<?php
require __DIR__.'/bootstrap.php';
$n=native_client();$p=php_client();$t=fixture()['prefix'].'ddl';$checks=0;
function ddl_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
try {
 $n->query("DROP TABLE IF EXISTS `$t`");
 $ddl="CREATE TABLE IF NOT EXISTS `$t` (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(60) NOT NULL,score int NOT NULL DEFAULT 0,payload longtext NULL,stamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',UNIQUE KEY name_key(name),KEY payload_prefix(payload(50))) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Native fixture'";
 ddl_check($n->query($ddl)['command']===true,'Create command');ddl_check($n->query($ddl)['command']===true,'Existing CREATE IF NOT EXISTS');
 $insert=$n->query("INSERT INTO `$t` (name,score) VALUES ('first',5),('second',10)");ddl_check($insert['affected_rows']===2&&(int)$insert['insert_id']>0,'Insert with defaults/identity');
 $a=$n->query("SHOW FULL COLUMNS FROM `$t`")['rows'];$b=$p->query("SHOW FULL COLUMNS FROM `$t`")->fetchAll();ddl_check($a===$b,'Logical catalog column parity');
 ddl_check($n->query("SHOW CREATE TABLE `$t`")['rows']===$p->query("SHOW CREATE TABLE `$t`")->fetchAll(),'Logical CREATE parity');
 ddl_check($n->query("UPDATE `$t` SET score=score+1 ORDER BY id DESC LIMIT 1")['affected_rows']===1,'Limited update');
 ddl_check($n->query("INSERT INTO `$t` (name,score) VALUES ('first',20) ON DUPLICATE KEY UPDATE score=VALUES(score)+1")['affected_rows']===1,'Upsert update');
 ddl_check($n->query("INSERT IGNORE INTO `$t` (name,score) VALUES ('first',99)")['affected_rows']===0,'Ignore duplicate');
 $rows=$n->query("SELECT id,name,score,stamp FROM `$t` ORDER BY id")['rows'];ddl_check($rows===$p->query("SELECT id,name,score,stamp FROM `$t` ORDER BY id")->fetchAll(),'Read/write parity');
 ddl_check($rows[0]['score']==='21'&&$rows[1]['score']==='11','Mutation values');
 try {$n->query("REPLACE INTO `$t` (id,name,score) VALUES ({$rows[0]['id']},'first','invalid')");throw new RuntimeException('Invalid replace succeeded');}catch(Throwable $e){if($e instanceof RuntimeException)throw $e;}
 ddl_check($n->query("SELECT score FROM `$t` WHERE name='first'")['rows'][0]['score']==='21','REPLACE failure preserves deleted row');
 ddl_check($n->query("REPLACE INTO `$t` (id,name,score) VALUES ({$rows[0]['id']},'first',30)")['affected_rows']===2,'Atomic replace count');
 $n->query("SELECT SQL_CALC_FOUND_ROWS id FROM `$t` ORDER BY id LIMIT 1");ddl_check(reset($n->query('SELECT FOUND_ROWS()')['rows'][0])==='2','FOUND_ROWS state');
 $n->query("SET SESSION sql_mode='PIPES_AS_CONCAT'");ddl_check(reset($n->query("SELECT 'a'||'b'")['rows'][0])==='ab','Native SQL mode concat');
 $n->query("SET SESSION sql_mode=''");ddl_check(reset($n->query("SELECT 'a'||'b'")['rows'][0])==='0','Native SQL mode reset');
 ddl_check($n->query("DELETE FROM `$t` ORDER BY score DESC LIMIT 1")['affected_rows']===1,'Limited delete');
 echo "PASS $checks native installation/write contracts\n";
}finally{$n->query("DROP TABLE IF EXISTS `$t`");$n->close();$p->close();}
