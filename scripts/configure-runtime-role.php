<?php
/** Configure a non-admin WordPress role after a verified restore. No site cutover. */
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/migration/Backup.php';require dirname(__DIR__).'/migration/Plan.php';require dirname(__DIR__).'/migration/Policy.php';require dirname(__DIR__).'/migration/Restore.php';
$options=[];foreach(array_slice($argv,1) as $arg){if(preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m))$options[$m[1]]=$m[2];}
if(!isset($options['target-config'],$options['policy'],$options['output'])){echo "Usage: php scripts/configure-runtime-role.php --target-config=/private/target.json --policy=/private/policy.json --output=/private/runtime.json [--role=wp_runtime] [--iam-principal=arn:aws:iam::ACCOUNT:role/ROLE]\n";exit(0);}
try{
 if(file_exists($options['output']))throw new RuntimeException('Runtime configuration already exists');
 $settings=json_decode(file_get_contents($options['target-config']),true,512,JSON_THROW_ON_ERROR);$policy=WPDSQLMigration\Policy::load($options['policy']);
 if($policy['active_schema']!=='wp_live')throw new RuntimeException('Non-admin runtime requires the wp_live application schema');
 $role=$options['role']??'wp_runtime';if(!preg_match('/^wp_[a-z0-9_]{1,40}$/D',$role))throw new RuntimeException('Use a dedicated wp_ role name');
 $p=WPDSQLMigration\Restore::target($settings,$settings['classification']??'synthetic');
 $state=$p->query("SELECT phase,policy_sha256 FROM wp_live.__wp_dsql_restore WHERE id='restore'")->fetch(PDO::FETCH_ASSOC);
 if(!$state || $state['phase']!=='verified' || !hash_equals($state['policy_sha256'],hash('sha256',WPDSQLMigration\Backup::json($policy))))throw new RuntimeException('A verified restore with this policy is required');
 $s=$p->prepare('SELECT 1 FROM pg_roles WHERE rolname=?');$s->execute([$role]);if($s->fetchColumn())throw new RuntimeException('Role already exists; refusing to assume its prior privileges');
 $principal=$options['iam-principal']??null;
 if(!$principal){$sts=new Aws\Sts\StsClient(['region'=>$settings['region'],'version'=>'latest','profile'=>$settings['profile']??null]);$principal=$sts->getCallerIdentity([])['Arn'];}
 if(!preg_match('~^arn:aws:iam::[0-9]+:(?:user|role)/[A-Za-z0-9+=,.@_/-]+$~D',$principal))throw new RuntimeException('Pass an IAM user/role ARN, not an STS session ARN');
 $q=WPDSQLMigration\Backup::qi($role);$p->exec('CREATE ROLE '.$q.' WITH LOGIN');$p->exec('AWS IAM GRANT '.$q.' TO '.$p->quote($principal));
 $p->exec('GRANT USAGE ON SCHEMA wp_live TO '.$q);
 $p->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA wp_live TO '.$q);
 $p->exec('GRANT USAGE,SELECT,UPDATE ON ALL SEQUENCES IN SCHEMA wp_live TO '.$q);
 if($policy['archive_tables']){$p->exec('GRANT USAGE ON SCHEMA wp_archive TO '.$q);$p->exec('GRANT SELECT ON ALL TABLES IN SCHEMA wp_archive TO '.$q);}
 $settings['user']=$role;$settings['schema']='wp_live';$settings['value_codec']=$policy['value_codec']?'frame-v1':null;
 file_put_contents($options['output'],json_encode($settings,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");chmod($options['output'],0600);
 echo "Configured runtime role; archive grants are SELECT-only. No WordPress connection was changed.\n";
}catch(Throwable $e){fwrite(STDERR,'Runtime role setup stopped: '.get_class($e).". Inspect the target before retrying.\n");exit(1);}
