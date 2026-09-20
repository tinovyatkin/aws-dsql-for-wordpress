<?php
/** Status/recovery for automatic schema work, without bootstrapping WordPress. */
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vendor/autoload.php';
$action=$argv[1]??'status';$args=[];
foreach(array_slice($argv,2) as $arg){if(!preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m))throw new RuntimeException('Use --name=value arguments');$args[$m[1]]=$m[2];}
try {
 $target=json_decode(file_get_contents($args['target-config']??throw new RuntimeException('A target configuration is required')),true,512,JSON_THROW_ON_ERROR);
 $dir=realpath($target['state_directory']??'');if(!$dir)throw new RuntimeException('Schema state directory is missing');
 if(in_array($action,['recover','rollback'],true)) {
  $config=new WPDSQL\Engine\Config(host:$target['endpoint'],user:$target['user']??'wp_runtime',region:$target['region'],profile:$target['profile']??null,credentialsFile:$target['credentials_file']??null,schema:$target['schema']??'wp_live',valueCodec:true,tablePrefix:$target['table_prefix']??'wp_',automaticSchema:true,schemaUser:$target['schema_user']??'wp_upgrader',schemaStateDirectory:$dir);
  $controller=new WPDSQL\Schema\AutomaticSchema($config,static fn()=>null,null,$action==='recover');
  if($action==='rollback')$controller->cancel();
 }elseif($action!=='status')throw new RuntimeException('Use status, recover or rollback');
 $pending=is_file($dir.'/pending.json')?json_decode(file_get_contents($dir.'/pending.json'),true,512,JSON_THROW_ON_ERROR):null;
 $last=is_file($dir.'/last-operation.json')?json_decode(file_get_contents($dir.'/last-operation.json'),true,512,JSON_THROW_ON_ERROR):null;
 echo json_encode(['pending'=>$pending!==null,'phase'=>$pending['phase']??null,'step'=>$pending['position']??null,'release_pending'=>is_file($dir.'/release.json'),'last_operation'=>$last],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
} catch(Throwable $e){fwrite(STDERR,'Automatic schema '.$action.' failed: '.($e instanceof PDOException?'database/connection error':$e->getMessage())."\n");exit(1);}
