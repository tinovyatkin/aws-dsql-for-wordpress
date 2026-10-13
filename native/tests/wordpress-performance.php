<?php
/** Run with wp eval-file on a staging WordPress using the matching native release.
 * Optional first argument: idle seconds (0–90). No schema or existing rows change.
 */
global $wpdb;
if (wp_get_environment_type() !== 'staging' || !($wpdb instanceof DSQL_WPDB)
    || !($wpdb->get_driver() instanceof WPDSQL\Engine\NativeDriver)) {
    throw new RuntimeException('Native DSQL staging WordPress required');
}
$config=(new ReflectionProperty($wpdb->get_driver(),'nativeConfig'))->getValue($wpdb->get_driver());
$revision='performance-contract-'.bin2hex(random_bytes(8));
$make=static fn()=>new DsqlNativeEngine($config->host,$config->region,$config->profile?:'default',
    $config->user,$config->schema,$config->tablePrefix,$revision,$config->credentialsFile,
    $config->valueCodec,$config->sqlMode,$config->cacheEnabled);
$checks=[];
$check=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$sql="SELECT p.ID,m.meta_id FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID=m.post_id WHERE p.post_status='publish' ORDER BY p.ID,m.meta_id LIMIT 5";
$a=$make();$first=$a->query($sql);
$check(($first['timing']['metadata_tables_loaded']??0)===2.0 && ($first['timing']['metadata_round_trips']??0)===1.0,'two-table metadata uses one catalog round trip');
$check($a->query($sql)['rows']===$first['rows'],'same-client plan preserves rows');
$a->close();
// SQLx returns dropped leases asynchronously, including a liveness check.
usleep(250000);
$b=$make();$warm=$b->query($sql);
$check($warm['rows']===$first['rows'] && ($warm['timing']['plan_hit']??0)===1.0,'new client reuses generation-scoped plan');
$check($b->connectionStats()['opened']===0.0,'new client reuses physical connection');
$b->invalidateSchema();$fresh=$b->query($sql);
$check($fresh['rows']===$first['rows'] && ($fresh['timing']['metadata_tables_loaded']??0)===2.0,'invalidation reloads physical metadata and preserves rows');
$b->close();
$key='native_performance_'.bin2hex(random_bytes(8));
$check($wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",$key))===null,'temporary key is absent');
try {
    foreach(['before','after'] as $value) {
        $result=$wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",$key,$value));
        $check($result!==false && $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key))===$value,'unique-key upsert '.$value);
    }
} finally {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name=%s",$key));
}
$check($wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name=%s",$key))===null,'temporary option removed');
$idle=(int)($args[0]??0);
if($idle<0||$idle>90)throw new RuntimeException('Idle probe accepts 0–90 seconds');
if($idle>0)sleep($idle);
$c=$make();$retained=$c->query($sql);$stats=$c->connectionStats();
$check($retained['rows']===$first['rows'] && $stats['opened']===0.0,'physical connection survives idle interval');
$c->close();
$check($wpdb->dsql_error_count===0,'no adapter errors');
echo json_encode(['checks'=>$checks,'first_metadata_ms'=>$first['timing']['metadata_ms'],'metadata_round_trips'=>1,'idle_seconds'=>$idle,'retained_connection'=>$stats,'errors'=>$wpdb->dsql_error_count],JSON_THROW_ON_ERROR)."\n";
