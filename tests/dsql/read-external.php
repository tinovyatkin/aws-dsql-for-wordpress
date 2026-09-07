<?php
// A fresh WordPress process, without a persistent object cache.
require dirname(__DIR__, 2) . '/.local/wordpress/wp-load.php';
$expected = json_decode(file_get_contents(dirname(__DIR__, 2) . '/.local/external-writer.json'), true);
$comment = get_comment($expected['comment_id']);
if (!$comment || $comment->comment_content !== $expected['message'] || (int)$comment->comment_post_ID !== $expected['post_id']) {
    throw new RuntimeException('External comment did not round-trip through WordPress');
}
if ((int) get_post($expected['post_id'])->comment_count !== (int) get_comments(['post_id'=>$expected['post_id'],'status'=>'approve','count'=>true])) {
    throw new RuntimeException('Comment count mismatch');
}
if ($wpdb->dsql_errors) { throw new RuntimeException('Database errors while reading external comment'); }
echo "PASS WordPress reads the native comment written by Node.js, including Unicode and the updated count\n";
