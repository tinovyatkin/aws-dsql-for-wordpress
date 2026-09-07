<?php
$root=dirname(__DIR__,2);require $root.'/.local/full-target/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Local demo required');
$old=$wpdb->suppress_errors(true);
$r=$wpdb->insert($wpdb->options,['option_name'=>"unsupported\0key",'option_value'=>'probe','autoload'=>'off']);
$wpdb->suppress_errors($old);
if($r!==false||!str_contains($wpdb->last_error,'unindexed TEXT'))throw new RuntimeException('Indexed NUL write was not rejected');
echo "PASS indexed NUL writes are rejected before changing data\n";
require $root.'/migration/Backup.php';require $root.'/migration/Plan.php';require $root.'/migration/Restore.php';
$settings=json_decode(file_get_contents($root.'/.local/full-demo/runtime-target.json'),true);$pdo=WPDSQLMigration\Restore::target($settings);
if((int)$pdo->query('SELECT COUNT(*) FROM wp_archive.wp_statistics_visitor')->fetchColumn()!==8000)throw new RuntimeException('Archive read count mismatch');
try{$pdo->exec('UPDATE wp_archive.wp_statistics_visitor SET agent=agent');throw new LogicException('Archive write allowed');}catch(PDOException $e){if($e->getCode()!=='42501')throw $e;}
echo "PASS runtime role reads all 8000 archived rows and cannot update them\n";
