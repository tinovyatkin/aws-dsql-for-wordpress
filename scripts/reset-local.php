<?php
// This only resets the synthetic fixture identified by the local cluster record.
if (($argv[1] ?? '') !== '--reset-synthetic-fixture') { exit("Pass --reset-synthetic-fixture\n"); }
require __DIR__ . '/connection.php';
$p = dsql_test_connection();
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
$catalog=new DSQL_Schema_Catalog($p);
if($catalog->exists()&&$catalog->managed())throw new RuntimeException('Managed restored catalog: refusing synthetic installation reset');
$tables = $p->query("SELECT tablename FROM pg_tables WHERE schemaname='public'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    if ($table!==DSQL_Schema_Catalog::TABLE && !str_starts_with($table, 'dsqlwp_')) { throw new RuntimeException('Unexpected table: refusing reset'); }
}
if($catalog->exists())foreach($catalog->names() as $name)if(!str_starts_with($name,'dsqlwp_'))throw new RuntimeException('Catalog contains another installation: refusing reset');
foreach ($tables as $table) { $p->exec('DROP TABLE "' . str_replace('"','""',$table) . '" CASCADE'); }
echo 'Reset ' . count($tables) . " synthetic tables.\n";
