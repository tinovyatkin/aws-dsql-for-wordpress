<?php
/** The offline runner can rebuild a table containing automatic NOT NULL checks. */
ini_set('zend.exception_ignore_args','1');
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$s=json_decode(file_get_contents($root.'/.local/upgrade-lab/runtime-target.json'),true);
$aws=new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$s['region'],'profile'=>$s['profile']]);
if(($s['classification']??'')!=='synthetic'||($aws->getCluster(['identifier'=>explode('.',$s['endpoint'])[0]])['tags']['Purpose']??'')!=='synthetic-wordpress-migration')throw new RuntimeException('Synthetic fixture required');
$dir=$root.'/.local/automatic-manual-'.bin2hex(random_bytes(4));mkdir($dir,0700);
$config=new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_runtime',region:$s['region'],profile:$s['profile'],schema:'wp_live',valueCodec:true,tablePrefix:'wp_',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$dir);
$p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$s['endpoint'],user:'wp_upgrader',region:$s['region'],profile:$s['profile'],schema:'wp_live'));
$t='wp_auto_manual_'.bin2hex(random_bytes(4));$d=new WPDSQL\Engine\NativeDriver($config);$id='test-'.bin2hex(random_bytes(8));$guard=null;$reserved=false;$retained=null;
try {
 $d->query("CREATE TABLE $t(id bigint PRIMARY KEY, label varchar(30) NOT NULL DEFAULT '')");$d->query("INSERT INTO $t VALUES(1,'preserved')");
 $d->query("ALTER TABLE $t ADD extra int NOT NULL DEFAULT 5");$d->close();
 $data=['format'=>'wordpress-dsql-upgrade-v1','id'=>$id,'directory'=>$dir,'host'=>gethostname(),'wordpress'=>$dir.'/unused-wp','target'=>['user'=>'wp_upgrader','classification'=>'synthetic'],'table_prefix'=>'wp_','runtime_role'=>'wp_runtime','allow_destructive'=>false,'omit_fulltext_indexes'=>[]];
 WPDSQLUpgrade\Session::write($dir.'/session.json',$data);$session=new WPDSQLUpgrade\Session($dir);
 $guard=WPDSQLUpgrade\Session::guard($data['wordpress']);file_put_contents($guard,$id);
 $q=$p->prepare("INSERT INTO wp_live.__wp_dsql_upgrade_lock(id,run_id) VALUES('schema',?)");$q->execute([$id]);$reserved=true;
 $engine=new WPDSQLUpgrade\Engine($p,$session);$engine->execute("ALTER TABLE $t MODIFY label varchar(60) NOT NULL DEFAULT ''");
 $op=json_decode(file_get_contents(glob($dir.'/operation-*.json')[0]),true);$retained=$op['retained'];
 if($op['mode']!=='rebuild'||$p->query("SELECT label FROM wp_live.$t WHERE id=1")->fetchColumn()!=='preserved'||$p->query("SELECT extra FROM wp_live.$t WHERE id=1")->fetchColumn()!=='5')throw new RuntimeException('Rebuild did not preserve data');
 $catalog=new DSQL_Schema_Catalog($p);foreach($catalog->get($t)['columns'] as $column)if(isset($column['dsql_not_null_constraint']))throw new RuntimeException('Old CHECK marker retained');
 $q=$p->prepare("SELECT is_nullable FROM information_schema.columns WHERE table_schema='wp_live' AND table_name=? AND column_name='extra'");$q->execute([$t]);if($q->fetchColumn()!=='NO')throw new RuntimeException('Rebuild lost NOT NULL');
 echo "PASS controlled rebuild after automatic required-column addition\n";
}finally{
 $d->close();
 if(!is_file($dir.'/pending.json')) {
  $p->exec("DROP TABLE IF EXISTS wp_live.$t");if($retained)$p->exec('DROP TABLE IF EXISTS wp_upgrade_archive."'.$retained.'"');
  $q=$p->prepare('DELETE FROM wp_live.__wp_dsql_schema WHERE table_name=?');$q->execute([$t]);
  if($reserved){$q=$p->prepare("DELETE FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema' AND run_id=?");$q->execute([$id]);}
  if($guard)unlink($guard);
 }
}
