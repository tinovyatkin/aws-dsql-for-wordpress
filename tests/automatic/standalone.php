<?php
// Live smoke belongs outside PHPUnit; invoked explicitly with the native module.
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$s=json_decode(file_get_contents($root.'/.local/upgrade-lab/runtime-target.json'),true,512,JSON_THROW_ON_ERROR);
if(($s['classification']??'')!=='synthetic'||$s['user']!=='wp_runtime')throw new RuntimeException('Synthetic runtime role required');
$aws=new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$s['region'],'profile'=>$s['profile']]);$cluster=$aws->getCluster(['identifier'=>explode('.',$s['endpoint'])[0]]);if(($cluster['tags']['Purpose']??'')!=='synthetic-wordpress-migration')throw new RuntimeException('Live synthetic tag required');
$dir=$root.'/.local/automatic-schema-smoke';if(!is_dir($dir))mkdir($dir,0700);
$c=new WPDSQL\Engine\Config(host:$s['endpoint'],user:$s['user'],region:$s['region'],profile:$s['profile'],schema:'wp_live',valueCodec:true,tablePrefix:'wp_',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$dir);
$d=new WPDSQL\Engine\NativeDriver($c);$t='wp_auto_test_'.bin2hex(random_bytes(4));$checks=0;
function auto_check(bool $ok,string $name):void{global $checks;if(!$ok)throw new RuntimeException($name);$checks++;echo "PASS $name\n";}
try {
 $d->query("CREATE TABLE $t(id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,label varchar(30) NOT NULL DEFAULT '',body text NULL,KEY label_key(label)) DEFAULT CHARSET=utf8mb4");
 $d->query("INSERT INTO $t(label,body) VALUES ('first','data'),('second','more')");
 $d->query("ALTER TABLE $t ADD flag tinyint NOT NULL DEFAULT 1");
 auto_check(array_column($d->query("SELECT flag FROM $t ORDER BY id")->fetchAll(),'flag')===['1','1'],'NOT NULL addition backfills existing rows');
 auto_check($d->query("SHOW FULL COLUMNS FROM $t LIKE 'flag'")->fetchAll()[0]['Null']==='NO','Logical NOT NULL matches enforced check');
 $d->query("INSERT INTO $t(label) VALUES ('third')");auto_check($d->query("SELECT flag FROM $t WHERE label='third'")->fetchColumn()==='1','Default applies to new rows');
 try{$d->query("INSERT INTO $t(label,flag) VALUES ('invalid',NULL)");throw new LogicException('NULL accepted');}catch(WPDSQL\Engine\QueryException $e){auto_check($e->sqlState()==='23514','Database enforces not-null check');}
 $d->query("ALTER TABLE $t ADD optional varchar(20) DEFAULT 'seed'");auto_check($d->query("SELECT optional FROM $t WHERE id=1")->fetchColumn()==='seed','Nullable default addition backfills');
 $d->query("ALTER TABLE $t MODIFY body longtext NULL");auto_check($d->query("SHOW FULL COLUMNS FROM $t LIKE 'body'")->fetchAll()[0]['Type']==='longtext','Safe TEXT widening is metadata-only');
 $d->query("ALTER TABLE $t ADD INDEX flag_key(flag)");$d->query("ALTER TABLE $t DROP INDEX label_key");auto_check(count($d->query("SHOW INDEX FROM $t WHERE Key_name='flag_key'")->fetchAll())===1,'Automatic index addition and removal');
 $d->query("ALTER TABLE $t CHANGE label title varchar(30) NOT NULL DEFAULT ''");auto_check($d->query("SELECT title FROM $t WHERE id=1")->fetchColumn()==='first','Column rename preserves values');
 $d->query("ALTER TABLE $t ALTER COLUMN title SET DEFAULT 'new'");$d->query("INSERT INTO $t(body) VALUES ('last')");auto_check($d->query("SELECT COUNT(*) FROM $t WHERE title='new'")->fetchColumn()==='1','Default update is effective');
 auto_check(!is_file($dir.'/pending.json'),'No pending journal after successful operations');
 echo "PASS $checks automatic schema contracts\n";
}finally {
 $d->close();$p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_upgrader',region:$s['region'],profile:$s['profile'],schema:'wp_live'));
 // Only this run's synthetic table. Keep a failed journal for diagnosis.
 if(!is_file($dir.'/pending.json')){$p->exec('DROP TABLE IF EXISTS wp_live."'.$t.'"');$q=$p->prepare('DELETE FROM wp_live.__wp_dsql_schema WHERE table_name=?');$q->execute([$t]);}
}
