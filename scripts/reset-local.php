<?php
// This only resets the synthetic fixture identified by the local cluster record.
if (($argv[1] ?? '') !== '--reset-synthetic-fixture') { exit("Pass --reset-synthetic-fixture\n"); }
require __DIR__ . '/connection.php';
$p = dsql_test_connection();
$tables = $p->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    if (!str_starts_with($table, 'dsqlwp_')) { throw new RuntimeException('Unexpected table: refusing reset'); }
}
foreach ($tables as $table) { $p->exec('DROP TABLE "' . str_replace('"','""',$table) . '" CASCADE'); }
echo 'Reset ' . count($tables) . " synthetic tables.\n";
