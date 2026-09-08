<?php
/** Real synthetic DSQL integration through the standalone engine, with no WordPress bootstrap. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
use WPDSQL\Engine\{Config,Driver,QueryException};
$root = dirname(__DIR__, 2);
$cluster = json_decode(file_get_contents($root.'/.local/cluster.json'), true, flags: JSON_THROW_ON_ERROR);
$settings = json_decode(file_get_contents($root.'/.local/settings.json'), true, flags: JSON_THROW_ON_ERROR);
if (($cluster['tags']['Purpose'] ?? '') !== 'synthetic-wordpress-compatibility'
    || $settings['endpoint'] !== $cluster['identifier'].'.dsql.'.$settings['region'].'.on.aws') {
    throw new RuntimeException('Synthetic test cluster configuration required');
}
$driver = new Driver(new Config(host:$settings['endpoint'], region:$settings['region'], profile:$settings['profile'], tablePrefix:'engine_contract_'));
$checks = 0;
function engine_check(bool $ok, string $name): void { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks++; }
$table = 'engine_contract_items';
try {
    engine_check(!class_exists('wpdb', false) && !defined('ABSPATH'), 'No WordPress bootstrap');
    $driver->query("DROP TABLE IF EXISTS $table");
    try { $driver->query("SELECT name FROM $table"); throw new RuntimeException('Missing-table read succeeded'); } catch (QueryException $e) {}
    engine_check($driver->query("CREATE TABLE $table (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,name varchar(60) NOT NULL,note longtext NULL,stamp datetime NOT NULL DEFAULT '0000-00-00 00:00:00',UNIQUE KEY name_key(name))")->command, 'Create command contract');
    $insert = $driver->query("INSERT INTO $table (name,note) VALUES ('first','Synthetic 🌍')");
    engine_check($insert->rowCount() === 1 && $insert->insertId > 0, 'Affected rows and identity');
    $result = $driver->query("SELECT name,note,stamp FROM $table ORDER BY id");
    engine_check($result->columnCount() === 3 && $result->getColumnMeta(1)['name'] === 'note', 'Result metadata');
    engine_check($result->fetch(PDO::FETCH_NUM) === ['first','Synthetic 🌍','0000-00-00 00:00:00'], 'Decoded values and numeric fetch');
    engine_check($result->fetch() === false, 'Result exhaustion');
    $upsert = $driver->query("INSERT INTO $table(name,note) VALUES ('first','changed') ON DUPLICATE KEY UPDATE note=VALUES(note)");
    engine_check($upsert->rowCount() === 1, 'Documented DSQL upsert count');
    engine_check($driver->query("SELECT note FROM $table WHERE name='first'")->fetchColumn() === 'changed', 'Upsert effects');
    $columns = $driver->query("SHOW FULL COLUMNS FROM $table")->fetchAll();
    engine_check(array_column($columns, 'Field') === ['id','name','note','stamp'], 'Column metadata order');
    engine_check($columns[1]['Type'] === 'varchar(60)', 'Column length metadata');
    $indexes = $driver->query("SHOW INDEX FROM $table")->fetchAll();
    engine_check(in_array('name', array_column($indexes, 'Column_name'), true), 'Index metadata');
    $driver->query('BEGIN'); $driver->query("INSERT INTO $table(name) VALUES ('rollback')"); $driver->query('ROLLBACK');
    engine_check($driver->query("SELECT COUNT(*) FROM $table WHERE name='rollback'")->fetchColumn() === '0', 'Caller transaction rollback');
    $driver->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
    $value = "quoted ' and \\ Unicode 🌍";
    engine_check($driver->query("SELECT '".$driver->escape($value)."'")->fetchColumn() === $value, 'Escaping follows session mode');
    $driver->query("SET SESSION sql_mode=''");
    $driver->query("SELECT 'shape-one'"); $driver->query("SELECT 'shape-two'");
    engine_check($driver->cacheStats()['hits'] > 0, 'Reusable translation plans');
    engine_check(!class_exists('wpdb', false) && !defined('ABSPATH'), 'Execution and DDL remain independent of WordPress');
    echo "PASS $checks standalone DSQL engine contracts\n";
} finally { $driver->query("DROP TABLE IF EXISTS $table"); $driver->close(); }
