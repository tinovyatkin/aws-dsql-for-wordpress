<?php
/** auto_prepend_file for the synthetic WordPress smoke test only. */
$root = dirname(__DIR__, 2);
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== $root . '/tests/dsql/smoke.php') {
    throw new RuntimeException('Capture is limited to the synthetic smoke test');
}
$queries = [];
$GLOBALS['wp_filter']['query'][PHP_INT_MAX][] = [
    'function' => static function (string $sql) use (&$queries): string {
        if (!defined('WP_ENVIRONMENT_TYPE') || WP_ENVIRONMENT_TYPE !== 'local') {
            throw new RuntimeException('Synthetic local environment required');
        }
        $queries[] = $sql;
        return $sql;
    },
    'accepted_args' => 1,
];
register_shutdown_function(static function () use (&$queries, $root): void {
    $file = $root . '/.local/parser-evaluation/wordpress-queries.json';
    file_put_contents($file, json_encode($queries, JSON_THROW_ON_ERROR));
    chmod($file, 0600);
});
