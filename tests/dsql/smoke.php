<?php
/** Exercise WordPress APIs against the real DSQL fixture; never a production site. */
require dirname(__DIR__, 2) . '/.local/wordpress/wp-load.php';
if (wp_get_environment_type() !== 'local' || $wpdb->prefix !== 'dsqlwp_') { throw new RuntimeException('Synthetic local fixture required'); }
$checks = [];
function check($condition, string $label): void {
    global $checks, $wpdb;
    $checks[] = ['check' => $label, 'passed' => (bool) $condition];
    if (!$condition) { throw new RuntimeException($label . ': ' . $wpdb->last_error); }
}
$wpdb->dsql_errors = [];
try {
    check($wpdb instanceof DSQL_WPDB, 'WordPress uses AWS DSQL PDO drop-in');
    check(is_blog_installed(), 'Core installation is persistent');
    $user = wp_authenticate('dsqltest', trim(file_get_contents(dirname(__DIR__, 2) . '/.local/admin-password')));
    check($user instanceof WP_User, 'Administrator authentication');
    wp_set_current_user($user->ID);
    $text = "A traveller’s ID — SELECT, UPDATE, KEY; O'Reilly \\ roads 🌍\nРусский текст; 100%_real";
    $payload = ['text' => $text, 'nested' => ['ID' => 'David', 'value' => 'ON DUPLICATE KEY UPDATE'], 'number' => 42];
    update_option('dsql_smoke_option', $payload, false);
    check(get_option('dsql_smoke_option') === $payload, 'Serialized option and UTF-8 round trip');
    $payload['number']++;
    update_option('dsql_smoke_option', $payload, false);
    check(get_option('dsql_smoke_option') === $payload, 'Option update');
    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", 'dsql_smoke_upsert', 'first', 'off'));
    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)", 'dsql_smoke_upsert', 'second', 'off'));
    check($wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name='dsql_smoke_upsert'") === 'second', 'Option upsert updates the unique option name');
    $postId = wp_insert_post(['post_title'=>'DSQL compatibility story', 'post_content'=>wp_slash($text), 'post_status'=>'draft', 'post_author'=>$user->ID], true);
    check(is_int($postId) && $postId > 0, 'Create draft and return identity');
    clean_post_cache($postId);
    check(get_post($postId)->post_content === $text, 'Article text survives uncached database read');
    update_post_meta($postId, '_dsql_smoke_meta', wp_slash($payload));
    wp_cache_delete($postId, 'post_meta');
    check(get_post_meta($postId, '_dsql_smoke_meta', true) === $payload, 'Serialized post metadata round trip');
    $term = wp_insert_term('DSQL laboratory ' . $postId, 'category');
    check(!is_wp_error($term), 'Create taxonomy term');
    $assigned = wp_set_post_terms($postId, [$term['term_id']], 'category');
    check(!is_wp_error($assigned) && count($assigned) === 1, 'Assign taxonomy relationship');
    check(wp_update_post(['ID'=>$postId, 'post_status'=>'publish'], true) === $postId, 'Publish draft');
    $query = new WP_Query(['post_type'=>'post','s'=>'compatibility','posts_per_page'=>1]);
    check($query->post_count === 1 && $query->found_posts >= 1, 'Search and pagination FOUND_ROWS');
    $comment = wp_insert_comment(wp_slash(['comment_post_ID'=>$postId,'comment_author'=>'Synthetic Reader','comment_author_email'=>'reader@example.invalid','comment_content'=>$text,'comment_approved'=>1]));
    check($comment > 0, 'Insert native comment and return identity');
    clean_comment_cache($comment);
    check(get_comment($comment)->comment_content === $text, 'Native comment text round trip');
    check((int) get_post($postId)->comment_count === 1, 'Comment count maintained by WordPress');
    $rest = rest_get_server()->dispatch(new WP_REST_Request('GET', '/wp/v2/posts/' . $postId));
    check($rest->get_status() === 200 && $rest->get_data()['id'] === $postId, 'REST API reads published post');
    $create = new WP_REST_Request('POST', '/wp/v2/posts');
    $create->set_body_params(['title'=>'REST editor draft', 'content'=>'Written through the REST API.', 'status'=>'draft']);
    $created = rest_get_server()->dispatch($create);
    check($created->get_status() === 201, 'REST API creates editor draft');
    $restId = $created->get_data()['id'];
    $publish = new WP_REST_Request('POST', '/wp/v2/posts/' . $restId);
    $publish->set_body_params(['status'=>'publish']);
    check(rest_get_server()->dispatch($publish)->get_status() === 200, 'REST API publishes editor draft');
    $delete = new WP_REST_Request('DELETE', '/wp/v2/posts/' . $restId);
    $delete->set_param('force', true);
    check(rest_get_server()->dispatch($delete)->get_status() === 200 && get_post($restId) === null, 'REST API permanently deletes synthetic post');
    update_option('dsql_smoke_post_id', $postId, false);
    check(delete_option('dsql_smoke_option'), 'Delete option');
    check(wp_trash_post($postId) instanceof WP_Post, 'Trash post');
    check(wp_untrash_post($postId) instanceof WP_Post, 'Restore post');
    wp_update_post(['ID'=>$postId,'post_status'=>'publish']);
    check(count($wpdb->dsql_errors) === 0, 'No database errors during WordPress API tests');
} catch (Throwable $e) {
    $checks[] = ['failure' => $e->getMessage()];
}
$report = ['wordpress'=>$wp_version, 'php'=>PHP_VERSION, 'checks'=>$checks, 'db_errors'=>$wpdb->dsql_errors, 'post_id'=>$postId??null];
file_put_contents(dirname(__DIR__, 2) . '/.local/smoke-results.json', json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
foreach ($checks as $check) { echo isset($check['check']) ? (($check['passed']?'PASS':'FAIL') . ' ' . $check['check']) : ('FAIL ' . $check['failure']); echo "\n"; }
exit(isset($check['failure']) || !empty($wpdb->dsql_errors) ? 1 : 0);
