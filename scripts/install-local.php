<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('WP_INSTALLING', true);
require dirname(__DIR__) . '/.local/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (is_blog_installed()) { echo "Already installed.\n"; exit; }
$wpdb->dsql_errors = [];
$wpdb->defer_index_wait = true;
$result = wp_install('DSQL WordPress Laboratory', 'dsqltest', 'dsqltest@example.invalid', false, '', trim(file_get_contents(dirname(__DIR__) . '/.local/admin-password')));
$wpdb->wait_for_indexes();
file_put_contents(dirname(__DIR__) . '/.local/install-errors.json', json_encode($wpdb->dsql_errors, JSON_PRETTY_PRINT));
echo json_encode(['user_id'=>$result['user_id']??null,'url'=>$result['url']??null,'errors'=>count($wpdb->dsql_errors),'queries'=>$wpdb->num_queries]), "\n";
exit($wpdb->dsql_errors || empty($result['user_id']) || !is_blog_installed() ? 1 : 0);
