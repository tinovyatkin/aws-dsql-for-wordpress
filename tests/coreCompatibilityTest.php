<?php
use PHPUnit\Framework\TestCase;
use WPDSQL\MySQL\Translation\{Shape,Compiler,Renderer};
use WPDSQL\Schema\{Introspection,Ddl};
use WPDSQLUpgrade\Schema;
require_once __DIR__.'/core-sql/FixturePDO.php';
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';

final class coreCompatibilityTest extends TestCase {
    private function translate(string $sql):array {
        $shape=new Shape($sql);$plan=(new Compiler($shape))->compile();
        return (new Renderer(new CoreSqlFixturePDO(),'wp_live',false))->statements($plan['body'],$shape)[0];
    }
    public function test_sql_version_reports_the_existing_mysql_compatibility_level():void {
        $r=$this->translate('SELECT VERSION()');
        self::assertSame([\WPDSQL\Engine\Driver::MYSQL_VERSION.'-Aurora-DSQL-compat'],$r['params']);
        self::assertStringNotContainsString('VERSION()',$r['sql']);
        self::assertTrue(version_compare('8.0',$r['params'][0],'<='));
        self::assertStringContainsString('Aurora DSQL',(new ReflectionClass(\WPDSQL\Engine\Driver::class))->newInstanceWithoutConstructor()->serverInfo());
    }
    public function test_calendar_expressions_bind_current_arguments_once():void {
        foreach(['WEEK','DAYOFYEAR','DAYOFWEEK','WEEKDAY'] as $function){
            $r=$this->translate("SELECT $function('2026-01-01')");
            self::assertSame(['2026-01-01'],$r['params']);self::assertSame(1,substr_count($r['sql'],'?'));
        }
        foreach(['WEEK(post_date,8)','WEEK(post_date,post_id)','WEEK()','WEEK(post_date,0,1)','DAYOFYEAR(post_date,1)'] as $expr){
            try{$this->translate("SELECT $expr FROM wp_posts");self::fail('Invalid calendar signature accepted');}catch(RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        }
    }
    public function test_binary_predicates_use_bytes_but_regex_uses_case_sensitive_text():void {
        $r=$this->translate("SELECT post_id FROM wp_postmeta WHERE CAST(meta_value AS BINARY) = 'Hotel '");
        self::assertSame(2,substr_count($r['sql'],'CONVERT_TO'));self::assertSame(['Hotel '],$r['params']);
        $r=$this->translate("SELECT post_id FROM wp_postmeta WHERE CAST(meta_key AS BINARY) REGEXP BINARY '^Hotel$'");
        self::assertStringContainsString(' ~ ',$r['sql']);self::assertStringNotContainsString('CONVERT_TO',$r['sql']);self::assertStringNotContainsString('<> 0',$r['sql']);
        $this->expectException(RuntimeException::class);$this->translate("SELECT CAST(meta_value AS BINARY(4)) FROM wp_postmeta");
    }
    public function test_core_self_join_delete_targets_only_selected_rows():void {
        $r=$this->translate('DELETE o1 FROM wp_options o1 JOIN wp_options o2 USING (option_name) WHERE o2.option_id > o1.option_id');
        self::assertStringContainsString('WHERE "option_id" IN (SELECT "o1"."option_id"',$r['sql']);
        self::assertStringContainsString('USING ( "option_name" )',$r['sql']);
        $this->expectException(RuntimeException::class);$this->translate('DELETE a FROM wp_posts a JOIN wp_postmeta b ON b.post_id=a.ID WHERE a.ID=1');
    }
    public function test_site_health_metadata_is_honest_and_counts_only_when_needed():void {
        $pdo=new CoreSqlFixturePDO();$i=new Introspection($pdo,new DSQL_Schema_Catalog($pdo),'wp_live','postgres');
        self::assertSame([],$i->query("SHOW VARIABLES LIKE 'max_connections'"));
        $i->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME='wp_posts'");
        self::assertSame([],array_values(array_filter($pdo->queries,fn($q)=>str_starts_with($q,'SELECT COUNT(*)'))));
        $r=$i->query("SELECT TABLE_NAME AS 'table',TABLE_ROWS AS 'rows',SUM(DATA_LENGTH+INDEX_LENGTH) AS 'bytes' FROM information_schema.TABLES WHERE TABLE_NAME='wp_posts' GROUP BY TABLE_NAME");
        self::assertSame([['table'=>'wp_posts','rows'=>'21','bytes'=>null]],$r);
        $status=$i->query("SHOW TABLE STATUS LIKE 'wp_posts'")[0];
        self::assertSame('21',$status['Rows']);self::assertNull($status['Data_length']);self::assertNull($status['Index_length']);
    }
    public function test_metadata_grouping_does_not_silently_accept_other_aggregate_semantics():void {
        $i=new Introspection(new CoreSqlFixturePDO(),new DSQL_Schema_Catalog(new CoreSqlFixturePDO()),'wp_live','postgres');
        foreach(["SELECT SUM(DATA_LENGTH) FROM information_schema.TABLES","SELECT TABLE_NAME FROM information_schema.TABLES GROUP BY ENGINE","SELECT TABLE_NAME FROM information_schema.TABLES GROUP BY TABLE_NAME WITH ROLLUP","SELECT TABLE_NAME FROM information_schema.TABLES WHERE SUM(DATA_LENGTH)>0","SELECT SUM(DISTINCT DATA_LENGTH) FROM information_schema.TABLES GROUP BY TABLE_NAME"] as $q){
            try{$i->query($q);self::fail('Unsupported aggregation accepted');}catch(RuntimeException $e){self::assertNotEmpty($e->getMessage());}
        }
    }
    public function test_maintenance_has_no_hidden_mutations_and_missing_tables_are_not_healthy():void {
        $pdo=new CoreSqlFixturePDO();$i=new Introspection($pdo,new DSQL_Schema_Catalog($pdo),'wp_live','postgres');
        self::assertSame('OK',$i->query('CHECK TABLE wp_posts')[0]['Msg_text']);
        foreach(['REPAIR','ANALYZE','OPTIMIZE'] as $op){$r=$i->query("$op TABLE wp_posts")[0];self::assertSame('note',$r['Msg_type']);self::assertStringContainsString('No maintenance was performed',$r['Msg_text']);}
        self::assertSame('error',$i->query('CHECK TABLE wp_missing')[0]['Msg_type']);
        foreach($pdo->queries as $q)self::assertStringStartsWith('SELECT',$q);
        $this->expectException(RuntimeException::class);$i->query('CHECK TABLE other.wp_posts');
    }
    public function test_utf8_widening_is_metadata_only_and_cannot_change_collation_semantics():void {
        $options=['table_prefix'=>'wp_','allow_destructive'=>false,'omit_fulltext_indexes'=>[]];
        $before=Schema::apply((new Ddl('CREATE TABLE wp_t (id bigint PRIMARY KEY,title varchar(50)) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'))->parse(),null,$options);
        $before['table_options']['charset']='utf8';$before['table_options']['collation']='utf8_unicode_ci';
        $ddl=(new Ddl('ALTER TABLE wp_t CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'))->parse();
        $after=Schema::apply($ddl,$before,$options);
        self::assertSame('utf8mb4',$after['table_options']['charset']);self::assertSame('utf8mb4_unicode_ci',$after['columns'][1]['Collation']);
        self::assertSame(['id'=>'id','title'=>'title'],$after['mapping']);
        $before['table_options']['collation']='utf8_general_ci';
        $this->expectException(RuntimeException::class);Schema::apply($ddl,$before,$options);
    }
}
