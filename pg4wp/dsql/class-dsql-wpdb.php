<?php
/**
 * WordPress database drop-in using AWS's Aurora DSQL PHP PDO connector.
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
use Aws\AuroraDsql\PdoPgsql\AuroraDsql;
use Aws\AuroraDsql\PdoPgsql\DsqlConfig;
use Aws\AuroraDsql\PdoPgsql\OCCRetry;

$autoload = defined('DSQL_AUTOLOAD') ? DSQL_AUTOLOAD : dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once $autoload;
require_once __DIR__ . '/class-dsql-sql.php';
require_once __DIR__ . '/class-dsql-schema-catalog.php';
require_once __DIR__ . '/class-dsql-diagnostics.php';

class DSQL_WPDB extends wpdb {
    private DSQL_SQL $translator;
    private bool $valueCodec=false;
    private string $schema='public';
    private DSQL_Schema_Catalog $schemaCatalog;
    private float $connectedAt = 0;
    private array $indexJobs = [];
    public bool $defer_index_wait = false;
    public array $dsql_errors = [];
    public int $dsql_error_count = 0;
    private int $queryRetries = 0;

    public function db_connect($allow_bail = true) {
        $start = microtime(true);
        try {
            $profile = defined('DSQL_PROFILE') ? DSQL_PROFILE : null;
            $provider = null;
            if ($profile) {
                // Connector 0.1.1 passes "profile" to defaultProvider(), which
                // the PHP SDK ignores for shared profiles. Use its client resolver.
                $sdkClient = new \Aws\DSQL\DSQLClient([
                    'version' => 'latest', 'region' => defined('DSQL_REGION') ? DSQL_REGION : 'us-east-1',
                    'profile' => $profile,
                ]);
                $provider = static fn() => $sdkClient->getCredentials();
            }
            $config = new DsqlConfig(
                host: $this->dbhost,
                user: $this->dbuser ?: 'admin',
                database: $this->dbname ?: 'postgres',
                region: defined('DSQL_REGION') ? DSQL_REGION : null,
                credentialsProvider: $provider,
                occMaxRetries: 3,
            );
            $this->dbh = AuroraDsql::connect($config, [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => true,
            ]);
            $this->schema=defined('DSQL_SCHEMA')?DSQL_SCHEMA:'public';
            if(!in_array($this->schema,['public','wp_live'],true)) throw new RuntimeException('Unsupported application schema');
            $this->dbh->exec('SET search_path TO "'.$this->schema.'", pg_catalog');
            $this->valueCodec=defined('DSQL_VALUE_CODEC') && DSQL_VALUE_CODEC==='frame-v1';
            $this->translator = new DSQL_SQL($this->dbh,$this->valueCodec,$this->schema);
            $this->schemaCatalog = new DSQL_Schema_Catalog($this->dbh);
            $this->connectedAt = microtime(true);
            $this->is_mysql = false;
            $this->ready = true;
            $this->has_connected = true;
            $this->init_charset();
            return true;
        } catch (Throwable $e) {
            $this->ready = false;
            $this->last_error = $e->getMessage();
            $event = $this->recordFailure($e, '', 'connect', $start);
            // An uncaught PDO/SDK exception can contain credentials or SQL DETAIL.
            if ($allow_bail) { throw new RuntimeException('DSQL connection failed; reference ' . $event['fingerprint']); }
            return false;
        }
    }

    public function init_charset() { $this->charset = 'utf8mb4'; $this->collate = ''; }
    public function set_charset($dbh, $charset = null, $collate = null) {}
    public function set_sql_mode($modes = []) {}
    public function select($db, $dbh = null) {
        if ($db !== $this->dbname) { throw new RuntimeException('DSQL supports one database per cluster'); }
    }
    public function check_connection($allow_bail = true) {
        return $this->ready && microtime(true) - $this->connectedAt < 3300
            ? true : $this->db_connect($allow_bail);
    }
    public function close() { $this->dbh = null; $this->ready = false; return true; }
    public function check_database_version() { return null; }
    public function db_version() { return '8.0.17'; } // MySQL dialect: integer display widths have no storage meaning.
    public function db_server_info() { return 'Aurora DSQL (PostgreSQL-compatible)'; }
    public function has_cap($cap) { return in_array($cap, ['collation', 'group_concat', 'subqueries', 'set_charset', 'utf8mb4', 'identifier_placeholders'], true); }
    public function _real_escape($data) {
        if (!is_scalar($data)) { return ''; }
        return $this->add_placeholder_escape(strtr((string) $data, [
            '\\' => '\\\\', "'" => "\\'", '"' => '\\"', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z',
        ]));
    }
    public function get_col_charset($table, $column) { return 'utf8mb4'; }
    public function get_col_length($table, $column) { return false; }
    protected function check_safe_collation($query) { return true; }
    protected function load_col_info() {
        $this->col_info = [];
        if ($this->result instanceof PDOStatement) {
            for ($i = 0; $i < $this->result->columnCount(); $i++) {
                $meta = $this->result->getColumnMeta($i);
                $this->col_info[] = (object) ['name' => $meta['name'], 'type' => $meta['native_type'] ?? 'text'];
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

    public function query($query) {
        if (!$this->check_connection()) { return false; }
        $query = apply_filters('query', $query);
        if (!$query) { return false; }
        $this->flush();
        $this->last_query = $query;
        $start = microtime(true);
        $stage = 'metadata';
        $this->queryRetries = 0;
        try {
            if (preg_match('/^\s*(?:ALTER\s+TABLE|DROP\s+TABLE)(?:\s+IF\s+EXISTS)?\s+[\x60"]?(\w+)/i', $query, $ddl)
                && $this->schemaCatalog->get($ddl[1])) {
                throw new RuntimeException('Schema changes on restored tables require a migration-aware upgrade; refusing to make the saved schema metadata stale');
            }
            $emulated = $this->metadataQuery($query);
            $rows = [];
            $rowTypes = [];
            if ($emulated !== null) {
                $rows = $emulated;
                $this->num_queries++;
            } else {
                if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query)) {
                    $this->wait_for_indexes();
                }
                // MySQL SET NAMES/sql_mode are driver configuration, not DSQL settings.
                if (preg_match('/^\s*SET\s+(?:NAMES|(?:SESSION\s+)?sql_mode)\b/i', $query)) {
                    return true;
                }
                $stage = 'translate';
                $statements = $this->translator->translate($query);
                $stage = 'execute';
                foreach ($statements as $sql) {
                    $stmt = $this->execute($sql);
                    $this->result = $stmt;
                    if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+ASYNC\b/i', $sql)) {
                        $job = $stmt->fetchColumn();
                        if ($job) { $this->indexJobs[] = $job; }
                    } else {
                        for ($i=0; $i<$stmt->columnCount(); $i++) {
                            $meta=$stmt->getColumnMeta($i);
                            $rowTypes[$meta['name']]=$meta['native_type']??'';
                        }
                        $rows = $stmt->columnCount() ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                        $this->rows_affected += $stmt->rowCount();
                    }
                }
                if (!$this->defer_index_wait) { $this->wait_for_indexes(); }
            }
            $decodeValues=$this->valueCodec && $emulated===null;
            $stage = 'decode';
            $this->last_result = array_map(static function ($row) use ($rowTypes,$decodeValues) {
                foreach ($row as $field=>&$value) {
                    if (is_resource($value)) { $value=stream_get_contents($value); }
                    if (in_array($rowTypes[$field]??'', ['timestamp','timestamptz','date'],true) && is_string($value)) {
                        $value=preg_replace('/^0001-01-01/', '0000-00-00', $value);
                    }
                    elseif ($value !== null) { $value = $decodeValues && ($rowTypes[$field]??'')!=='bytea'?DSQL_Value_Codec::decode((string)$value):(string)$value; }
                }
                return (object) $row;
            }, $rows);
            $this->num_rows = count($rows);
            if (preg_match('/^\s*(INSERT|REPLACE)\b/i', $query)) {
                $this->insert_id = $rows ? (int) reset($rows[0]) : 0;
                // Core installs the default category with explicit term_id=1.
                // PostgreSQL identities do not advance after an explicit ID.
                // Restrict reseeding to the single-writer installation phase.
                if (defined('WP_INSTALLING') && WP_INSTALLING
                    && preg_match('/^INSERT\s+INTO\s+[\x60"]?(\w+)[\x60"]?\s*\(([^)]+)\)/i', $query, $insert)
                    && $insert[1] === $this->terms && preg_match('/\bterm_id\b/', $insert[2])) {
                    $table = '"' . str_replace('"', '""', $this->terms) . '"';
                    $reset = $this->dbh->prepare("SELECT setval(pg_get_serial_sequence(?, 'term_id'), (SELECT MAX(term_id) FROM $table))");
                    $reset->execute([$this->terms]);
                }
            }
            if (defined('SAVEQUERIES') && SAVEQUERIES) {
                $this->log_query($query, microtime(true) - $start, $this->get_caller(), $start, []);
            }
            if (preg_match('/^\s*(CREATE|ALTER|DROP|BEGIN|START|COMMIT|ROLLBACK)\b/i', $query)) { return true; }
            return preg_match('/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/i', $query) ? $this->rows_affected : $this->num_rows;
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            if (preg_match('/^\s*(INSERT|REPLACE)\b/i', $query)) { $this->insert_id = 0; }
            $this->recordFailure($e, $query, $stage, $start, $this->queryRetries);
            return false;
        }
    }

    private function recordFailure(Throwable $error, string $query, string $stage, float $started, int $retries = 0): array {
        $event = DSQL_Diagnostics::event($error, $query, $stage, $started, $retries);
        $this->dsql_error_count++;
        // Retain bounded diagnostics for long-running WP-CLI/cron processes.
        if (count($this->dsql_errors) >= 100) { array_shift($this->dsql_errors); }
        $this->dsql_errors[] = $event;
        DSQL_Diagnostics::emit($event);
        return $event;
    }

    /** Never use wpdb's raw SQL/message HTML and error-log renderer. */
    public function print_error($str = '') {
        $this->recordFailure(new RuntimeException('Reported database failure'), (string) $this->last_query, 'reported', microtime(true));
        return false;
    }

    public function wait_for_indexes(): void {
        foreach ($this->indexJobs as $job) {
            $wait = $this->dbh->prepare('CALL sys.wait_for_job(?)');
            $wait->execute([$job]);
            if (!$wait->fetchColumn()) { throw new RuntimeException('DSQL index build failed'); }
        }
        $this->indexJobs = [];
    }

    private function execute(string $sql): PDOStatement {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->num_queries++;
                return $this->dbh->query($sql);
            } catch (PDOException $e) {
                // Retry only known aborted, single-statement transactions.
                // Never replay an ambiguous connection failure or part of a caller transaction.
                if ($attempt >= 3 || $this->dbh->inTransaction() || !OCCRetry::isOccError($e)) { throw $e; }
                $this->queryRetries++;
                usleep(random_int(10000, 30000) * (2 ** $attempt));
            }
        }
    }

    /** Emulate the metadata queries used by install/dbDelta and wpdb. */
    private function metadataQuery(string $query): ?array {
        // Core's update check asks whether any tables use the MyISAM engine.
        // DSQL has no MyISAM tables.
        if (preg_match('/^\s*SELECT\s+TABLE_NAME\s+FROM\s+information_schema\.TABLES\b/i', $query)
            && preg_match('/\bENGINE\s*=\s*[\'"]MyISAM[\'"]/i', $query)) {
            return [];
        }
        if (preg_match('/^\s*SELECT\s+COUNT\(\*\)\s+FROM\s+information_schema\.statistics\b/i',$query)
            && preg_match('/index_type\s*=\s*[\'"]FULLTEXT[\'"]/i',$query)) { return [['count'=>'0']]; }
        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?autocommit/i',$query)) { return [['autocommit'=>$this->dbh->inTransaction()?'0':'1']]; }
        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?sql_mode/i', $query)) { return [['sql_mode' => '']]; }
        if (preg_match('/^\s*SHOW\s+INDEX(?:ES)?\s+FROM\s+[\x60"]?(\w+)/i', $query, $m)) {
            $saved = $this->schemaCatalog->get($m[1]);
            if ($saved) { return $saved['indexes']; }
            $stmt = $this->dbh->prepare("SELECT t.relname AS \"Table\",
                CASE WHEN i.indisunique THEN 0 ELSE 1 END AS \"Non_unique\",
                CASE WHEN i.indisprimary THEN 'PRIMARY' ELSE substr(idx.relname, length(t.relname)+2) END AS \"Key_name\",
                k.ordinality AS \"Seq_in_index\", a.attname AS \"Column_name\",
                NULL AS \"Sub_part\", 'BTREE' AS \"Index_type\", 'A' AS \"Collation\"
                FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid
                JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_class idx ON idx.oid=i.indexrelid
                CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum, ordinality)
                JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum
                WHERE t.relname=? AND n.nspname=? AND i.indisvalid
                ORDER BY idx.relname, k.ordinality");
            $stmt->execute([$m[1],$this->schema]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if (preg_match('/^\s*SHOW\s+(?:FULL\s+)?TABLES(?:\s+LIKE\s+(.+))?/i', $query, $m)) {
            $sql = "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = '".$this->schema."'";
            if (isset($m[1])) { $sql .= ' AND tablename LIKE ' . $this->translator->quote_mysql_literal(rtrim(trim($m[1]), ';')); }
            return $this->dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        }
        if (preg_match('/^\s*(?:DESCRIBE|DESC|SHOW\s+(?:FULL\s+)?COLUMNS\s+FROM)\s+[\x60"]?(\w+)/i', $query, $m)) {
            $saved = $this->schemaCatalog->get($m[1]);
            if ($saved) { return $saved['columns']; }
            $stmt = $this->dbh->prepare("SELECT column_name, data_type, is_nullable, column_default, character_maximum_length, is_identity
                FROM information_schema.columns WHERE table_name = ? AND table_schema = ? ORDER BY ordinal_position");
            $stmt->execute([$m[1],$this->schema]);
            return array_map(static function ($r) {
                $type = match ($r['data_type']) {
                    'character varying' => 'varchar(' . $r['character_maximum_length'] . ')',
                    'timestamp without time zone' => 'datetime', 'integer' => 'int', default => $r['data_type'],
                };
                $default = $r['column_default'];
                if (is_string($default) && preg_match("/^'((?:[^']|'')*)'::/", $default, $value)) {
                    $default = str_replace("''", "'", $value[1]);
                    if ($default === '0001-01-01 00:00:00') { $default = '0000-00-00 00:00:00'; }
                }
                return ['Field' => $r['column_name'], 'Type' => $type, 'Null' => $r['is_nullable'], 'Key' => '',
                    'Default' => $default, 'Extra' => $r['is_identity'] === 'YES' ? 'auto_increment' : '',
                    'Collation' => 'utf8mb4_unicode_ci'];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }
        return null;
    }
}
