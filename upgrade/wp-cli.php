<?php
/** Loaded with WP-CLI --require before WordPress/plugins initialize. */
if(PHP_SAPI!=='cli'||!defined('WP_CLI')||!WP_CLI||!getenv('DSQL_UPGRADE_SESSION'))throw new RuntimeException('Controlled CLI session required');
ini_set('zend.exception_ignore_args','1');
if(!defined('WP_ACCESSIBLE_HOSTS'))define('WP_ACCESSIBLE_HOSTS','api.wordpress.org,downloads.wordpress.org');
$installFilter=static function($name,$callback,$arguments=1){
    if(function_exists('add_filter'))add_filter($name,$callback,PHP_INT_MAX,$arguments);
    else $GLOBALS['wp_filter'][$name][PHP_INT_MAX][]=['function'=>$callback,'accepted_args'=>$arguments];
};
$requestFilter=static function($pre,$args,$url){
    $parts=parse_url($url);
    if(($parts['scheme']??'')==='https'&&!isset($parts['user'])&&!isset($parts['pass'])&&in_array($parts['host']??'',['api.wordpress.org','downloads.wordpress.org'],true))return false;
    return new WP_Error('dsql_upgrade_offline','Provider requests are paused during controlled upgrades.');
};
$installFilter('pre_wp_mail',static fn()=>true);
$installFilter('pre_http_request',$requestFilter,3);
$finalize=static function()use($requestFilter){
    error_reporting(error_reporting()&~E_DEPRECATED&~E_USER_DEPRECATED);
    add_filter('file_mod_allowed','__return_true',PHP_INT_MAX);
    add_filter('automatic_updater_disabled','__return_true',PHP_INT_MAX);
    remove_filter('pre_http_request',$requestFilter,PHP_INT_MAX);
    add_filter('pre_http_request',$requestFilter,PHP_INT_MAX,3);
    if(!defined('FS_METHOD'))define('FS_METHOD','direct');
};
$installFilter('wp_loaded',$finalize,0);
WP_CLI::add_command('dsql-upgrade-verify',static function(){
    global $wpdb;
    $report=$wpdb->verify_schema_upgrade();
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    $changes=dbDelta(wp_get_db_schema(),false);
    if($changes)WP_CLI::error('Core dbDelta still proposes changes; run core update-db or reconcile before finishing');
    if($wpdb->dsql_error_count)WP_CLI::error('Database errors occurred during verification');
    $session=\WPDSQLUpgrade\Context::$session;
    $session->data['verified']=true;$session->data['verified_at']=gmdate('c');$session->save();
    WP_CLI::success('Schema catalog verified; core dbDelta has no pending changes ('.$report['catalogued_tables'].' tables).');
});
