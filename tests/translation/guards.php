<?php
require dirname(__DIR__,2).'/.local/wordpress/wp-load.php';
if(wp_get_environment_type()!=='local'||$wpdb->prefix!=='dsqlwp_')throw new RuntimeException('Synthetic DSQL fixture required');
$table=$wpdb->prefix.'translation_guards';$checks=0;
function guard_check(bool $ok,string $name):void {global $checks,$wpdb;if(!$ok)throw new RuntimeException($name.': '.$wpdb->last_error);$checks++;}
try {
 $wpdb->query("DROP TABLE IF EXISTS $table");
 $ddl="CREATE TABLE IF NOT EXISTS $table (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(60) NOT NULL,score int NOT NULL,payload longtext NULL,UNIQUE KEY name_key(name)) DEFAULT CHARSET=utf8mb4";
 guard_check($wpdb->query($ddl)!==false,'Create fixture');
 guard_check($wpdb->query($ddl)!==false,'Existing CREATE IF NOT EXISTS does not recreate indexes');
 guard_check($wpdb->query("INSERT INTO $table (name,score) VALUES ('first',5),('second',10)")===2,'Seed fixture');
 guard_check($wpdb->query("UPDATE $table SET score=score+1,id=score WHERE id=1")===false,'Sequential dependent assignment rejected');
 guard_check($wpdb->get_var("SELECT score FROM $table WHERE id=1")==='5','Rejected UPDATE left the row unchanged');
 guard_check($wpdb->query("INSERT INTO $table (id,name,score) VALUES (1,'second',77) ON DUPLICATE KEY UPDATE score=VALUES(score)")===false,'Ambiguous conflict target rejected');
 guard_check($wpdb->get_var("SELECT score FROM $table WHERE id=1")==='5','Rejected upsert left the row unchanged');
 guard_check($wpdb->query("REPLACE INTO $table (id,name,score) VALUES (1,'first','not-an-integer')")===false,'Invalid REPLACE insert fails');
 guard_check($wpdb->get_var("SELECT score FROM $table WHERE id=1")==='5','REPLACE rollback restored its deleted row');
 guard_check($wpdb->get_var('SELECT @@SESSION.autocommit')==='1','REPLACE released its own failed transaction');
 guard_check($wpdb->query("INSERT INTO $table (id,name,score) VALUES (0,'zero-id',1)")===1&&$wpdb->insert_id===3,'Zero identity uses a generated ID');
 guard_check($wpdb->query("INSERT INTO $table VALUES (4,'implicit',3,NULL)")===1,'Implicit INSERT column order comes from current schema');
 $wpdb->query("DELETE FROM $table WHERE id IN (3,4)");
 guard_check($wpdb->query("SET SESSION sql_mode='PIPES_AS_CONCAT'")!==false,'Set concatenation mode');
 guard_check($wpdb->get_var("SELECT 'a'||'b'")==='ab','Concatenation mode has its own translation');
 $wpdb->query("SET SESSION sql_mode=''");
 guard_check($wpdb->get_var("SELECT 'a'||'b'")==='0','Reset mode does not reuse concatenation plan');
 $wpdb->query("SET SESSION sql_mode='IGNORE_SPACE'");
 guard_check($wpdb->get_var("SELECT COUNT (*) FROM $table")==='2','IGNORE_SPACE handles function whitespace');
 $wpdb->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
 $value="a\\b'c";
 guard_check($wpdb->get_var($wpdb->prepare('SELECT %s',$value))===$value,'prepare and binding honor NO_BACKSLASH_ESCAPES');
 $wpdb->query("SET SESSION sql_mode=''");
 guard_check($wpdb->get_var($wpdb->prepare('SELECT %s',$value))===$value,'Default escaping is restored');
 guard_check($wpdb->query("SET SESSION sql_mode='made_up'")===false,'Unsupported mode rejected');
 guard_check(($wpdb->get_row('SELECT @@SESSION.sql_mode',ARRAY_A)['sql_mode']??null)==='','Rejected mode does not alter session behavior');
 echo "PASS $checks write, transaction, identity, and SQL-mode checks\n";
} finally {$wpdb->query("SET SESSION sql_mode=''");$wpdb->query("DROP TABLE IF EXISTS $table");}
