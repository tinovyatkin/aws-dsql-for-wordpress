<?php
/** Real synthetic DSQL integration through the standalone engine, with no WordPress bootstrap. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
use WPDSQL\Engine\{Config,Driver,QueryException};
$root = dirname(__DIR__, 2);
$cluster = json_decode(file_get_contents($root.'/.local/cluster.json'), true, flags: JSON_THROW_ON_ERROR);
$settings = json_decode(file_get_contents($root.'/.local/settings.json'), true, flags: JSON_THROW_ON_ERROR);
if (($cluster['tags']['Purpose'] ?? '') !== 'synthetic-wordpress-compatibility'
    || $settings['endpoint'] !== $cluster['identifier'].'.dsql.'.$settings['region'].'.on.aws') {
    throw new RuntimeException('Synthetic test cluster configuration required');
}
$driver = new Driver(new Config(host:$settings['endpoint'], region:$settings['region'], profile:$settings['profile'], tablePrefix:'schema_adoption_'));
$checks=0;
function schema_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
$table='schema_adoption_items';$bad='schema_adoption_incomplete';
try{
    $driver->query("DROP TABLE IF EXISTS $table");$driver->query("DROP TABLE IF EXISTS $bad");
    schema_check($driver->query("DESCRIBE $table")->fetchAll()===[],'Missing DESCRIBE supports dbDelta planning');
    $driver->query("CREATE TABLE $table (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,title varchar(60) NOT NULL DEFAULT 'a,b' COMMENT 'literal CHECK (x)',body longtext NULL,score tinyint NOT NULL DEFAULT 1,stamp timestamp DEFAULT CURRENT_TIMESTAMP,KEY title_key(title(12))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='table description'");
    $columns=$driver->query("SHOW FULL COLUMNS FROM $table")->fetchAll();
    schema_check(array_column($columns,'Type')===['bigint unsigned','varchar(60)','longtext','tinyint','timestamp'],'MySQL types survive physical lowering');
    schema_check($columns[1]['Comment']==='literal CHECK (x)','Comments stay data');
    schema_check($driver->query("SHOW COLUMNS FROM $table LIKE 'bo%'")->fetchColumn()==='body','SHOW LIKE');
    schema_check($driver->query("SHOW FULL COLUMNS FROM $table WHERE Field='body' AND `Default` IS NULL")->fetchColumn()==='body','SHOW WHERE');
    $indexes=$driver->query("SHOW INDEX FROM $table WHERE Key_name='title_key'")->fetchAll();
    schema_check(count($indexes)===1&&$indexes[0]['Sub_part']==='12','Index prefix metadata');
    $ddl=$driver->query("SHOW CREATE TABLE $table")->fetchColumn(1);
    schema_check(str_contains($ddl,'longtext')&&str_contains($ddl,"COMMENT='table description'")&&str_contains($ddl,'CURRENT_TIMESTAMP'),'Canonical MySQL DDL');
    $round=(new WPDSQL\Schema\Ddl($ddl,'public'))->parse();
    schema_check(count($round['columns'])===5,'SHOW CREATE parses through the same AST');
    $rows=$driver->query("SELECT c.COLUMN_NAME AS field,c.DATA_TYPE FROM information_schema.COLUMNS c WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' ORDER BY ORDINAL_POSITION LIMIT 2,2")->fetchAll();
    schema_check($rows===[['field'=>'body','DATA_TYPE'=>'longtext'],['field'=>'score','DATA_TYPE'=>'tinyint']],'INFORMATION_SCHEMA aliases/order/limit');
    schema_check($driver->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_NAME='$table' AND INDEX_TYPE='FULLTEXT'")->fetchColumn()==='0','COUNT on empty metadata selection');
    schema_check($driver->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='postgres' AND TABLE_NAME IN ('$table','absent_table') AND ENGINE='MyISAM'")->fetchAll()===[],'WordPress update-check IN predicate');
    schema_check($driver->query("SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_NAME='$table'")->fetchColumn()==='table description','Shared table options');
    foreach(["SHOW TABLES WHERE nonexistent=1","SELECT c.BAD FROM information_schema.COLUMNS c WHERE TABLE_NAME='$table'","SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_NAME='$table' GROUP BY COLUMN_NAME","SELECT a.COLUMN_NAME FROM information_schema.COLUMNS a JOIN information_schema.TABLES b ON 1=1"] as $sql){try{$driver->query($sql);throw new LogicException('Unsupported metadata accepted');}catch(QueryException $e){$checks++;}}
    $driver->close();$driver=new Driver(new Config(host:$settings['endpoint'],region:$settings['region'],profile:$settings['profile'],tablePrefix:'schema_adoption_'));
    schema_check($driver->query("SHOW COLUMNS FROM $table LIKE 'body'")->fetchAll()[0]['Type']==='longtext','Logical metadata survives a fresh connection');
    try{$driver->query("CREATE TABLE $bad (n int NOT NULL DEFAULT 'invalid-number')");throw new LogicException('Invalid physical schema accepted');}catch(QueryException $e){$checks++;}
    try{$driver->query("SHOW COLUMNS FROM $bad");throw new LogicException('Incomplete schema was advertised');}catch(QueryException $e){schema_check(str_contains($e->nativeFailure()->getMessage(),'Incomplete installation'),'Pending publication fails closed');}
    try{$driver->query('SELECT 1');throw new LogicException('Incomplete installation allowed application SQL');}catch(QueryException $e){$checks++;}
    $driver->query("DROP TABLE IF EXISTS $bad");
    echo "PASS $checks shared-schema/AST introspection contracts\n";
}finally{$driver->query("DROP TABLE IF EXISTS $table");$driver->query("DROP TABLE IF EXISTS $bad");$driver->close();}
