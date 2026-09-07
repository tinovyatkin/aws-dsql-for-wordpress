<?php
/** Controlled version-pinned WP-CLI commands, with a persistent maintenance guard. */
ini_set('zend.exception_ignore_args','1');umask(0077);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/upgrade/Engine.php';
use WPDSQLUpgrade\Session;use WPDSQLUpgrade\Engine;
$action=$argv[1]??'help';$args=[];$command=[];$separator=false;
foreach(array_slice($argv,2) as $arg){if($arg==='--'){$separator=true;continue;}if($separator){$command[]=$arg;continue;}if(preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m))$args[$m[1]]=$m[2];else throw new RuntimeException('Use --name=value options');}
function connection(array $t): PDO {
    if(!preg_match('/^[a-z0-9]{26}\.dsql\.([a-z0-9-]+)\.on\.aws$/D',$t['endpoint']??'',$host)||$host[1]!==$t['region'])throw new RuntimeException('DSQL endpoint/region mismatch');
    if(!preg_match('/^wp_[a-z0-9_]+$/D',$t['user']??'')||$t['user']==='wp_runtime')throw new RuntimeException('Separate upgrade role required');
    $provider=isset($t['credentials_file'])?Aws\Credentials\CredentialProvider::ini($t['profile']??'default',$t['credentials_file']):null;
    $config=['version'=>'latest','region'=>$t['region']];if($provider)$config['credentials']=$provider;elseif(isset($t['profile']))$config['profile']=$t['profile'];
    $sdk=new Aws\DSQL\DSQLClient($config);$cluster=$sdk->getCluster(['identifier'=>explode('.',$t['endpoint'])[0]]);
    $purpose=($t['classification']??'')==='production'?'wordpress-dsql-production-migration':'synthetic-wordpress-migration';
    if(($cluster['tags']['Purpose']??'')!==$purpose)throw new RuntimeException('Cluster classification mismatch');
    return Aws\AuroraDsql\PdoPgsql\AuroraDsql::connect(new Aws\AuroraDsql\PdoPgsql\DsqlConfig(host:$t['endpoint'],user:$t['user'],region:$t['region'],credentialsProvider:static fn()=>$sdk->getCredentials()),[PDO::ATTR_STRINGIFY_FETCHES=>true]);
}
function wpBinary(string $path): array {
    if(!str_contains($path,'/'))foreach(explode(PATH_SEPARATOR,getenv('PATH')) as $dir)if(is_file($dir.'/'.$path)){$path=$dir.'/'.$path;break;}
    $path=realpath($path)?:throw new RuntimeException('WP-CLI PHAR not found');
    $head=file_get_contents($path,false,null,0,256);
    if(!str_contains($head,'<?php'))throw new RuntimeException('Provide the WP-CLI PHP/PHAR file, not a shell wrapper');
    return [PHP_BINARY,'-d','error_reporting=8191','-d','zend.exception_ignore_args=1',$path];
}
function snapshotCode(Session $session,array $command): void {
    $wp=$session->data['wordpress'];$kind=$command[0];$id=bin2hex(random_bytes(8));$path=$session->directory.'/code-'.$id.'.tar';$tar=new PharData($path);$files=[];
    $roots=$kind==='plugin'?[$wp.'/wp-content/plugins/'.$command[2]]:[$wp.'/wp-admin',$wp.'/wp-includes'];
    if($kind==='core')foreach(glob($wp.'/*') as $f)if(is_file($f)&&basename($f)!=='wp-config.php'&&!str_starts_with(basename($f),'.'))$files[]=$f;
    foreach($roots as $dir){if(!is_dir($dir)||is_link($dir))throw new RuntimeException('Code root is missing or symlinked');foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $file){if($file->isLink())throw new RuntimeException('Refusing to archive symlinked code');if($file->isFile())$files[]=$file->getPathname();}}
    $entries=[];foreach($files as $file){if(is_link($file))throw new RuntimeException('Refusing to archive symlinked code');$entries[substr($file,strlen($wp)+1)]=$file;}
    $tar->buildFromIterator(new ArrayIterator($entries));unset($tar);chmod($path,0600);
    $versionSource=$kind==='core'?$wp.'/wp-includes/version.php':$wp.'/wp-content/plugins/'.($session->data['plugin_files'][$command[2]]??'');
    $text=is_file($versionSource)?file_get_contents($versionSource):'';
    preg_match($kind==='core'?'/\$wp_version\s*=\s*[\x27\x22]([^\x27\x22]+)/':'/^[ \t*#]*Version:\s*([^\r\n]+)/mi',$text,$version);
    $session->data['code_snapshots'][]=['file'=>basename($path),'sha256'=>hash_file('sha256',$path),'previous_version'=>trim($version[1]??'unknown'),'command'=>$command];$session->save();
}
function installedVersion(Session $session,array $command): string {
    $wp=$session->data['wordpress'];$file=$command[0]==='core'?$wp.'/wp-includes/version.php':$wp.'/wp-content/plugins/'.($session->data['plugin_files'][$command[2]]??'');
    if(!is_file($file))throw new RuntimeException('Updated code entrypoint is missing');
    preg_match($command[0]==='core'?'/\$wp_version\s*=\s*[\x27\x22]([^\x27\x22]+)/':'/^[ \t*#]*Version:\s*([^\r\n]+)/mi',file_get_contents($file),$m);
    return trim($m[1]??'');
}
function checkBackup(array $t,string $reference): void {
    if(($t['classification']??'')==='synthetic'&&$reference==='synthetic-fixture')return;
    if(!preg_match('~^arn:aws:backup:[a-z0-9-]+:[0-9]{12}:recovery-point:[a-z0-9-]+$~D',$reference))throw new RuntimeException('A completed native backup reference is required');
    $config=['region'=>$t['region'],'version'=>'latest'];
    if(isset($t['credentials_file']))$config['credentials']=Aws\Credentials\CredentialProvider::ini($t['profile']??'default',$t['credentials_file']);elseif(isset($t['profile']))$config['profile']=$t['profile'];
    $b=new Aws\Backup\BackupClient($config);$r=$b->describeRecoveryPoint(['BackupVaultName'=>$t['backup_vault']??'blonde-travel-dsql','RecoveryPointArn'=>$reference]);
    if($r['Status']!=='COMPLETED'||!str_ends_with($r['ResourceArn'],'cluster/'.explode('.',$t['endpoint'])[0]))throw new RuntimeException('Backup does not cover this cluster');
    if(time()-$r['CreationDate']->getTimestamp()>86400)throw new RuntimeException('Use a fresh backup from the last 24 hours');
}
try {
    if($action==='help'){echo "upgrade.php begin|exec|recover|cancel|verify|finish|status --session=/private/run [options] [-- WP-CLI command]\nSee docs/controlled-upgrades.md.\n";exit;}
    $directory=$args['session']??throw new RuntimeException('Session path required');
    if($action==='begin') {
        if(($args['writers-frozen']??'')!=='yes')throw new RuntimeException('Pause and drain all external writers before beginning');
        if(file_exists($directory))throw new RuntimeException('Use a new private session directory');
        $wp=realpath($args['wordpress']??'');if(!$wp||!is_file($wp.'/wp-load.php'))throw new RuntimeException('WordPress directory required');
        $parent=realpath(dirname($directory));if(!$parent||$parent===$wp||str_starts_with($parent,$wp.'/'))throw new RuntimeException('Use an existing private session parent outside the WordPress document root');
        $target=json_decode(file_get_contents($args['target-config']??''),true,512,JSON_THROW_ON_ERROR);
        $env=getenv();unset($env['DSQL_UPGRADE_SESSION']);$env['BT_DSQL_MAINTENANCE']='1';
        $probe=proc_open([PHP_BINARY,'-d','error_reporting=8191',dirname(__DIR__).'/upgrade/inspect-site.php',$wp],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$streams,$wp,$env);
        if(!is_resource($probe))throw new RuntimeException('Cannot inspect WordPress connection');
        $siteText=stream_get_contents($streams[1]);$siteError=stream_get_contents($streams[2]);fclose($streams[1]);fclose($streams[2]);
        if(proc_close($probe)!==0)throw new RuntimeException('WordPress connection inspection failed');
        $site=json_decode($siteText,true,512,JSON_THROW_ON_ERROR);
        if($site['endpoint']!==$target['endpoint']||$site['driver']!=='DSQL_WPDB'||$site['multisite']||$site['prefix']!==($args['table-prefix']??'wp_'))throw new RuntimeException('WordPress connection/prefix differs from the supported single-site upgrade target');
        if(($target['classification']??'')==='production'&&($args['allow-production-data']??'')!=='yes')throw new RuntimeException('Explicit production-data session required');
        $backup=$args['backup-reference']??'';checkBackup($target,$backup);
        $p=connection($target);$id=bin2hex(random_bytes(16));$guard=Session::guard($wp);
        $prefix=$args['table-prefix']??'wp_';$runtime=$args['runtime-role']??'wp_runtime';
        if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*_$/D',$prefix)||!preg_match('/^wp_[a-z0-9_]+$/D',$runtime)||$runtime===$target['user'])throw new RuntimeException('Invalid prefix/runtime role');
        mkdir($directory,0700,true);$directory=realpath($directory);
        $data=['format'=>'wordpress-dsql-upgrade-v1','id'=>$id,'directory'=>$directory,'host'=>gethostname(),'wordpress'=>$wp,'target'=>$target,'table_prefix'=>$prefix,'runtime_role'=>$runtime,'backup_reference'=>$backup,'allow_destructive'=>($args['allow-destructive']??'')==='yes','omit_fulltext_indexes'=>isset($args['policy'])?(json_decode(file_get_contents($args['policy']),true)['omit_fulltext_indexes']??[]):[], 'failed'=>false,'verified'=>false,'closed'=>false,'started_at'=>gmdate('c')];
        Session::write($directory.'/session.json',$data);
        $g=fopen($guard,'x');if(!$g)throw new RuntimeException('Another upgrade maintenance guard exists');fwrite($g,$id."\n");fflush($g);fsync($g);fclose($g);chmod($guard,0644);Session::syncDirectory(dirname($guard));
        try {$s=$p->prepare("INSERT INTO wp_live.__wp_dsql_upgrade_lock (id,run_id) VALUES ('schema',?)");$s->execute([$id]);}
        catch(Throwable $e){unlink($guard);throw $e;}
        $cmd=array_merge(wpBinary($args['wp-cli']??'wp'),['--path='.$wp,'core','is-installed']);if(function_exists('posix_geteuid')&&posix_geteuid()===0)$cmd[]='--allow-root';
        $env=getenv();unset($env['DSQL_UPGRADE_SESSION']);$env['BT_DSQL_MAINTENANCE']='1';
        $probe=proc_open($cmd,[0=>['file','/dev/null','r'],1=>['file',$directory.'/guard-probe.log','w'],2=>['file',$directory.'/guard-probe.log','a']],$pipes,$wp,$env);
        if(!is_resource($probe)||proc_close($probe)!==75) {
            $s=$p->prepare("DELETE FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema' AND run_id=?");$s->execute([$id]);unlink($guard);
            throw new RuntimeException('WordPress is not enforcing the upgrade guard; deploy the upgrade-capable adapter first');
        }
        $s=$p->query('SELECT option_value FROM wp_live.'.WPDSQLMigration\Backup::qi($prefix.'options')." WHERE option_name='active_plugins'");
        $active=unserialize(DSQL_Value_Codec::decode((string)$s->fetchColumn()),['allowed_classes'=>false]);
        if(!is_array($active))throw new RuntimeException('Cannot read active plugin inventory');
        $data['plugins']=array_values(array_unique(array_map(static fn($file)=>dirname($file)==='.'?basename($file,'.php'):dirname($file),$active)));
        foreach($active as $file)$data['plugin_files'][dirname($file)==='.'?basename($file,'.php'):dirname($file)]=$file;
        Session::write($directory.'/session.json',$data);
        echo "Upgrade session started; ordinary WordPress requests are blocked.\n";exit;
    }
    $session=new Session($directory,false);
    $controller=fopen($directory.'/controller.lock','c');if(!flock($controller,LOCK_EX|LOCK_NB))throw new RuntimeException('Upgrade controller already running');
    if($action==='status') {echo json_encode(['id'=>$session->data['id'],'closed'=>$session->data['closed'],'failed'=>$session->data['failed'],'verified'=>$session->data['verified'],'pending_phase'=>$session->operation()['phase']??null]),"\n";exit;}
    if($action==='plan') {
        $locked=new Session($directory);$engine=new Engine(connection($locked->data['target']),$locked);
        $sql=file_get_contents($args['sql-file']??throw new RuntimeException('A single-statement --sql-file is required'));
        $plan=$engine->plan($sql);Session::write($directory.'/preview.json',['plan'=>$plan]);
        echo json_encode(['mode'=>$plan['mode']??'noop','table'=>$plan['original']??null,'columns_after'=>count($plan['after']['columns']??[]),'indexes_after'=>count($plan['after']['indexes']??[]),'details_file'=>$directory.'/preview.json']),"\n";exit;
    }
    if(in_array($action,['recover','cancel'],true)) {
        $locked=new Session($directory);$guard=Session::guard($locked->data['wordpress']);
        if($locked->data['closed']||!is_file($guard)||trim(file_get_contents($guard))!==$locked->data['id'])throw new RuntimeException('Recovery requires this session\'s active maintenance guard');
        $p=connection($locked->data['target']);
        if(!$p->query("SELECT run_id FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema'")->fetchColumn()){$s=$p->prepare("INSERT INTO wp_live.__wp_dsql_upgrade_lock (id,run_id) VALUES ('schema',?)");$s->execute([$locked->data['id']]);}
        $engine=new Engine($p,$locked);
        try {$action==='recover'?$engine->recover():$engine->cancel();}catch(Throwable $e){$locked->fail();throw $e;}
        echo "Schema recovery completed; rerun the interrupted idempotent command and verify before finishing.\n";exit;
    }
    if($action==='finish') {
        if($session->data['closed']) {
            $p=connection($session->data['target']);if($p->query("SELECT run_id FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema'")->fetchColumn())throw new RuntimeException('Database is reserved by another session');
            $guard=Session::guard($session->data['wordpress']);if(is_file($guard)&&trim(file_get_contents($guard))===$session->data['id'])unlink($guard);
            foreach($session->data['code_snapshots']??[] as $snapshot)if(is_file($directory.'/'.$snapshot['file']))unlink($directory.'/'.$snapshot['file']);
            echo "Closed session finalized.\n";exit;
        }
        $session->assertActive();if(!$session->data['verified']||$session->operation())throw new RuntimeException('Successful verification is required before reopening');
        $p=connection($session->data['target']);$engine=new Engine($p,$session);$engine->verify();
        $s=$p->prepare("DELETE FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema' AND run_id=?");$s->execute([$session->data['id']]);
        $session->data['closed']=true;$session->data['finished_at']=gmdate('c');$session->save();$guard=Session::guard($session->data['wordpress']);unlink($guard);Session::syncDirectory(dirname($guard));
        foreach($session->data['code_snapshots']??[] as $snapshot)unlink($directory.'/'.$snapshot['file']);
        echo "Upgrade verified and WordPress reopened. Retained tables remain available to the upgrade owner.\n";exit;
    }
    if(!in_array($action,['exec','verify'],true))throw new RuntimeException('Unknown upgrade action');
    $session->assertActive();if($session->operation())throw new RuntimeException('Recover pending DDL first');
    if($action==='verify')$command=['dsql-upgrade-verify'];
    if(!$command)throw new RuntimeException('WP-CLI command required after --');
    foreach($command as $arg)if(preg_match('/^--(?:path|url|ssh|http|require|exec|skip-plugins|skip-themes|allow-root)(?:=|$)/',$arg))throw new RuntimeException('Runner controls WordPress targeting and bootstrap');
    $kind=implode(' ',array_slice($command,0,2));
    if($action==='exec'&&!in_array($kind,['core update','core update-db','plugin update','eval-file','eval'],true)&&!in_array($command[0],['eval-file','eval'],true))throw new RuntimeException('Only controlled core/plugin updates and explicit migration callbacks are supported');
    if(in_array($kind,['core update','plugin update'],true)&&!array_filter($command,static fn($a)=>preg_match('/^--version=[0-9][a-zA-Z0-9.-]*$/D',$a)))throw new RuntimeException('Pin an exact core/plugin version');
    if($kind==='plugin update'&&(!isset($command[2])||str_starts_with($command[2],'--')||in_array('--all',$command,true)))throw new RuntimeException('Update one explicitly named plugin at a time');
    if($kind==='plugin update'&&(!preg_match('/^[a-z0-9-]+$/D',$command[2])||!in_array($command[2],$session->data['plugins']??[],true)))throw new RuntimeException('Select a plugin from this session\'s active inventory');
    if(in_array($kind,['core update','plugin update'],true)) {
        $start=$kind==='plugin update'?3:2;
        foreach(array_slice($command,$start) as $flag)if(!preg_match('/^--version=[0-9][a-zA-Z0-9.-]*$/D',$flag)&&!in_array($flag,['--force','--skip-content'],true))throw new RuntimeException('Unsupported package-update flag');
        snapshotCode($session,$command);
    }
    $session->data['verified']=false;$session->data['last_command']=$command;$session->save();
    $wpCli=$args['wp-cli']??'wp';
    $cmd=array_merge(wpBinary($wpCli),['--path='.$session->data['wordpress'],'--require='.dirname(__DIR__).'/upgrade/wp-cli.php']);
    if(function_exists('posix_geteuid')&&posix_geteuid()===0)$cmd[]='--allow-root';
    $cmd=array_merge($cmd,$command);
    $env=getenv();$env['DSQL_UPGRADE_SESSION']=$directory;$env['BT_DSQL_MAINTENANCE']='1';
    $process=proc_open($cmd,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,$session->data['wordpress'],$env);
    if(!is_resource($process))throw new RuntimeException('Cannot start WP-CLI');$code=proc_close($process);
    $session=new Session($directory,false);
    if($code!==0||$session->data['failed']||$session->operation()){$session->fail();throw new RuntimeException('WP-CLI command or a captured schema operation failed; site remains guarded');}
    if(in_array($kind,['core update','plugin update'],true)) {
        $requested=array_values(array_filter($command,static fn($a)=>str_starts_with($a,'--version=')))[0];
        if(installedVersion($session,$command)!==substr($requested,10)){$session=new Session($directory,false);$session->fail();throw new RuntimeException('Installed version differs from the requested pinned version');}
    }
    echo "Controlled command completed; explicit verification/finish still required.\n";
} catch(Throwable $e) {
    if(isset($directory)&&is_dir($directory)){file_put_contents($directory.'/controller-error.txt',$e->getMessage());chmod($directory.'/controller-error.txt',0600);}
    elseif(isset($directory)&&is_dir(dirname($directory))&&!is_link($directory.'.error.txt')){file_put_contents($directory.'.error.txt',$e->getMessage());chmod($directory.'.error.txt',0600);}
    fwrite(STDERR,'Controlled upgrade stopped ('.get_class($e)."). Inspect its private journal; automatic updates remain disabled.\n");exit(1);
}
