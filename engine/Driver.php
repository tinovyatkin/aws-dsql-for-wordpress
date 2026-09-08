<?php
namespace WPDSQL\Engine;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Aws\AuroraDsql\PdoPgsql\OCCRetry;

require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-sql.php';
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
use DSQL_SQL;
use DSQL_Schema_Catalog;
use DSQL_Value_Codec;

/** MySQL compatibility and DSQL execution without WordPress bootstrap or hooks. */
final class Driver {
    public const MYSQL_VERSION = '8.0.17';
    public readonly string $client_info;
    private ?PDO $pdo = null;
    private DSQL_SQL $translator;
    private DSQL_Schema_Catalog $schemaCatalog;
    private \Closure $connector;
    private \Closure $clock;
    private float $connectedAt = 0;
    private bool $closed = false;
    private array $indexJobs = [];
    private array $pendingTables = [];
    private bool $catalogChecked = false;
    private \WPDSQL\Schema\Introspection $introspection;
    private int $queries = 0;
    private int $retries = 0;
    private string $stage = 'connect';
    private string $operation = '';
    private ?\WPDSQLUpgrade\Engine $schemaUpgrade = null;
    private ?\WPDSQLUpgrade\Session $upgradeSession = null;
    private string $schema;

    /** The optional factory/clock support injected transports and deterministic lifecycle tests. */
    public function __construct(private readonly Config $config, ?\Closure $connector = null, ?\Closure $clock = null) {
        $this->connector = $connector ?? static fn() => AwsConnection::connect($config);
        $this->clock = $clock ?? static fn() => microtime(true);
        $this->schema = $config->schema;
        $this->client_info = self::MYSQL_VERSION.'-mysql-on-dsql';
        $this->connect();
    }
    private function connect(): void {
        try {
            $pdo = ($this->connector)();
            $pdo->exec('SET search_path TO "'.$this->schema.'", pg_catalog');
            $upgrade = $this->upgradeSession ? new \WPDSQLUpgrade\Engine($pdo, $this->upgradeSession) : null;
            if (isset($this->translator)) {
                $this->translator->reconnect($pdo);
            } else {
                $c = $this->config;
                $this->translator = new DSQL_SQL($pdo, $c->valueCodec, $c->schema, $c->sqlMode,
                    $c->host, $c->cacheDirectory, $c->cacheEnabled, $c->tablePrefix);
            }
            $this->pdo = $pdo;
            $this->schemaCatalog = new DSQL_Schema_Catalog($pdo);
            $this->introspection = new \WPDSQL\Schema\Introspection($pdo,$this->schemaCatalog,$this->schema,$this->config->database);
            $this->catalogChecked=false;
            $this->schemaUpgrade = $upgrade;
            $this->connectedAt = ($this->clock)();
        } catch (\Throwable $error) {
            throw new QueryException($error, 'connect', '', 0, $this->upgradeSession !== null);
        }
    }
    public function checkConnection(): void {
        if ($this->closed) { throw new RuntimeException('DSQL driver is closed'); }
        $this->upgradeSession?->assertActive();
        // A logical transaction must never be split across physical connections.
        if (($this->clock)() - $this->connectedAt >= 3300 && !$this->pdo->inTransaction()) {
            $this->connect();
        }
    }
    public function close(): void {
        try {
            if ($this->pdo?->inTransaction()) { $this->pdo->rollBack(); }
        } catch (\Throwable $error) {
            throw new QueryException($error, 'close', 'ROLLBACK', 0);
        } finally {
            $this->closed = true;
            $this->schemaUpgrade = null;
            $this->upgradeSession = null;
            unset($this->translator, $this->schemaCatalog, $this->introspection);
            $this->pdo = null;
            $this->indexJobs = [];
        }
    }
    public function inTransaction(): bool { return $this->pdo?->inTransaction() ?? false; }
    public function queryCount(): int { return $this->queries; }
    public function sqlMode(): string { return $this->translator->sqlMode(); }
    public function cacheStats(): array { return $this->translator->stats(); }
    public function rememberPrepared(string $sql): void { $this->translator->rememberPrepared($sql); }
    public function serverInfo(): string { return 'Aurora DSQL (PostgreSQL-compatible)'; }

    /** MySQL escaping only; WordPress adds its placeholder-escape markers above this layer. */
    public function escape(string $value): string {
        if ($this->closed) { throw new RuntimeException('Cannot escape data without an active database connection.'); }
        if (in_array('NO_BACKSLASH_ESCAPES', explode(',', $this->sqlMode()), true)) {
            return str_replace("'", "''", $value);
        }
        return strtr($value, ['\\'=>'\\\\', "'"=>"\\'", '"'=>'\\"', "\0"=>'\\0', "\n"=>'\\n', "\r"=>'\\r', "\x1a"=>'\\Z']);
    }

    public function query(string $query, bool $deferIndexWait = false): Result {
        $this->stage = 'connect';
        $this->operation = '';
        $this->retries = 0;
        try {
            $this->checkConnection();
            $this->stage = 'metadata';
            $this->operation = $this->translator->operation($query);
            $operation = $this->operation;
            if ($this->isDdl()) {
                if ($this->schemaUpgrade) {
                    $this->schemaUpgrade->execute($query);
                    $this->queries++;
                    return new Result([], [], 0, $operation, true);
                }
                if ($this->schemaCatalog->managed()) {
                    throw new RuntimeException('Schema changes on restored tables require the controlled DSQL upgrade runner');
                }
            }
            if(!$this->isDdl()&&($this->indexJobs||$this->pendingTables))$this->waitForIndexes();
            if(!$this->isDdl()&&!$this->catalogChecked){$this->schemaCatalog->assertReadable($this->config->tablePrefix);$this->catalogChecked=true;}
            $emulated = $this->metadataQuery($query);
            $rows = [];
            $columns = [];
            $affected = 0;
            if ($emulated !== null) {
                $rows = $emulated;
                $this->queries++;
                foreach (array_keys($rows[0] ?? []) as $name) { $columns[] = ['name'=>$name, 'native_type'=>'text']; }
            } else {
                if (in_array($operation, ['INSERT','UPDATE','DELETE','REPLACE'], true)) { $this->waitForIndexes(); }
                if (preg_match('/^\s*SET\s+(?:NAMES|(?:SESSION\s+)?sql_mode)\b/i', $query)) {
                    if (preg_match('/^\s*SET\s+(?:SESSION\s+)?sql_mode\b/i', $query)) { $this->translator->setSqlModeStatement($query); }
                    return new Result([], [], 0, $operation, true);
                }
                $this->stage = 'translate';
                $statements = $this->translator->translate($query);
                $logical=$statements[0]['logical_table']??null;
                if($logical)$this->schemaCatalog->beginInstallation($logical);
                $this->stage = 'execute';
                $atomic = !empty($statements[0]['atomic']);
                $outerTransaction = $this->pdo->inTransaction();
                for ($attempt = 0; ; $attempt++) {
                    $ownsTransaction = $atomic && !$outerTransaction;
                    try {
                        if ($ownsTransaction) { $this->pdo->beginTransaction(); }
                        $rows = []; $columns = []; $affected = 0;
                        foreach ($statements as $statement) {
                            $sql = $statement['sql'];
                            $stmt = $this->execute($sql, $statement['params']);
                            if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+ASYNC\b/i', $sql)) {
                                $job = $stmt->fetchColumn();
                                if ($job) { $this->indexJobs[] = $job; }
                            } else {
                                $columns = [];
                                for ($i = 0; $i < $stmt->columnCount(); $i++) { $columns[] = $stmt->getColumnMeta($i); }
                                $rows = $stmt->columnCount() ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                                $affected += $stmt->rowCount();
                            }
                        }
                        if ($ownsTransaction) { $this->pdo->commit(); }
                        break;
                    } catch (\Throwable $error) {
                        if ($ownsTransaction && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                        if (!$ownsTransaction || $attempt >= 3 || !$error instanceof PDOException || !OCCRetry::isOccError($error)) { throw $error; }
                        $this->retries++;
                        usleep(random_int(10000, 30000) * (2 ** $attempt));
                    }
                }
                if($logical)$this->pendingTables[$logical['name']]=$logical;
                if(isset($statements[0]['logical_drop'])&&$this->schemaCatalog->exists()){
                    $this->schemaCatalog->remove($statements[0]['logical_drop']);unset($this->pendingTables[$statements[0]['logical_drop']]);
                }
                if (!$deferIndexWait) { $this->waitForIndexes(); }
            }
            $this->stage = 'decode';
            $types = array_column($columns, 'native_type', 'name');
            $decode = $this->config->valueCodec && $emulated === null;
            $insertId = in_array($operation, ['INSERT','REPLACE'], true) && $rows ? (int) reset($rows[0]) : 0;
            foreach ($rows as &$row) {
                foreach ($row as $field => &$value) {
                    if (is_resource($value)) { $value = stream_get_contents($value); }
                    if (in_array($types[$field] ?? '', ['timestamp','timestamptz','date'], true) && is_string($value)) {
                        $value = preg_replace('/^0001-01-01/', '0000-00-00', $value);
                    } elseif ($value !== null) {
                        $value = $decode && ($types[$field] ?? '') !== 'bytea' ? DSQL_Value_Codec::decode((string) $value) : (string) $value;
                    }
                }
                unset($value);
            }
            unset($row);
            return new Result($rows, $columns, $affected, $operation,
                in_array($operation, ['CREATE','ALTER','DROP','RENAME','TRUNCATE','BEGIN','START','COMMIT','ROLLBACK'], true), $insertId, $query, $this->translator->sqlMode());
        } catch (\Throwable $error) {
            $this->fail($error,$this->stage,$this->operation,$this->retries);
        } finally {
            if ($this->isDdl() && isset($this->translator)) {
                $this->catalogChecked=false;
                $this->schemaCatalog->clear();
                $this->translator->refreshSchemaMetadata();
                $this->introspection->clear();
            }
        }
    }
    private function fail(\Throwable $error,string $stage,string $operation,int $retries=0): never {
        if($error instanceof QueryException){$stage=$error->stage;$operation=$error->operation;$retries=$error->retries;$error=$error->nativeFailure();}
        if($this->upgradeSession){
            $this->upgradeSession->fail();
            file_put_contents($this->upgradeSession->directory.'/failure.txt',$error->getMessage());
            chmod($this->upgradeSession->directory.'/failure.txt',0600);
        }
        throw new QueryException($error,$stage,$operation,$retries,$this->upgradeSession!==null);
    }
    private function isDdl(): bool { return in_array($this->operation, ['CREATE','ALTER','DROP','RENAME','TRUNCATE'], true); }
    public function enableSchemaUpgrade(\WPDSQLUpgrade\Session $session): void {
        require_once dirname(__DIR__).'/upgrade/Engine.php';
        $this->schemaUpgrade = new \WPDSQLUpgrade\Engine($this->pdo, $session);
        $this->upgradeSession = $session;
    }
    public function verifySchemaUpgrade(): array {
        if (!$this->schemaUpgrade) { throw new RuntimeException('No controlled upgrade context'); }
        return $this->schemaUpgrade->verify();
    }
    /** Explicit single-writer import/installation operation, never an ordinary INSERT side effect. */
    public function reseedIdentity(string $table, string $column): void {
        $tableSql = \WPDSQL\MySQL\Translation\Renderer::qi($table);
        $columnSql = \WPDSQL\MySQL\Translation\Renderer::qi($column);
        $s = $this->pdo->prepare("SELECT setval(pg_get_serial_sequence(?, ?), (SELECT MAX($columnSql) FROM $tableSql))");
        $s->execute([$table, $column]);
    }
    public function waitForIndexes(): void {
        if(!$this->indexJobs&&!$this->pendingTables)return;
        foreach ($this->indexJobs as $job) {
            $wait = $this->pdo->prepare('CALL sys.wait_for_job(?)');
            $wait->execute([$job]);
            if (!$wait->fetchColumn()) { throw new RuntimeException('DSQL index build failed'); }
        }
        $this->indexJobs = [];
        foreach($this->pendingTables as $table)$this->schemaCatalog->finishInstallation($table);
        $this->pendingTables=[];
        $this->introspection->clear();
    }
    private function execute(string $sql, array $params = []): PDOStatement {
        for ($attempt = 0; ; $attempt++) {
            try {
                $this->queries++;
                if (!$params) { return $this->pdo->query($sql); }
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
            } catch (PDOException $error) {
                if ($attempt >= 3 || $this->pdo->inTransaction() || !OCCRetry::isOccError($error)) { throw $error; }
                $this->retries++;
                usleep(random_int(10000, 30000) * (2 ** $attempt));
            }
        }
    }
    /** Session variables remain local; schema metadata is dispatched through its AST. */
    private function metadataQuery(string $query): ?array {
        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?autocommit\s*;?\s*$/i',$query)) return [['autocommit'=>$this->pdo->inTransaction()?'0':'1']];
        if (preg_match('/^\s*SELECT\s+@@(?:SESSION\.)?sql_mode\s*;?\s*$/i',$query)) return [['sql_mode'=>$this->translator->sqlMode()]];
        if(in_array($this->operation,['SHOW','DESCRIBE','DESC','CHECK','REPAIR','OPTIMIZE','ANALYZE'],true)||($this->operation==='SELECT'&&preg_match('/\binformation_schema\s*\./i',$query))) {
            return $this->introspection->query($query,$this->translator->sqlMode());
        }
        return null;
    }
    /** Logical metadata is requested explicitly; ordinary result fetching adds no catalog work. */
    public function columnMeta(Result $result,int $position): array|false {
        $native=$result->getColumnMeta($position);if(!$native||empty($native['table']))return $native;
        $table=$this->logicalTable($native['table']);if(!$table)return $native;
        $name=$native['name'];$sql=$result->sourceQuery();
        if($sql!==''&&$result->operation==='SELECT'){
            $parsed=\WPDSQL\MySQL\SqlParser::parse($sql,sqlMode:$result->sourceSqlMode());
            $specs=\WPDSQL\Schema\Ast::nodes($parsed->tree,'query_specification');
            if(count($specs)===1){
                $items=\WPDSQL\Schema\Ast::nodes($specs[0],'select_item');
                if(count($items)===$result->columnCount()&&isset($items[$position])){
                    $expr=\WPDSQL\Schema\Ast::child($items[$position],'expr');
                    $idents=$expr?\WPDSQL\Schema\Ast::nodes($expr,'simple_ident'):[];
                    if(count($idents)===1&&\WPDSQL\Schema\Ast::text($expr)===\WPDSQL\Schema\Ast::text($idents[0])){
                        $tokens=\WPDSQL\Schema\Ast::tokens($idents[0]);$name=end($tokens)->get_value();
                    }
                }
            }
        }
        foreach($table['columns'] as $column)if(strcasecmp($column['Field'],$name)===0)return \WPDSQL\Schema\Model::columnDescriptor($table,$column,$native,$this->config->database);
        return $native;
    }
    public function logicalTable(string $name): ?array {
        try{$this->checkConnection();$this->waitForIndexes();return $this->introspection->qualifiedTable($name);}
        catch(\Throwable $error){$this->fail($error,'metadata','SHOW');}
    }
}
