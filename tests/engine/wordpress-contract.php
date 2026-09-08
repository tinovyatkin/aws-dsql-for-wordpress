<?php
/** WordPress contracts informed by upstream WP_SQLite_DB_Tests; see tests/engine/README.md. */
require dirname(__DIR__, 2).'/.local/wordpress/wp-load.php';
if (wp_get_environment_type() !== 'local' || $wpdb->prefix !== 'dsqlwp_') { throw new RuntimeException('Synthetic WordPress fixture required'); }
use WPDSQL\Engine\Driver;
$db = new class($wpdb->get_driver()) extends DSQL_WPDB {
    public function __construct(Driver $driver) { $this->dbh=$driver; $this->ready=true; $this->dbname='postgres'; }
    public function columnInfo(): array { $this->load_col_info(); return $this->col_info; }
};
$checks=0;
function contract_check(bool $ok, string $name): void { global $checks,$db; if (!$ok) { throw new RuntimeException($name.': '.$db->last_error); } $checks++; }
contract_check($db->get_driver() === $wpdb->get_driver(), 'Driver access');
contract_check($db->db_version() === Driver::MYSQL_VERSION && $db->db_server_info() !== '', 'Version contract');
contract_check($db->has_cap('UTF8MB4') && !$db->has_cap('unknown'), 'Capability normalization');
foreach (["\0"=>'\\0',"\n"=>'\\n',"\r"=>'\\r','\\'=>'\\\\',"'"=>"\\'",'"'=>'\\"',"\x1a"=>'\\Z',"\t"=>"\t","\x08"=>"\x08",'Ʈềʂᴛ🙂'=>'Ʈềʂᴛ🙂'] as $input=>$expected) {
    contract_check($db->_real_escape($input) === $expected, 'MySQL escaping');
}
$value="a\\b'c — 🌍";
$sql=$db->prepare('SELECT %s AS value',$value);
contract_check(is_string($sql), 'prepare retains string API');
contract_check($db->get_var($sql) === $value, 'prepare binding round trip');
contract_check($db->translation_cache_stats()['prepared_hits'] > 0, 'Prepared capture reaches engine');
contract_check($db->query("SELECT 1 AS number,'text' AS label") === 1, 'SELECT query return count');
contract_check($db->last_result[0]->number === '1' && $db->num_rows === 1, 'WordPress string-valued object results');
contract_check(array_column($db->columnInfo(),'name') === ['number','label'], 'Result column names');
$db->flush();
contract_check($db->columnInfo() === [] && $db->last_result === [] && $db->last_query === null, 'Flushed result state');
$columns=$db->get_results("SHOW FULL COLUMNS FROM {$wpdb->options}", ARRAY_A);
contract_check(in_array('option_name',array_column($columns,'Field'),true), 'Emulated metadata is available through wpdb');
$db->insert_id=123;
$cancel=static fn($query)=>'';
add_filter('query',$cancel);
try { contract_check($db->query('SELECT 1') === false && $db->insert_id === 0, 'Filtered query resets insert ID'); }
finally { remove_filter('query',$cancel); }
$db->insert_id=123;
contract_check($db->query("INSERT INTO dsqlwp_engine_absent (name) VALUES ('synthetic')") === false && $db->insert_id === 0, 'Failed insert resets ID');
contract_check($db->last_error !== '', 'Failure exposed through wpdb error contract');
contract_check($db->get_var('SELECT 1') === '1' && $db->last_error === '', 'Following query clears error');
$db->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
contract_check($db->_real_escape("a\\b'c") === "a\\b''c", 'Session-sensitive escaping');
$db->query("SET SESSION sql_mode=''");
$db->close();
contract_check($db->db_version() === '' && $db->db_server_info() === '' && $db->columnInfo() === [], 'Closed driver state');
try { $db->get_driver(); throw new LogicException('Closed driver exposed'); } catch (RuntimeException $e) { $checks++; }
echo "PASS $checks WordPress driver contracts\n";
