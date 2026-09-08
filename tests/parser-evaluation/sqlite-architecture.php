<?php
/** In-memory architecture probe; never connects to AWS or WordPress. */
$source=$argv[1]??null;
if(!$source||!is_file($source.'/packages/mysql-on-sqlite/src/load.php')){
    fwrite(STDERR,"Usage: php tests/parser-evaluation/sqlite-architecture.php SQLITE_REPOSITORY_PATH\n");exit(2);
}
require $source.'/packages/mysql-on-sqlite/src/load.php';
$db=new WP_MySQL_On_SQLite('mysql-on-sqlite:dbname=architecture_review');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES,true);
$results=[];
$run=function(string $name,string $sql)use($db,&$results){
 try{$s=$db->query($sql);$results[$name]=['ok'=>true,'rows'=>$s->fetchAll(PDO::FETCH_ASSOC),'affected'=>$s->rowCount(),'backend_queries'=>count($db->get_last_sqlite_queries())];return $s;}
 catch(Throwable $e){$results[$name]=['ok'=>false,'error'=>$e->getMessage()];return null;}
};
$run('create',"CREATE TABLE sample (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(40) NOT NULL DEFAULT '',note longtext NULL, UNIQUE KEY name_key(name(12))) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$run('insert',"INSERT INTO sample(name,note) VALUES ('alpha','synthetic')");
$run('upsert',"INSERT INTO sample(name,note) VALUES ('alpha','changed') ON DUPLICATE KEY UPDATE note=VALUES(note)");
$run('show_columns',"SHOW FULL COLUMNS FROM sample LIKE 'name'");
$run('show_indexes',"SHOW INDEX FROM sample WHERE Key_name='name_key'");
$run('information_schema',"SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='architecture_review' AND TABLE_NAME='sample' ORDER BY ORDINAL_POSITION");
$run('show_create',"SHOW CREATE TABLE sample");
$s=$run('result',"SELECT id AS identifier,name FROM sample");if($s)$results['column_metadata']=$s->getColumnMeta(0);
$run('prefix_collision',"INSERT INTO sample(name) VALUES ('sharedprefix-A'),('sharedprefix-B')");
$run('prefix_rows',"SELECT name FROM sample WHERE name LIKE 'sharedprefix%' ORDER BY name");
$run('alter',"ALTER TABLE sample ADD COLUMN enabled tinyint NOT NULL DEFAULT 1 AFTER name");
$run('alter_metadata',"SHOW FULL COLUMNS FROM sample LIKE 'enabled'");
$run('savepoint_begin','START TRANSACTION');$run('savepoint','SAVEPOINT probe');$run('savepoint_rollback','ROLLBACK TO SAVEPOINT probe');$run('rollback','ROLLBACK');
try{$db->prepare('SELECT ?');$results['prepare']=['ok'=>true];}catch(Throwable $e){$results['prepare']=['ok'=>false,'sqlstate'=>$e->getCode(),'error'=>$e->getMessage()];}
echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
