<?php
$root=dirname(__DIR__,2);$lab=$root.'/.local/upgrade-lab';$session=$lab.'/native-cache-'.time();
$settings=json_decode(file_get_contents($lab.'/runtime-target.json'),true,flags:JSON_THROW_ON_ERROR);
if($settings['classification']!=='synthetic'||$settings['user']!=='wp_runtime')throw new RuntimeException('Synthetic runtime required');
function command(string $action,array $args=[]):void{global $root,$session;
 $cmd=[PHP_BINARY,$root.'/scripts/upgrade.php',$action,'--session='.$session,...$args];
 $p=proc_open($cmd,[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);
 file_put_contents($root.'/.local/native-upgrade-cache-detail.log',$out.$err,FILE_APPEND);if($code)throw new RuntimeException('Controlled operation failed: '.$action);
}
function execute(string $code):void{command('exec',['--','eval','global $wpdb;'.$code]);}
function client():DsqlNativeEngine{global $settings;return new DsqlNativeEngine($settings['endpoint'],$settings['region'],$settings['profile'],$settings['user'],$settings['schema'],'wp_','native-cache-rebuild');}
// The controller performs a live synthetic-cluster tag check before writes.
command('begin',['--wordpress='.$root.'/.local/upgrade-wordpress','--target-config='.$lab.'/upgrade-target.json','--writers-frozen=yes','--backup-reference=synthetic-fixture','--allow-destructive=yes']);
execute('$wpdb->query("CREATE TABLE wp_native_cache_probe (id bigint PRIMARY KEY,label varchar(30))");$wpdb->query("INSERT INTO wp_native_cache_probe VALUES (1,\'original\')");');
$a=client();$b=client();$q='SELECT label FROM wp_native_cache_probe WHERE id=1';
foreach([$a,$b] as $c)if($c->query($q)['rows'][0]['label']!=='original')throw new RuntimeException('Warm baseline');
$b->close();
execute('$wpdb->query("ALTER TABLE wp_native_cache_probe ADD COLUMN new_field varchar(30) DEFAULT \'new\'");$wpdb->query("UPDATE wp_native_cache_probe SET label=\'replacement\' WHERE id=1");');
// A fresh request detects the catalog generation, invalidating idle and live leases.
$b=client();if($b->query($q)['rows'][0]['label']!=='replacement')throw new RuntimeException('Idle prepared statement still reads retained original');
if($a->query($q)['rows'][0]['label']!=='replacement')throw new RuntimeException('Live prepared statement still reads retained original');
if($a->query('SELECT new_field FROM wp_native_cache_probe WHERE id=1')['rows'][0]['new_field']!=='new')throw new RuntimeException('Physical metadata remained stale');
$a->close();$b->close();
execute('$wpdb->query("DROP TABLE wp_native_cache_probe");');command('verify');command('finish');
echo "PASS retained-table rebuild invalidates idle/live statements and column metadata\n";
