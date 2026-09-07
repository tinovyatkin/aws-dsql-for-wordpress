<?php
/** Backup/plan/restore/verify only. Never changes a live WordPress connection. */
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/migration/Backup.php';
require dirname(__DIR__).'/migration/Plan.php';
require dirname(__DIR__).'/migration/Policy.php';
use WPDSQLMigration\Backup;
use WPDSQLMigration\Plan;
use WPDSQLMigration\Restore;
$command=$argv[1]??'help';$options=[];
foreach (array_slice($argv,2) as $arg) { if(!preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m)) exit("Options use --name=value\n");$options[$m[1]]=$m[2]; }
function required(string $key):string { global $options;return $options[$key]??throw new RuntimeException('Missing --'.$key); }
try {
    if (in_array($command,['backup','inspect-source'],true)) {
        if (isset($options['mysql-config'])) { $config=require $options['mysql-config']; }
        elseif (isset($options['wordpress-path'])) {
            define('SHORTINIT',true);require rtrim($options['wordpress-path'],'/').'/wp-load.php';
            if (defined('DB_DRIVER') && DB_DRIVER==='dsql') throw new RuntimeException('MySQL source required');
            $host=DB_HOST;$port=3306;
            if (preg_match('/^([^:]+):(\d+)$/',$host,$m)) { $host=$m[1];$port=(int)$m[2]; }
            if (str_contains($host,':')) throw new RuntimeException('Use --mysql-config for socket/IPv6 source hosts');
            $config=['dsn'=>'mysql:host='.$host.';port='.$port.';dbname='.DB_NAME.';charset=utf8mb4','user'=>DB_USER,'password'=>DB_PASSWORD];
        } else throw new RuntimeException('Provide --mysql-config or --wordpress-path');
        if (isset($options['mysql-ca'])) $config['ssl_ca']=$options['mysql-ca'];
        if ($command==='inspect-source') {
            $report=Backup::inspectSource(Backup::source($config),\WPDSQLMigration\Policy::load($options['policy']??null));
            if (isset($options['report'])) {file_put_contents($options['report'],Backup::json($report)."\n");chmod($options['report'],0600);}
            echo Backup::json($report),"\n";exit($report['preflight_passed']?0:1);
        }
        $manifest=Backup::export(Backup::source($config),required('backup'),required('classification'));
        echo Backup::json(['backup_complete'=>true,'tables'=>count($manifest['tables']),'rows'=>array_sum(array_column($manifest['tables'],'rows'))]),"\n";
    } elseif (in_array($command,['plan','restore','verify'],true)) {
        $directory=required('backup');$manifest=\WPDSQLMigration\Policy::apply(Backup::load($directory),\WPDSQLMigration\Policy::load($options['policy']??null));
        if ($command==='plan') { $report=Plan::inspect($directory,$manifest); }
        else {
            require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/migration/Restore.php';
            $settings=json_decode(file_get_contents(required('target-config')),true,512,JSON_THROW_ON_ERROR);
            if (($settings['endpoint']??'')!==required('expect-target')) throw new RuntimeException('Target endpoint does not match explicit --expect-target');
            if ($manifest['classification']==='production' && ($options['allow-production-data']??'')!=='yes') throw new RuntimeException('Production backup requires --allow-production-data=yes and a protected non-Playground destination');
            $pdo=Restore::target($settings,$manifest['classification']);
            $report=$command==='restore'?Restore::run($pdo,$directory,$manifest,required('expect-target')):Restore::verify($pdo,$directory,$manifest);
        }
        if (isset($options['report'])) { file_put_contents($options['report'],Backup::json($report)."\n");chmod($options['report'],0600); }
        echo Backup::json($report),"\n";
        if (($report['restore_supported']??true)===false || ($report['verified']??true)===false) exit(1);
    } else {
        echo "php scripts/migrate.php backup --wordpress-path=/path/to/wp --backup=/private/new-directory --classification=production\n";
        echo "php scripts/migrate.php plan --backup=/private/backup-directory\n";
        echo "php scripts/migrate.php restore|verify --backup=/private/backup-directory --target-config=/private/dsql.json --expect-target=cluster.dsql.region.on.aws\n";
    }
} catch (Throwable $e) {
    // SQL errors may contain actual row values. Retain those only in a private report.
    if (isset($options['error-report'])) { file_put_contents($options['error-report'],Backup::json(['error'=>$e->getMessage(),'type'=>get_class($e)])."\n");chmod($options['error-report'],0600); }
    fwrite(STDERR,'Migration stopped ('.get_class($e).'). No WordPress cutover was performed.');
    if (isset($options['error-report'])) fwrite(STDERR,' See the private error report.');
    fwrite(STDERR,"\n");exit(1);
}
