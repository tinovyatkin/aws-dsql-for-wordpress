<?php
$root=dirname(__DIR__,2);require $root.'/.local/full-target/wp-load.php';
$expected=json_decode(file_get_contents($root.'/.local/full-demo/external-report.json'),true);
// Explicit invalidation is necessary for external writes with a persistent cache.
clean_post_cache($expected['post_id']);clean_comment_cache($expected['comment_id']);wp_cache_delete($expected['post_id'],'post_meta');
if(base64_encode(get_post_meta($expected['post_id'],'_dsql_external_binary',true))!==$expected['expected_base64'])throw new RuntimeException('External binary value mismatch');
if(!str_contains(get_comment($expected['comment_id'])->comment_content,'External Node.js'))throw new RuntimeException('External comment missing');
if($wpdb->dsql_errors)throw new RuntimeException('Database errors');
echo "PASS WordPress reads the external comment and binary metadata after scoped Redis invalidation\n";
