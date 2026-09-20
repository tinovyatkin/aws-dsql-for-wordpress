<?php
/** Opt in an existing runtime IAM principal to the existing narrow schema-owner role. */
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vendor/autoload.php';
$args=[];foreach(array_slice($argv,1) as $arg){if(!preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m))throw new RuntimeException('Use --name=value arguments');$args[$m[1]]=$m[2];}
try {
 $target=json_decode(file_get_contents($args['target-config']??throw new RuntimeException('Target configuration required')),true,512,JSON_THROW_ON_ERROR);
 $host=$target['endpoint'];$schema=$target['schema']??'wp_live';$owner=$args['schema-user']??'wp_upgrader';$runtime=$args['runtime-role']??'wp_runtime';$principal=$args['iam-principal']??'';
 if(!preg_match('/^[a-z0-9]{26}\.dsql\.([a-z0-9-]+)\.on\.aws$/D',$host,$m)||$m[1]!==$target['region'])throw new RuntimeException('Endpoint and region must match');
 if(!in_array($schema,['wp_live','public'],true)||$owner===$runtime)throw new RuntimeException('Separate schema and runtime roles required');
 foreach([$owner,$runtime] as $role)if(!preg_match('/^wp_[a-z0-9_]{1,40}$/D',$role))throw new RuntimeException('Dedicated WordPress roles required');
 if(!preg_match('~^arn:aws:iam::[0-9]{12}:(?:user|role)/[A-Za-z0-9+=,.@_/-]+$~D',$principal))throw new RuntimeException('IAM principal ARN required');
 if(($args['token-stdin']??'no')==='yes') {
  // Short-lived, caller-generated admin token arrives only via stdin, never args or a file.
  $token=trim(stream_get_contents(STDIN));if($token==='')throw new RuntimeException('Admin token missing');
  $ca=getenv('PGSSLROOTCERT');if(!$ca||!is_file($ca))throw new RuntimeException('An explicit trusted CA file is required');
  $p=new PDO('pgsql:host='.$host.';port=5432;dbname=postgres;sslmode=verify-full;sslrootcert='.$ca,'admin',$token,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_STRINGIFY_FETCHES=>true]);unset($token);
 } else {
  $aws=new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$target['region'],'profile'=>$target['profile']??'default']);
  $live=$aws->getCluster(['identifier'=>explode('.',$host)[0]]);$purpose=($target['classification']??'synthetic')==='production'?'wordpress-dsql-production-migration':'synthetic-wordpress-migration';
  if(($live['tags']['Purpose']??'')!==$purpose)throw new RuntimeException('Cluster purpose differs from target configuration');
  $p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$host,user:'admin',region:$target['region'],profile:$target['profile']??null,credentialsFile:$target['credentials_file']??null,schema:$schema));
 }
 if($p->query('SELECT current_user')->fetchColumn()!=='admin')throw new RuntimeException('Administrative setup identity required');
 $q=$p->prepare('SELECT tableowner FROM pg_tables WHERE schemaname=? AND tablename=?');
 foreach(['__wp_dsql_schema','__wp_dsql_upgrade_lock'] as $table){$q->execute([$schema,$table]);if($q->fetchColumn()!==$owner)throw new RuntimeException('Configure the schema owner and upgrade catalog first');}
 $sql=($args['revoke']??'no')==='yes'?'AWS IAM REVOKE "'.$owner.'" FROM ':'AWS IAM GRANT "'.$owner.'" TO ';
 $p->exec($sql.$p->quote($principal));
 echo (($args['revoke']??'no')==='yes'?'Removed':'Configured')." automatic schema role mapping; the default runtime SQL role remains separate.\n";
} catch(Throwable $e){fwrite(STDERR,'Automatic schema setup failed ('.get_class($e).'; '.($e instanceof PDOException?$e->getCode():'configuration').").\n");exit(1);}
