<?php
ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';$s=json_decode(file_get_contents($root.'/.local/upgrade-lab/runtime-target.json'),true);
$aws=new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$s['region'],'profile'=>$s['profile']]);if(($s['classification']??'')!=='synthetic'||($aws->getCluster(['identifier'=>explode('.',$s['endpoint'])[0]])['tags']['Purpose']??'')!=='synthetic-wordpress-migration')throw new RuntimeException('Synthetic fixture required');
$dir=$root.'/.local/automatic-rejections';if(!is_dir($dir))mkdir($dir,0700);
$config=new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_runtime',region:$s['region'],profile:$s['profile'],schema:'wp_live',valueCodec:true,tablePrefix:'wp_',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$dir);
$p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_upgrader',region:$s['region'],profile:$s['profile'],schema:'wp_live'));
$t='wp_auto_reject_'.bin2hex(random_bytes(4));$d=new WPDSQL\Engine\NativeDriver($config);
function expect_rejection(callable $callback,string $message):void{try{$callback();throw new LogicException('Expected rejection');}catch(WPDSQL\Engine\QueryException $e){if(!str_contains($e->nativeFailure()->getMessage(),$message))throw $e;}}
try {
 $d->query("CREATE TABLE $t(id bigint PRIMARY KEY, label varchar(30) NOT NULL DEFAULT '')");$d->query("INSERT INTO $t VALUES(1,'duplicate'),(2,'duplicate')");
 expect_rejection(fn()=>$d->query("ALTER TABLE $t ADD extra int, ADD UNIQUE KEY unique_label(label)"),'Duplicate values');
 if($d->query("SHOW COLUMNS FROM $t LIKE 'extra'")->fetchAll())throw new RuntimeException('Preflight changed schema');
 expect_rejection(fn()=>$d->query("UPDATE $t SET label='version-advanced' WHERE id=1"),'earlier schema');
 if($d->query("SELECT label FROM $t WHERE id=1")->fetchColumn()!=='duplicate'||is_file($dir.'/pending.json'))throw new RuntimeException('Failed migration changed data or left a journal');
 echo "PASS duplicate-index preflight and sticky version-write guard\n";
 $d->close();$d=new WPDSQL\Engine\NativeDriver($config);
 $d->query('BEGIN');expect_rejection(fn()=>$d->query("ALTER TABLE $t ADD tx_field int"),'caller-owned transaction');
 expect_rejection(fn()=>$d->query('COMMIT'),'earlier schema');$d->query('ROLLBACK');
 if($d->inTransaction()||$d->query("SHOW COLUMNS FROM $t LIKE 'tx_field'")->fetchAll())throw new RuntimeException('Transaction ownership changed');
 echo "PASS caller-owned transaction rejects schema mutation and requires rollback\n";
 $d->close();$d=new WPDSQL\Engine\NativeDriver($config);
 $d->query("ALTER TABLE $t ADD new_field int DEFAULT 5");$d->query("ALTER TABLE $t ADD new_field int DEFAULT 5");
 $d->query("ALTER TABLE $t ALTER COLUMN new_field DROP DEFAULT");$d->query("INSERT INTO $t(id,label) VALUES(3,'third')");
 if($d->query("SELECT new_field FROM $t WHERE id=3")->fetchColumn()!==null)throw new RuntimeException('DROP DEFAULT was not applied');
 echo "PASS repeated additive DDL and DROP DEFAULT\n";
}finally{$d->close();if(!is_file($dir.'/pending.json')){$p->exec("DROP TABLE IF EXISTS wp_live.$t");$q=$p->prepare('DELETE FROM wp_live.__wp_dsql_schema WHERE table_name=?');$q->execute([$t]);}}
