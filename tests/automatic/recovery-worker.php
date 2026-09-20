<?php
ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$s=json_decode(file_get_contents($root.'/.local/upgrade-lab/runtime-target.json'),true,512,JSON_THROW_ON_ERROR);
if(($s['classification']??'')!=='synthetic')throw new RuntimeException('Synthetic fixture required');
$aws=new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$s['region'],'profile'=>$s['profile']]);if(($aws->getCluster(['identifier'=>explode('.',$s['endpoint'])[0]])['tags']['Purpose']??'')!=='synthetic-wordpress-migration')throw new RuntimeException('Live synthetic tag required');
$action=$argv[1];$dir=$root.'/.local/automatic-recovery';if(!is_dir($dir))mkdir($dir,0700);$table='wp_auto_recovery_probe';
$config=new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_runtime',region:$s['region'],profile:$s['profile'],schema:'wp_live',valueCodec:true,tablePrefix:'wp_',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$dir);
if($action==='setup') {
 $d=new WPDSQL\Engine\NativeDriver($config);$d->query("CREATE TABLE $table(id bigint PRIMARY KEY,body text NOT NULL)");
 $rows=[];for($i=1;$i<=105;$i++)$rows[]="($i,'source-$i')";$d->query("INSERT INTO $table VALUES ".implode(',',$rows));echo "ready\n";
}elseif($action==='fault') {
 $point=$argv[2];$controller=new WPDSQL\Schema\AutomaticSchema($config,static fn()=>null,static function($p,$op)use($point){if($p===$point)exit(86);});
 $controller->execute("ALTER TABLE $table ADD added varchar(30) NOT NULL DEFAULT 'fill'",'');throw new RuntimeException('Fault did not fire');
}elseif($action==='known-failure') {
 $controller=new WPDSQL\Schema\AutomaticSchema($config,static fn()=>null,static function($p,$op){if($p==='before-step'&&$op['position']===1)throw new WPDSQL\Engine\NativeDatabaseException('Synthetic unsupported statement','0A000');});
 try{$controller->execute("ALTER TABLE $table ADD added varchar(30) NOT NULL DEFAULT 'fill', ADD INDEX body_key(body(20))",'');throw new LogicException('Failure injection not observed');}
 catch(WPDSQL\Engine\NativeDatabaseException $e){if($e->getCode()!=='0A000')throw $e;echo "rolled-back\n";}
}elseif($action==='verify') {
 $d=new WPDSQL\Engine\NativeDriver($config);$want=$argv[2]??'filled';$columns=$d->query("SHOW COLUMNS FROM $table")->fetchAll();
 if($d->query("SELECT COUNT(*) FROM $table")->fetchColumn()!=='105')throw new RuntimeException('Rows lost');
 if($want==='filled'&&$d->query("SELECT COUNT(*) FROM $table WHERE added='fill'")->fetchColumn()!=='105')throw new RuntimeException('Backfill incomplete');
 if($want==='original'&&in_array('added',array_column($columns,'Field'),true))throw new RuntimeException('Rollback left a new column');
 if(is_file($dir.'/pending.json')||is_file($dir.'/release.json'))throw new RuntimeException('Journal not closed');echo "verified\n";
}elseif($action==='cleanup') {
 if(is_file($dir.'/pending.json')||is_file($dir.'/release.json'))throw new RuntimeException('Recover first');
 $p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_upgrader',region:$s['region'],profile:$s['profile'],schema:'wp_live'));
 $p->exec("DROP TABLE IF EXISTS wp_live.$table");$q=$p->prepare('DELETE FROM wp_live.__wp_dsql_schema WHERE table_name=?');$q->execute([$table]);echo "removed\n";
}else throw new RuntimeException('Unknown action');
