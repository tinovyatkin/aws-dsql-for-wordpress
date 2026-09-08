<?php
/** Compare synthetic query results and write effects on local MySQL and test DSQL. */
$root=dirname(__DIR__,2);$backend=$argv[1]??'';
if(!in_array($backend,['mysql','dsql'],true))throw new RuntimeException('mysql or dsql required');
require $root.($backend==='mysql'?'/.local/mysql-wordpress/wp-load.php':'/.local/wordpress/wp-load.php');
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local synthetic environment required');
if($backend==='mysql'&&!str_starts_with(DB_HOST,'127.0.0.1:'))throw new RuntimeException('Loopback MySQL required');
$table=$wpdb->prefix.'translation_probe';$report=[];
function query_check(string $sql):int|bool {global $wpdb;$r=$wpdb->query($sql);if($r===false)throw new RuntimeException($wpdb->last_error);return $r;}
function rows_check(string $sql):array {global $wpdb;$r=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);return $r;}
try {
 query_check("DROP TABLE IF EXISTS $table");
 query_check("CREATE TABLE $table (id bigint NOT NULL AUTO_INCREMENT, name varchar(80) NOT NULL, score int NOT NULL DEFAULT 0, note longtext NULL, stamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY(id), UNIQUE KEY name_key(name)) DEFAULT CHARSET=utf8mb4");
 query_check("INSERT INTO $table (name,score,note) VALUES ('alpha',4,' -12.5tail'),('beta',8,NULL),('gamma',3,'Русский 🌍')");
 $report['rows']=rows_check("SELECT id,name,score,note,stamp FROM $table ORDER BY id");
 $report['quoted_in']=rows_check("SELECT id FROM $table WHERE id IN ('1','3') ORDER BY id");
 $report['nested']=rows_check("SELECT name, IF(score>5,'high','low') AS band, CONCAT(name, ':', LOWER(COALESCE(note,'none'))) AS label FROM $table ORDER BY id LIMIT 1,2");
 $report['numeric_text']=rows_check("SELECT name,note+0 AS n FROM $table ORDER BY id");
 $report['null_concat']=rows_check("SELECT CONCAT(NULL,'x') AS n");
 $report['date_format']=rows_check("SELECT DATE_FORMAT('2024-03-12 04:05:06','%Y-%m-%d %H:%i:%s') AS day");
 $report['update_limit']=query_check("UPDATE $table SET score=score+1 ORDER BY id DESC LIMIT 1");
 $report['after_update']=rows_check("SELECT id,score FROM $table ORDER BY id");
 $report['upsert']=query_check("INSERT INTO $table (name,score) VALUES ('alpha',10) ON DUPLICATE KEY UPDATE score=VALUES(score)+1");
 $report['after_upsert']=rows_check("SELECT id,name,score FROM $table ORDER BY id");
 $report['replace']=query_check("REPLACE INTO $table (id,name,score) VALUES (1,'alpha',20)");
 $report['after_replace']=rows_check("SELECT id,name,score,note FROM $table ORDER BY id");
 $report['ignore']=query_check("INSERT IGNORE INTO $table (name,score) VALUES ('alpha',999)");
 $report['delete_limit']=query_check("DELETE FROM $table ORDER BY score DESC LIMIT 1");
 $report['after_delete']=rows_check("SELECT id,name,score FROM $table ORDER BY id");
 $report['delete_join']=query_check("DELETE a,b FROM $table a, $table b WHERE a.id=2 AND b.id=3");
 $report['empty']=rows_check("SELECT id FROM $table");
 query_check('BEGIN');query_check("INSERT INTO $table (name,score) VALUES ('rolled-back',1)");query_check('ROLLBACK');
 $report['rollback']=rows_check("SELECT name FROM $table");
 if($backend==='dsql')$report['cache_stats']=$wpdb->translation_cache_stats();
 echo "PASS $backend result and write-effect collection\n";
} catch(Throwable $e){$report['failure']=$e->getMessage();echo 'FAIL '.$backend.': '.$e->getMessage()."\n";}
finally {query_check("DROP TABLE IF EXISTS $table");}
$dir=$root.'/.local/antlr-research';if(!is_dir($dir))mkdir($dir,0700,true);
file_put_contents($dir.'/differential-'.$backend.'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
exit(isset($report['failure'])?1:0);
