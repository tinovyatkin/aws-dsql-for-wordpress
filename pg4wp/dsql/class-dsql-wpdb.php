<?php
/** WordPress API adaptation above the standalone MySQL-on-DSQL engine. */
use WPDSQL\Engine\Config;
use WPDSQL\Engine\Driver;
use WPDSQL\Engine\Result;
use WPDSQL\Engine\QueryException;

require_once defined('DSQL_AUTOLOAD') ? DSQL_AUTOLOAD : dirname(__DIR__, 2).'/vendor/autoload.php';
require_once __DIR__.'/class-dsql-diagnostics.php';

class DSQL_WPDB extends wpdb {
    public bool $defer_index_wait = false;
    public array $dsql_errors = [];
    public int $dsql_error_count = 0;

    public function get_driver(): Driver {
        if (!$this->dbh instanceof Driver) { throw new RuntimeException('No active DSQL driver'); }
        return $this->dbh;
    }
    public function db_connect($allow_bail = true) {
        $start = microtime(true);
        try {
            $upgrade = class_exists('WPDSQLUpgrade\\Context', false) ? \WPDSQLUpgrade\Context::$session : null;
            $this->dbh = new Driver(new Config(
                host: $this->dbhost,
                user: $this->dbuser ?: 'admin',
                database: $this->dbname ?: 'postgres',
                region: defined('DSQL_REGION') ? DSQL_REGION : null,
                profile: $upgrade ? ($upgrade->data['target']['profile'] ?? null) : (defined('DSQL_PROFILE') ? DSQL_PROFILE : null),
                credentialsFile: $upgrade->data['target']['credentials_file'] ?? null,
                schema: defined('DSQL_SCHEMA') ? DSQL_SCHEMA : 'public',
                valueCodec: defined('DSQL_VALUE_CODEC') && DSQL_VALUE_CODEC === 'frame-v1',
                sqlMode: defined('DSQL_SQL_MODE') ? DSQL_SQL_MODE : '',
                tablePrefix: $GLOBALS['table_prefix'] ?? 'wp_',
                cacheEnabled: !defined('DSQL_TRANSLATION_CACHE') || DSQL_TRANSLATION_CACHE !== false,
                cacheDirectory: defined('DSQL_TRANSLATION_CACHE_DIR') ? DSQL_TRANSLATION_CACHE_DIR : null,
            ));
            $this->is_mysql = false;
            $this->ready = true;
            $this->has_connected = true;
            $this->init_charset();
            return true;
        } catch (Throwable $error) {
            $this->ready = false;
            $failure = $error instanceof QueryException ? $error->nativeFailure() : $error;
            $this->last_error = $failure->getMessage();
            $event = $this->recordFailure($failure, '', 'connect', $start);
            if ($allow_bail) { throw new RuntimeException('DSQL connection failed; reference '.$event['fingerprint']); }
            return false;
        }
    }
    public function check_connection($allow_bail = true) {
        if (!$this->ready || !$this->dbh instanceof Driver) { return $this->db_connect($allow_bail); }
        try { $this->dbh->checkConnection(); return true; }
        catch (Throwable $error) {
            $failure = $error instanceof QueryException ? $error->nativeFailure() : $error;
            $event = $this->recordFailure($failure, '', 'connect', microtime(true));
            $this->last_error = $failure->getMessage();
            if ($allow_bail) { throw new RuntimeException('DSQL connection failed; reference '.$event['fingerprint']); }
            return false;
        }
    }
    public function close() {
        try {
            if ($this->dbh instanceof Driver) { $this->dbh->close(); }
            return true;
        } finally {
            $this->dbh = null;
            $this->ready = false;
            $this->flush();
        }
    }
    public function init_charset() { $this->charset = 'utf8mb4'; $this->collate = ''; }
    public function set_charset($dbh, $charset = null, $collate = null) {}
    public function set_sql_mode($modes = []) {}
    public function select($db, $dbh = null) {
        if ($db !== $this->dbname) { throw new RuntimeException('DSQL supports one database per cluster'); }
    }
    public function check_database_version() { return null; }
    public function db_version() { return $this->dbh instanceof Driver ? Driver::MYSQL_VERSION : ''; }
    public function db_server_info() { return $this->dbh instanceof Driver ? $this->dbh->serverInfo() : ''; }
    public function has_cap($cap) { return in_array(strtolower($cap), ['collation','group_concat','subqueries','set_charset','utf8mb4','identifier_placeholders'], true); }
    public function _real_escape($data) {
        if (!is_scalar($data)) { return ''; }
        return $this->add_placeholder_escape($this->get_driver()->escape((string) $data));
    }
    // Full logical-schema column contracts are the next adoption milestone.
    public function get_col_charset($table, $column) { return 'utf8mb4'; }
    public function get_col_length($table, $column) { return false; }
    protected function check_safe_collation($query) { return true; }
    protected function load_col_info() {
        $this->col_info = [];
        if ($this->result instanceof Result) {
            for ($i = 0; $i < $this->result->columnCount(); $i++) {
                $meta = $this->result->getColumnMeta($i);
                $this->col_info[] = (object) ['name'=>$meta['name'], 'type'=>$meta['native_type'] ?? 'text'];
            }
        }
    }
    public function flush() {
        $this->last_result = [];
        $this->col_info = null;
        $this->last_query = null;
        $this->rows_affected = 0;
        $this->num_rows = 0;
        $this->last_error = '';
        $this->result = null;
    }
    public function prepare($query, ...$args) {
        $sql = parent::prepare($query, ...$args);
        if (is_string($sql) && $this->dbh instanceof Driver) {
            $this->dbh->rememberPrepared($this->remove_placeholder_escape($sql));
        }
        return $sql;
    }
    public function translation_cache_stats(): array { return $this->get_driver()->cacheStats(); }
    public function query($query) {
        if (!$this->check_connection()) { return false; }
        $query = apply_filters('query', $query);
        if (!$query) { $this->insert_id = 0; return false; }
        $this->flush();
        $this->last_query = $query;
        $this->func_call = '$db->query("'.$query.'")';
        $start = microtime(true);
        $driver = $this->get_driver();
        $before = $driver->queryCount();
        $operation = '';
        try {
            $this->result = $driver->query($query, $this->defer_index_wait);
            $operation = $this->result->operation;
            $this->last_result = $this->result->fetchAll(PDO::FETCH_OBJ);
            $this->num_rows = count($this->last_result);
            $this->rows_affected = $this->result->rowCount();
            if (in_array($operation, ['INSERT','REPLACE'], true)) {
                $this->insert_id = $this->result->insertId;
                // WordPress installs its default category with an explicit ID.
                if (defined('WP_INSTALLING') && WP_INSTALLING
                    && preg_match('/^INSERT\s+INTO\s+[\x60"]?(\w+)[\x60"]?\s*\(([^)]+)\)/i', $query, $insert)
                    && $insert[1] === $this->terms && preg_match('/\bterm_id\b/', $insert[2])) {
                    $driver->reseedIdentity($this->terms, 'term_id');
                }
            }
            if (defined('SAVEQUERIES') && SAVEQUERIES) {
                $this->log_query($query, microtime(true) - $start, $this->get_caller(), $start, []);
            }
            if ($this->result->command) { return true; }
            return in_array($operation, ['INSERT','REPLACE','UPDATE','DELETE'], true) ? $this->rows_affected : $this->num_rows;
        } catch (Throwable $error) {
            $failure = $error instanceof QueryException ? $error->nativeFailure() : $error;
            if ($error instanceof QueryException) { $operation = $error->operation; }
            $this->last_error = $failure->getMessage();
            if (in_array($operation, ['INSERT','REPLACE'], true)) { $this->insert_id = 0; }
            $this->recordFailure($failure, $query, $error instanceof QueryException ? $error->stage : 'execute', $start,
                $error instanceof QueryException ? $error->retries : 0);
            if ($error instanceof QueryException && $error->controlledUpgrade) { throw new RuntimeException($error->getMessage()); }
            return false;
        } finally {
            $this->num_queries += $driver->queryCount() - $before;
        }
    }
    public function enable_schema_upgrade(\WPDSQLUpgrade\Session $session): void { $this->get_driver()->enableSchemaUpgrade($session); }
    public function verify_schema_upgrade(): array { return $this->get_driver()->verifySchemaUpgrade(); }
    public function wait_for_indexes(): void { $this->get_driver()->waitForIndexes(); }
    private function recordFailure(Throwable $error, string $query, string $stage, float $started, int $retries = 0): array {
        $event = DSQL_Diagnostics::event($error, $query, $stage, $started, $retries);
        $this->dsql_error_count++;
        if (count($this->dsql_errors) >= 100) { array_shift($this->dsql_errors); }
        $this->dsql_errors[] = $event;
        DSQL_Diagnostics::emit($event);
        return $event;
    }
    public function print_error($str = '') {
        $this->recordFailure(new RuntimeException('Reported database failure'), (string) $this->last_query, 'reported', microtime(true));
        return false;
    }
}
