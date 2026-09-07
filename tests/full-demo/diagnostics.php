<?php
require_once dirname(__DIR__, 2) . '/pg4wp/dsql/class-dsql-diagnostics.php';
function diagnostic_check(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}
$a = "SELECT * FROM wp_options WHERE option_value='secret-one' AND option_id=123 /* private comment */";
$b = "select * from wp_options where option_value='secret-two' and option_id=456 -- other comment\n";
diagnostic_check(DSQL_Diagnostics::normalize($a) === DSQL_Diagnostics::normalize($b), 'Values and comments share one fingerprint');
foreach (["SELECT 'unterminated-private", 'SELECT $$private dollar data$$', 'SELECT $tag$private data$tag$', "SELECT 'private\\'escaped'", 'SELECT "private"', 'SELECT 0xdeadbeef', 'SELECT /* private unfinished'] as $query) {
    diagnostic_check(!str_contains(strtolower(DSQL_Diagnostics::normalize($query)), 'private'), 'Quoted and malformed values excluded');
}
$root = dirname(__DIR__, 2);
require $root . '/.local/full-target/wp-load.php';
if (wp_get_environment_type() !== 'local') { throw new RuntimeException('Local synthetic demo required'); }
$log = tempnam(sys_get_temp_dir(), 'dsql-diagnostics-');
chmod($log, 0600);
$previousLog = ini_set('error_log', $log);
$previousSuppress = $wpdb->suppress_errors(true);
$previousShow = $wpdb->show_errors(true);
try {
    $before = $wpdb->dsql_error_count;
    ob_start();
    $failedRead = $wpdb->query("SELECT * FROM wp_dsql_missing_diagnostic_table WHERE secret='DO_NOT_LOG_QUERY_VALUE'");
    $failedWrite = $wpdb->insert($wpdb->options, ['option_name' => "DO_NOT_LOG\0INDEXED", 'option_value' => 'DO_NOT_LOG_SERIALIZED', 'autoload' => 'off']);
    $display = ob_get_clean();
    diagnostic_check($failedRead === false && $failedWrite === false, 'Database and translator failures return false');
    diagnostic_check($display === '', 'Raw SQL never rendered even with show_errors');
    diagnostic_check($wpdb->dsql_error_count === $before + 2, 'Suppressed failures still counted');
    $events = array_slice($wpdb->dsql_errors, -2);
    diagnostic_check($events[0]['sqlstate'] === '42P01' && $events[0]['stage'] === 'execute', 'Database SQLSTATE and execution stage captured');
    diagnostic_check($events[1]['kind'] === 'adapter' && $events[1]['stage'] === 'translate', 'Pre-database translation rejection captured');
    $text = file_get_contents($log);
    diagnostic_check(substr_count($text, 'wordpress_dsql_error') === 2, 'Exactly one durable event per failure');
    diagnostic_check(!str_contains($text, 'DO_NOT_LOG') && !str_contains($text, 'wp_dsql_missing_diagnostic_table') && !str_contains($text, 'DETAIL'), 'Log excludes values, raw SQL and exception details');
    diagnostic_check($wpdb->query('SELECT 1 AS ok') === 1 && $wpdb->dsql_error_count === $before + 2, 'Successful query recovers without false error event');
    // Manual print_error is also safe and retention is bounded for CLI workers.
    for ($i = 0; $i < 101; $i++) { $wpdb->print_error('DO_NOT_LOG_MESSAGE'); }
    diagnostic_check(count($wpdb->dsql_errors) === 100 && $wpdb->dsql_error_count === $before + 103, 'Bounded in-memory history retains total error count');
    diagnostic_check(!str_contains(file_get_contents($log), 'DO_NOT_LOG'), 'Manual error reporting excludes exception text');
} finally {
    ini_set('error_log', $previousLog);
    $wpdb->suppress_errors($previousSuppress);
    $wpdb->show_errors($previousShow);
    unlink($log);
}
