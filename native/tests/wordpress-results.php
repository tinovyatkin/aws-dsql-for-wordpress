<?php
/** Read-only result-shape regression checks against a native DSQL WordPress. */
global $wpdb;
if (!($wpdb instanceof DSQL_WPDB) || !($wpdb->get_driver() instanceof WPDSQL\Engine\NativeDriver)) {
    throw new RuntimeException('Native DSQL WordPress required');
}
$checks=[];
$check=static function(bool $ok,string $name)use(&$checks){if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
$driver=$wpdb->get_driver();
$sql="SELECT 1 AS same,2 AS same,NULL AS absent,'' AS empty_value";
$check($driver->query($sql)->fetchAll(PDO::FETCH_NUM)===[['1','2',null,'']], 'driver preserves duplicate labels, null and empty cells');
$check($driver->query($sql)->fetchColumn(1)==='2', 'fetchColumn uses ordinal');
$check($driver->query($sql)->fetch(PDO::FETCH_ASSOC)===['same'=>'2','absent'=>null,'empty_value'=>''], 'associative duplicate labels retain the last value');
$check($wpdb->get_row($sql,ARRAY_N)===['1','2',null,''], 'wpdb ARRAY_N row preserves ordinals');
$check($wpdb->get_results($sql,ARRAY_N)===[['1','2',null,'']], 'wpdb ARRAY_N results preserve ordinals');
$check($wpdb->get_var(null,0)==='1' && $wpdb->get_var(null,1)==='2' && $wpdb->get_var(null,2)===null && $wpdb->get_var(null,3)===null, 'wpdb get_var reuses ordered cells and normalizes empty strings');
$check($wpdb->get_col("SELECT 1 AS same,2 AS same UNION ALL SELECT 3,4",1)===['2','4'], 'get_col uses ordinal across rows');
$check($wpdb->get_row($sql,ARRAY_A)===['same'=>'2','absent'=>null,'empty_value'=>''], 'wpdb named columns retain last value');
$check($wpdb->last_result[0]->same==='2', 'public last_result remains ordinary objects');
$check($wpdb->get_row(null,ARRAY_N)===null && $wpdb->get_results(null,ARRAY_N)===null, 'null-query row/results semantics stay unchanged');
$wpdb->flush();$check($wpdb->get_var(null,1)===null,'flush drops positional results');
$check($wpdb->get_row('SELECT 1 AS first_value,2 AS second_value',ARRAY_N)===['1','2'], 'ordinary unique column labels stay ordered');
$counts=$wpdb->get_row("SELECT COUNT(*),COUNT(NULLIF(option_id>0,false)) FROM {$wpdb->options}",ARRAY_N);
$check(count($counts)===2 && $counts[0]===$counts[1], 'unaliased aggregate columns remain distinct');
$warnings=[];
set_error_handler(static function($severity,$message)use(&$warnings){$warnings[]=$severity;return true;});
try { $time=count_users('time');$memory=count_users('memory'); } finally { restore_error_handler(); }
// The memory strategy also counts direct/legacy per-user capabilities as roles.
// The SQL strategy intentionally counts only registered roles plus 'none'.
$memory['avail_roles']=array_intersect_key($memory['avail_roles'],wp_roles()->get_names()+['none'=>'None']);
ksort($time['avail_roles']);ksort($memory['avail_roles']);
$check($warnings===[] && $time===$memory, 'core SQL user counts match memory strategy without warnings');
$config=(new ReflectionProperty($driver,'nativeConfig'))->getValue($driver);
if($config->valueCodec) {
    $value="a\0b";
    $binary=$wpdb->get_row($wpdb->prepare("SELECT CAST('A' AS BINARY) AS same,%s AS same",$value),ARRAY_N);
    $check($binary===['A',$value],'duplicate binary/text labels decode by ordinal and preserve NUL bytes');
}
$check($wpdb->dsql_error_count===0,'no adapter errors');
echo json_encode(['checks'=>$checks,'count'=>count($checks),'engine'=>DsqlNativeEngine::engineVersion()],JSON_THROW_ON_ERROR)."\n";
