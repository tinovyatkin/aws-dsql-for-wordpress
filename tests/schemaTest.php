<?php
use PHPUnit\Framework\TestCase;
use WPDSQLUpgrade\Schema;
use WPDSQL\Schema\{Ddl,Model,Ast,Expression};
require_once dirname(__DIR__).'/upgrade/Engine.php';
final class schemaTest extends TestCase {
    private function model(string $sql,string $mode=''): array {return Schema::apply((new Ddl($sql,'wp_live',$mode))->parse(),null,['table_prefix'=>'wp_','allow_destructive'=>true,'omit_fulltext_indexes'=>[]]);}
    public function test_logical_schema_round_trip_preserves_comments_types_defaults_and_indexes(): void {
        $a=$this->model("CREATE TABLE wp_t (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, title varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'a,b' COMMENT 'CHECK (literal)', body longtext NULL, score tinyint DEFAULT -2, stamp timestamp DEFAULT CURRENT_TIMESTAMP, n int NULL DEFAULT NULL, KEY title_key(title(12)) COMMENT 'index note') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='table comment'");
        $b=$this->model(Model::mysql($a));
        self::assertSame($a['columns'],$b['columns']);self::assertSame($a['indexes'],$b['indexes']);self::assertSame($a['table_options'],$b['table_options']);
        self::assertSame('longtext',$b['columns'][2]['Type']);self::assertFalse($b['columns'][2]['HasDefault']);self::assertTrue($b['columns'][5]['HasDefault']);self::assertTrue($b['columns'][4]['DefaultExpression']);
        $pdo=new class extends PDO{public function __construct(){} public function quote(string $s,int $type=PDO::PARAM_STR):string|false{return "'".str_replace("'","''",$s)."'";}};
        self::assertNotEmpty(WPDSQLMigration\Plan::ddl($a,$pdo));
    }
    public function test_sql_mode_applies_to_schema_literals(): void {
        $a=$this->model("CREATE TABLE wp_t (c text DEFAULT 'a\\b')",'NO_BACKSLASH_ESCAPES');
        self::assertSame('a\\b',$a['columns'][0]['Default']);
        self::assertSame($a['columns'],$this->model(Model::mysql($a))['columns']);
    }
    public function test_legacy_metadata_normalizes_without_losing_current_timestamp_expression(): void {
        $a=$this->model('CREATE TABLE wp_t (stamp timestamp DEFAULT CURRENT_TIMESTAMP, n int NULL DEFAULT NULL)');
        unset($a['schema_version'],$a['table_options']);foreach($a['columns'] as &$c){unset($c['HasDefault'],$c['DefaultExpression']);}unset($c);
        $a=Model::normalize($a);self::assertSame(2,$a['schema_version']);self::assertTrue($a['columns'][0]['DefaultExpression']);self::assertTrue($a['columns'][1]['HasDefault']);
    }
    public function test_legacy_timestamp_precision_is_readable_but_unimplemented_defaults_are_not_executed(): void {
        $table=['name'=>'wp_t','mysql_ddl'=>'CREATE TABLE wp_t (stamp timestamp(6) DEFAULT CURRENT_TIMESTAMP(6))','columns'=>[['Field'=>'stamp','Type'=>'timestamp(6)','Null'=>'YES','Default'=>'CURRENT_TIMESTAMP(6)','Extra'=>'DEFAULT_GENERATED','Collation'=>null]],'indexes'=>[]];
        $table=Model::normalize($table);self::assertTrue($table['columns'][0]['DefaultExpression']);self::assertStringContainsString('CURRENT_TIMESTAMP(6)',Model::mysql($table));
        $this->expectException(RuntimeException::class);WPDSQLMigration\Plan::type(['Type'=>'varchar(40)','Default'=>'uuid()','Extra'=>'DEFAULT_GENERATED']);
    }
    public function test_duplicate_columns_and_separate_primary_keys_are_not_combined(): void {
        foreach(['CREATE TABLE wp_t (id int,ID int)','CREATE TABLE wp_t (a int PRIMARY KEY,b int PRIMARY KEY)','CREATE TABLE wp_t (a int,b int,KEY same(a),KEY SAME(b))'] as $sql){
            try{$this->model($sql);self::fail('Invalid duplicate schema accepted');}catch(RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        }
    }
    public function test_unknown_future_model_is_rejected(): void {
        $this->expectException(RuntimeException::class);Model::normalize(['schema_version'=>99]);
    }
    public function test_alter_default_and_positions_are_structural(): void {
        $a=$this->model('CREATE TABLE wp_t (a int DEFAULT 1,b varchar(10))');
        $ddl=(new Ddl("ALTER TABLE wp_t ADD c text AFTER a, MODIFY b varchar(30) FIRST, ALTER COLUMN a DROP DEFAULT"))->parse();
        $b=Schema::apply($ddl,$a,['table_prefix'=>'wp_','allow_destructive'=>false]);
        self::assertSame(['b','a','c'],array_column($b['columns'],'Field'));self::assertFalse($b['columns'][1]['HasDefault']);
    }
    public function test_unsupported_ddl_cannot_hide_in_optional_ast_branches(): void {
        foreach(['CREATE TEMPORARY TABLE wp_t (id int)','CREATE TABLE wp_t (id int) AS SELECT 1','CREATE TABLE wp_t (id int) PARTITION BY HASH(id) PARTITIONS 2','ALTER TABLE wp_t ADD id int, ALGORITHM=INPLACE','ALTER TABLE wp_t ADD id int GENERATED ALWAYS AS (1)','CREATE TABLE wp_t (id int, CONSTRAINT fk FOREIGN KEY(id) REFERENCES wp_x(id))','CREATE TABLE wp_t (id int, KEY k(id DESC))','DROP TABLE wp_t CASCADE','DROP TABLE wp_t,wp_x','CREATE INDEX idx ON wp_t ((lower(id)))','ALTER TABLE wp_t CONVERT TO CHARACTER SET latin1'] as $sql){
            try{(new Ddl($sql))->parse();self::fail('Accepted unsupported DDL: '.$sql);}catch(RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        }
    }
    public function test_information_schema_rows_retain_mysql_lengths_and_numeric_types(): void {
        $a=$this->model('CREATE TABLE wp_t (v varchar(40),b longtext,n decimal(10,2))');$rows=Model::informationColumns($a,'postgres');
        self::assertSame('40',$rows[0]['CHARACTER_MAXIMUM_LENGTH']);self::assertSame('160',$rows[0]['CHARACTER_OCTET_LENGTH']);self::assertSame('longtext',$rows[1]['DATA_TYPE']);self::assertSame('10',$rows[2]['NUMERIC_PRECISION']);self::assertSame('2',$rows[2]['NUMERIC_SCALE']);
    }
    public function test_index_key_precedence_and_result_metadata_share_the_model(): void {
        $table=$this->model('CREATE TABLE wp_t (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,a varchar(40),b int,c int,UNIQUE KEY u(a,b),KEY k(b,c))');
        self::assertSame(['PRI','UNI','MUL',''],array_column($table['columns'],'Key'));
        $meta=Model::columnDescriptor($table,$table['columns'][1],['name'=>'alias_a','table'=>'wp_t','native_type'=>'varchar'],'postgres');
        self::assertSame('VAR_STRING',$meta['native_type']);self::assertSame(253,$meta['mysqli:type']);self::assertSame('a',$meta['mysqli:orgname']);self::assertSame(160,$meta['len']);self::assertSame(4,$meta['mysqli:flags']);
    }
    public function test_catalog_truth_uses_sql_numeric_conversion(): void {
        self::assertTrue(Expression::truth('1'));self::assertFalse(Expression::truth('0'));self::assertFalse(Expression::truth('text'));self::assertNull(Expression::truth(null));
        self::assertFalse(Expression::evaluate(['and',['literal','text'],['literal',null]],[]));
    }
    public function test_catalog_predicate_uses_sql_null_and_like_behavior(): void {
        $q=WPDSQL\MySQL\SqlParser::parse("SHOW FULL COLUMNS FROM wp_t WHERE Field LIKE 'a%' AND `Default` IS NULL");
        $where=Ast::nodes($q->tree,'where_clause')[0];$expr=Expression::compile(Ast::child($where,'expr'),['Field','Default'],'postgres');
        self::assertTrue(Expression::evaluate($expr,['Field'=>'alpha','Default'=>null]));self::assertFalse(Expression::evaluate($expr,['Field'=>'beta','Default'=>null]));self::assertFalse(Expression::evaluate($expr,['Field'=>'alpha','Default'=>'x']));
    }
}
