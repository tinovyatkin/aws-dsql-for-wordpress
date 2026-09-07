<?php
/** One-time administrative setup. Does not run WordPress or alter application data. */
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/migration/Backup.php';require dirname(__DIR__).'/migration/Plan.php';require dirname(__DIR__).'/migration/Restore.php';
$o=[];foreach(array_slice($argv,1) as $arg)if(preg_match('/^--([a-z-]+)=(.*)$/D',$arg,$m))$o[$m[1]]=$m[2];
try {
    if(!isset($o['target-config'],$o['iam-principal']))throw new RuntimeException('Provide --target-config and --iam-principal');
    $t=json_decode(file_get_contents($o['target-config']),true,512,JSON_THROW_ON_ERROR);$role=$o['role']??'wp_upgrader';$runtime=$o['runtime-role']??'wp_runtime';
    foreach([$role,$runtime] as $name)if(!preg_match('/^wp_[a-z0-9_]{1,40}$/D',$name))throw new RuntimeException('Dedicated wp_ roles required');
    if($role===$runtime)throw new RuntimeException('Upgrade and application roles must be separate');
    if(!preg_match('~^arn:aws:iam::[0-9]{12}:(?:user|role)/[A-Za-z0-9+=,.@_/-]+$~D',$o['iam-principal']))throw new RuntimeException('IAM user/role ARN required');
    $p=WPDSQLMigration\Restore::target($t,$t['classification']??'synthetic');
    if($p->query('SELECT current_user')->fetchColumn()!=='admin')throw new RuntimeException('Administrative setup connection required');
    $p->exec('SET search_path TO wp_live, pg_catalog');
    $s=$p->prepare('SELECT 1 FROM pg_roles WHERE rolname=?');$s->execute([$role]);if(!$s->fetchColumn())$p->exec('CREATE ROLE "'.$role.'" WITH LOGIN');
    $p->exec('AWS IAM GRANT "'.$role.'" TO '.$p->quote($o['iam-principal']));
    // PostgreSQL requires membership in the destination role for OWNER TO.
    // This grants the narrow owner role to admin, never admin to the upgrader.
    $p->exec('GRANT "'.$role.'" TO admin');
    $p->exec('CREATE SCHEMA IF NOT EXISTS wp_upgrade_archive');
    $p->exec('GRANT USAGE,CREATE ON SCHEMA wp_live,wp_upgrade_archive TO "'.$role.'"');
    $p->exec("CREATE TABLE IF NOT EXISTS wp_live.__wp_dsql_upgrade_lock (id text PRIMARY KEY,run_id text NOT NULL)");
    $tables=$p->query("SELECT table_name FROM wp_live.__wp_dsql_schema WHERE metadata LIKE '%\"target_schema\":\"wp_live\"%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach(array_merge($tables,['__wp_dsql_schema','__wp_dsql_upgrade_lock']) as $table)$p->exec('ALTER TABLE wp_live.'.WPDSQLMigration\Backup::qi($table).' OWNER TO "'.$role.'"');
    $p->exec('GRANT SELECT ON wp_live.__wp_dsql_restore TO "'.$role.'"');
    $p->exec('REVOKE INSERT,UPDATE,DELETE ON wp_live.__wp_dsql_schema,wp_live.__wp_dsql_restore FROM "'.$runtime.'"');
    if($p->query("SELECT 1 FROM pg_namespace WHERE nspname='wp_archive'")->fetchColumn()) {
        $p->exec('GRANT USAGE ON SCHEMA wp_archive TO "'.$role.'"');$p->exec('GRANT SELECT ON ALL TABLES IN SCHEMA wp_archive TO "'.$role.'"');
    }
    echo 'Configured a separate schema owner for '.count($tables)." application tables; runtime DDL remains disabled.\n";
} catch(Throwable $e) {fwrite(STDERR,'Upgrade-role setup stopped ('.get_class($e)."). Inspect the target before retrying.\n");if(isset($o['error-report'])){file_put_contents($o['error-report'],$e->getMessage());chmod($o['error-report'],0600);}exit(1);}
