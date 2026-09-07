<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/migration/Backup.php';
require dirname(__DIR__,2).'/migration/Plan.php';
require dirname(__DIR__,2).'/migration/Restore.php';
use WPDSQLMigration\Backup;
use WPDSQLMigration\Plan;
use WPDSQLMigration\Restore;
$root=dirname(__DIR__,2).'/.local/migration';$backup=$root.'/backup-02';$manifest=Backup::load($backup);$settings=json_decode(file_get_contents($root.'/target.json'),true);
function rejects(callable $f,string $name,string $message):void {
    try {$f();}catch(Throwable $e){if(!str_contains($e->getMessage(),$message))throw $e;echo "PASS $name\n";return;}
    throw new RuntimeException('Guard did not reject: '.$name);
}
rejects(static fn()=>Backup::export(Backup::source(require $root.'/source-config.php'),$backup),'Refuses backup overwrite','already exists');
$corrupt=$root.'/tampered';if(!is_dir($corrupt))mkdir($corrupt,0700);
foreach(glob($backup.'/*') as $file)copy($file,$corrupt.'/'.basename($file));
file_put_contents($corrupt.'/'.$manifest['tables'][0]['file'],'tamper',FILE_APPEND);
rejects(static fn()=>Backup::load($corrupt),'Detects altered table backup','checksum mismatch');
rejects(static fn()=>Restore::target($settings,'production'),'Production data cannot enter synthetic cluster','purpose');
$pdo=Restore::target($settings);
rejects(static fn()=>Restore::run($pdo,$backup,$manifest,$settings['endpoint']),'Refuses non-empty restore target','not empty');
$bad=$manifest;$bad['tables'][0]['columns'][0]['Type']='geometry';
$plan=Plan::inspect($backup,$bad);
if($plan['restore_supported'])throw new RuntimeException('Unsupported schema accepted');
echo "PASS Unsupported schema blocks the restore plan\n";
rejects(static fn()=>Backup::source(['dsn'=>'mysql:host=remote.example.invalid;dbname=x','user'=>'x','password'=>'x']),'Remote MySQL requires verified TLS','ssl_ca');
$source=Backup::source(require $root.'/source-config.php');
if($source->query('SELECT DATABASE()')->fetchColumn()!=='wordpress_fixture')throw new RuntimeException('Synthetic MySQL fixture required');
$probe='wp_migration_nul_'.bin2hex(random_bytes(4));
try {
    $source->exec('CREATE TABLE '.Backup::qi($probe,'`').' (value varchar(32)) ENGINE=InnoDB');
    $s=$source->prepare('INSERT INTO '.Backup::qi($probe,'`').' (value) VALUES (?)');$s->execute(["real\0nul"]);$s->closeCursor();
    $inspection=Backup::inspectSource($source);
    $nul=array_filter($inspection['issues'],static fn($i)=>$i['table']===$probe&&str_contains($i['reason'],'NUL'));
    if(count($nul)!==1)throw new RuntimeException('NUL preflight missed a real NUL');
    echo "PASS Source preflight detects real NUL bytes without Unicode false positives\n";
} finally {$source->exec('DROP TABLE '.Backup::qi($probe,'`'));}
