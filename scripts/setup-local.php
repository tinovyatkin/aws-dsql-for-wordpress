<?php
/** Build a synthetic local fixture without copying production data or AWS secrets. */
$root = dirname(__DIR__);
$local = $root . '/.local';
$cluster = json_decode(file_get_contents($local . '/cluster.json'), true, 512, JSON_THROW_ON_ERROR);
$region = getenv('AWS_REGION') ?: 'eu-central-1';
$previous = is_file($local . '/settings.json') ? json_decode(file_get_contents($local . '/settings.json'), true) : [];
$profile = getenv('AWS_PROFILE') ?: ($previous['profile'] ?? 'default');
$host = $cluster['identifier'] . '.dsql.' . $region . '.on.aws';
file_put_contents($local . '/settings.json', json_encode(['endpoint'=>$host,'region'=>$region,'profile'=>$profile], JSON_PRETTY_PRINT));
$config = "<?php\n";
foreach (['DB_DRIVER'=>'dsql','DB_HOST'=>$host,'DB_USER'=>'admin','DB_PASSWORD'=>'','DB_NAME'=>'postgres','DB_CHARSET'=>'utf8mb4','DSQL_PROFILE'=>$profile,'DSQL_REGION'=>$region,'DSQL_AUTOLOAD'=>$root.'/vendor/autoload.php','PG4WP_ROOT'=>$root.'/pg4wp','WP_HOME'=>'http://127.0.0.1:9417','WP_SITEURL'=>'http://127.0.0.1:9417','WP_DEBUG'=>true,'WP_DEBUG_DISPLAY'=>false,'WP_DEBUG_LOG'=>$local.'/wordpress-errors.log','DISABLE_WP_CRON'=>true,'WP_ENVIRONMENT_TYPE'=>'local'] as $k=>$v) {
    $config .= 'define(' . var_export($k,true) . ', ' . var_export($v,true) . ");\n";
}
foreach (['AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $k) {
    $config .= 'define(' . var_export($k,true) . ', ' . var_export(bin2hex(random_bytes(32)),true) . ");\n";
}
$config .= '$table_prefix = "dsqlwp_";' . "\n";
$config .= 'if (!defined("ABSPATH")) { define("ABSPATH", __DIR__ . "/"); }' . "\n" . 'require_once ABSPATH . "wp-settings.php";' . "\n";
file_put_contents($local . '/wordpress/wp-config.php', $config);
chmod($local . '/wordpress/wp-config.php',0600);
// PG4WP_ROOT is explicit for the development symlink layout.
copy($root . '/pg4wp/db.php', $local . '/wordpress/wp-content/db.php');
@mkdir($local . '/wordpress/wp-content/mu-plugins',0755,true);
file_put_contents($local . '/wordpress/wp-content/mu-plugins/local-fixture.php', "<?php\nadd_filter('pre_wp_mail', '__return_false');\n");
if (!file_exists($local . '/admin-password')) {
    file_put_contents($local . '/admin-password', bin2hex(random_bytes(20)));
    chmod($local . '/admin-password',0600);
}
echo "Local synthetic WordPress configured on port 9417.\n";
