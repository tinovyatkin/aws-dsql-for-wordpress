<?php
use PHPUnit\Framework\TestCase;
use WPDSQL\Schema\AutomaticPlan;
use WPDSQL\Schema\AutomaticSchema;
use WPDSQLUpgrade\Schema;
final class automaticSchemaTest extends TestCase {
    private function table():array {
        return Schema::apply((new Schema("CREATE TABLE wp_auto (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY, body text NULL, label varchar(30) NOT NULL DEFAULT '', KEY label_key(label)) DEFAULT CHARSET=utf8mb4",'public'))->parse(),null,['table_prefix'=>'wp_','allow_destructive'=>false,'omit_fulltext_indexes'=>[]]);
    }
    private function plan(string $sql):array{return AutomaticPlan::build($sql,$this->table(),'wp_','public',true);}
    public function testCommonAddDefaultAndMetadataWidening():void {
        $p=$this->plan("ALTER TABLE wp_auto ADD COLUMN enabled tinyint NOT NULL DEFAULT 0");
        self::assertSame('add_column',$p['steps'][0]['kind']);
        self::assertSame('NO',AutomaticPlan::column($p['after'],'enabled')['Null']);
        self::assertStringStartsWith('__wpd_nn_',AutomaticPlan::column($p['after'],'enabled')['dsql_not_null_constraint']);
        $p=$this->plan('ALTER TABLE wp_auto MODIFY body longtext NULL');self::assertSame([],$p['steps']);self::assertSame('longtext',AutomaticPlan::column($p['after'],'body')['Type']);
        self::assertSame('default',$this->plan("ALTER TABLE wp_auto ALTER COLUMN label SET DEFAULT 'next'")['steps'][0]['kind']);
        self::assertCount(2,$this->plan('ALTER TABLE wp_auto ADD a int NULL, ADD b int NULL')['steps']);
    }
    public function testIndexAndRenameFastPaths():void {
        self::assertSame('add_index',$this->plan('ALTER TABLE wp_auto ADD INDEX body_key(body(20))')['steps'][0]['kind']);
        self::assertSame('drop_index',$this->plan('ALTER TABLE wp_auto DROP INDEX label_key')['steps'][0]['kind']);
        self::assertSame('rename_column',$this->plan("ALTER TABLE wp_auto CHANGE label title varchar(30) NOT NULL DEFAULT ''")['steps'][0]['kind']);
        self::assertSame('rename',$this->plan('RENAME TABLE wp_auto TO wp_auto_new')['steps'][0]['kind']);
    }
    public function testUnsafePlansRejectBeforeMutation():void {
        foreach(['DROP TABLE wp_auto','ALTER TABLE wp_auto DROP COLUMN body','ALTER TABLE wp_auto MODIFY label varchar(60)','ALTER TABLE wp_auto MODIFY body tinytext','ALTER TABLE wp_auto ADD n int FIRST','ALTER TABLE wp_auto DROP PRIMARY KEY','ALTER TABLE wp_auto ADD PRIMARY KEY(body)'] as $sql) {
            try{$this->plan($sql);self::fail('Expected unsupported plan: '.$sql);}catch(RuntimeException $e){self::assertNotSame('',$e->getMessage());}
        }
    }
    public function testNewTablesAndRepeatedCreates():void {
        $sql='CREATE TABLE wp_new (id bigint NOT NULL PRIMARY KEY,payload longtext)';
        $p=AutomaticPlan::build($sql,null,'wp_','public',true);self::assertSame('create',$p['steps'][0]['kind']);self::assertSame('public',$p['after']['target_schema']);
        self::assertSame([],$this->plan('CREATE TABLE IF NOT EXISTS wp_auto (ignored int)')['steps']);
    }
    public function testDdlRoutingHandlesCommentsWithoutParsingDataQueries():void {
        self::assertTrue(AutomaticSchema::isDdl("/* upgrade */\n-- comment\nALTER TABLE wp_auto ADD n int"));
        self::assertTrue(AutomaticSchema::isDdl("# x\nCREATE TABLE wp_x(id int)"));
        self::assertFalse(AutomaticSchema::isDdl("SELECT 'ALTER TABLE x'"));
        self::assertFalse(AutomaticSchema::isDdl('/* unterminated'));
    }
}
