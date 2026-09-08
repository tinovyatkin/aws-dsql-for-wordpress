<?php
use PHPUnit\Framework\TestCase;
use WPDSQL\Engine\{Config,Driver,Result,QueryException};

/** Scripted PDO transport verifies execution contracts without WordPress or a database. */
final class EngineTestPdo extends PDO {
    public array $calls = [];
    public bool $transaction = false;
    public ?Closure $handler = null;
    public function __construct() {}
    public function exec(string $statement): int|false { $this->calls[] = [$statement, []]; return 0; }
    public function inTransaction(): bool { return $this->transaction; }
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function commit(): bool { $this->transaction = false; return true; }
    public function rollBack(): bool { $this->transaction = false; return true; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'".str_replace("'", "''", $string)."'"; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new EngineTestStatement($this, $query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $s = $this->prepare($query); $s->execute(); return $s;
    }
    public function run(string $sql, array $params): array {
        $this->calls[] = [$sql, $params];
        if ($this->handler) { return ($this->handler)($sql, $params); }
        if (str_contains($sql, '__wp_dsql_schema')) { throw new PDOException('SQLSTATE[42P01]: missing catalog'); }
        if (preg_match('/^(BEGIN|COMMIT|ROLLBACK)$/', trim($sql), $m)) {
            $this->transaction = $m[1] === 'BEGIN'; return ['rows'=>[]];
        }
        if (str_contains($sql, 'dsql_found_rows')) { return ['rows'=>[['value'=>'7']]]; }
        return ['rows'=>[['value'=>$params[0] ?? '1']]];
    }
}
final class EngineTestStatement extends PDOStatement {
    private array $data = [];
    public function __construct(private EngineTestPdo $pdo, private string $sql) {}
    public function execute(?array $params = null): bool { $this->data = $this->pdo->run($this->sql, $params ?? []); return true; }
    public function columnCount(): int { return count($this->data['columns'] ?? array_keys($this->data['rows'][0] ?? [])); }
    public function rowCount(): int { return $this->data['affected'] ?? count($this->data['rows'] ?? []); }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->data['rows'] ?? []; }
    public function fetchColumn(int $column = 0): mixed { return array_values($this->data['rows'][0] ?? [])[$column] ?? false; }
    public function getColumnMeta(int $column): array|false {
        return $this->data['columns'][$column] ?? ['name'=>array_keys($this->data['rows'][0])[$column], 'native_type'=>'text'];
    }
}
final class engineTest extends TestCase {
    private function driver(EngineTestPdo $pdo): Driver {
        return new Driver(new Config('synthetic.invalid', cacheDirectory:''), fn()=>$pdo);
    }
    public function test_standalone_engine_binds_current_values_and_reuses_the_plan(): void {
        $pdo = new EngineTestPdo(); $driver = $this->driver($pdo);
        self::assertSame('first', $driver->query("SELECT 'first'")->fetchColumn());
        self::assertSame('second', $driver->query("SELECT 'second'")->fetchColumn());
        self::assertSame(1, $driver->cacheStats()['compilations']);
        self::assertSame(['second'], end($pdo->calls)[1]);
        self::assertStringNotContainsString('second', end($pdo->calls)[0]);
    }
    public function test_results_keep_metadata_and_cursor_independent_of_later_queries(): void {
        $driver = $this->driver(new EngineTestPdo());
        $first = $driver->query("SELECT 'first'"); $driver->query("SELECT 'later'");
        self::assertSame(1, $first->columnCount());
        self::assertSame('value', $first->getColumnMeta(0)['name']);
        self::assertSame([['first']], $first->fetchAll(PDO::FETCH_NUM));
        self::assertSame([], $first->fetchAll());
        self::assertFalse($first->fetchColumn());
    }
    public function test_result_null_is_distinct_from_end_of_data(): void {
        $r = new Result([['v'=>null]], [['name'=>'v']], 1, 'SELECT');
        self::assertNull($r->fetchColumn()); self::assertFalse($r->fetchColumn());
        self::assertFalse($r->getColumnMeta(9));
    }
    public function test_empty_command_results_are_fetchable(): void {
        $r = new Result([], [], 0, 'CREATE', true);
        self::assertFalse($r->fetchColumn()); self::assertSame([], $r->fetchAll());
    }
    public function test_failed_fetch_mode_does_not_advance_the_cursor(): void {
        $r = new Result([['v'=>'kept']], [['name'=>'v']], 1, 'SELECT');
        try { $r->fetch(PDO::FETCH_CLASS); self::fail('Unsupported fetch mode accepted'); } catch (InvalidArgumentException $e) {}
        self::assertSame('kept', $r->fetchColumn());
    }
    public function test_rotation_preserves_logical_session_and_never_splits_a_transaction(): void {
        $now = 0; $connections = [];
        $driver = new Driver(new Config('synthetic.invalid', cacheDirectory:''),
            function() use (&$connections) { return $connections[] = new EngineTestPdo(); },
            function() use (&$now) { return (float) $now; });
        $driver->query("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
        $driver->query('SELECT SQL_CALC_FOUND_ROWS 1 LIMIT 1');
        $driver->query('BEGIN'); $now = 3400;
        $driver->query('SELECT 2'); self::assertCount(1, $connections);
        self::assertTrue($driver->inTransaction());
        $driver->query('COMMIT'); self::assertCount(1, $connections);
        self::assertSame('7', $driver->query('SELECT FOUND_ROWS()')->fetchColumn());
        self::assertCount(2, $connections);
        self::assertSame('NO_BACKSLASH_ESCAPES', $driver->sqlMode());
        self::assertSame("a\\b''c", $driver->escape("a\\b'c"));
        self::assertFalse($driver->inTransaction());
    }
    public function test_occ_retries_only_outside_a_caller_transaction(): void {
        $pdo = new EngineTestPdo(); $attempts = 0;
        $pdo->handler = function() use (&$attempts) {
            if (++$attempts === 1) { throw new PDOException('SQLSTATE[40001]: OCC conflict'); }
            return ['rows'=>[['v'=>'ok']]];
        };
        $driver = $this->driver($pdo);
        self::assertSame('ok', $driver->query('SELECT 1')->fetchColumn()); self::assertSame(2, $attempts);
        $attempts = 0; $pdo->transaction = true;
        try { $driver->query('SELECT 2'); self::fail('Transaction error swallowed'); }
        catch (QueryException $e) { self::assertSame(0, $e->retries); self::assertSame('execute', $e->stage); }
        self::assertSame(1, $attempts);
        self::assertTrue($pdo->transaction);
    }
    public function test_atomic_replace_retries_the_whole_owned_batch_only(): void {
        foreach ([false,true] as $outer) {
            $pdo = new EngineTestPdo(); $pdo->transaction = $outer; $deletes = 0; $inserts = 0;
            $pdo->handler = function($sql) use (&$deletes,&$inserts) {
                if (str_contains($sql,'information_schema.columns')) {
                    return ['rows'=>[
                        ['column_name'=>'id','data_type'=>'bigint','is_nullable'=>'NO','column_default'=>null,'is_identity'=>'YES'],
                        ['column_name'=>'name','data_type'=>'character varying','is_nullable'=>'NO','column_default'=>null,'is_identity'=>'NO'],
                    ]];
                }
                if (str_contains($sql,'FROM pg_index')) {
                    return ['rows'=>[['indexrelid'=>'1','indisprimary'=>'t','indisunique'=>'t','attname'=>'id','ordinality'=>1]]];
                }
                if (str_starts_with($sql,'DELETE')) { $deletes++; return ['rows'=>[],'affected'=>1]; }
                if (str_starts_with($sql,'INSERT')) {
                    if (++$inserts === 1) { throw new PDOException('SQLSTATE[40001]: OCC conflict'); }
                    return ['rows'=>[['id'=>'1','name'=>'label']],'affected'=>1];
                }
                throw new RuntimeException('Unexpected test query');
            };
            $driver = $this->driver($pdo);
            try {
                $result = $driver->query("REPLACE INTO wp_t (id,name) VALUES (1,'label')");
                self::assertFalse($outer);
                self::assertSame(2,$result->rowCount());
                self::assertSame(2,$deletes); self::assertSame(2,$inserts);
                self::assertFalse($pdo->transaction);
            } catch (QueryException $e) {
                self::assertTrue($outer);
                self::assertSame(1,$deletes); self::assertSame(1,$inserts);
                self::assertSame(0,$e->retries); self::assertTrue($pdo->transaction);
            }
        }
    }
    public function test_ambiguous_connection_failure_is_not_replayed_or_exposed_in_top_level_message(): void {
        $pdo = new EngineTestPdo(); $calls = 0;
        $pdo->handler = function() use (&$calls) { $calls++; throw new PDOException('SQLSTATE[08006] private-secret'); };
        $driver = $this->driver($pdo);
        try { $driver->query('SELECT 1'); self::fail('Missing failure'); }
        catch (QueryException $e) {
            self::assertStringNotContainsString('private-secret', (string) $e);
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('private-secret', $e->nativeFailure()->getMessage());
        }
        self::assertSame(1, $calls);
    }
    public function test_sql_mode_metadata_and_rejection_preserve_session_state(): void {
        $driver = $this->driver(new EngineTestPdo());
        self::assertTrue($driver->query("SET sql_mode='ANSI_QUOTES'")->command);
        self::assertSame('ANSI_QUOTES', $driver->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        try { $driver->query("SET sql_mode='unknown'"); self::fail('Unsupported mode accepted'); } catch (QueryException $e) {}
        self::assertSame('ANSI_QUOTES', $driver->sqlMode());
    }
    public function test_close_rejects_queries_and_escaping(): void {
        $pdo = new EngineTestPdo(); $driver = $this->driver($pdo); $pdo->transaction = true; $driver->close();
        self::assertFalse($pdo->transaction);
        self::assertFalse($driver->inTransaction());
        try { $driver->query('SELECT 1'); self::fail('Closed driver executed SQL'); } catch (QueryException $e) { self::assertSame('connect', $e->stage); }
        $this->expectException(RuntimeException::class); $driver->escape('value');
    }
}
