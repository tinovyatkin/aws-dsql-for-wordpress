<?php
namespace WPDSQL\Engine;

/** wpdb/PHP result adaptation only. SQL handling and transport remain native. */
final class NativeDriver extends Driver {
    private \DsqlNativeEngine $native;
    private RequestTiming $requestTiming;
    private ?\WPDSQL\Schema\AutomaticSchema $automatic=null;
    private int $automaticQueries=0;
    private static ?\WeakMap $schemaClients=null;
    public function __construct(private readonly Config $nativeConfig) {
        $this->requestTiming = new RequestTiming();
        if (!class_exists('DsqlNativeEngine', false)) {
            throw new \RuntimeException('The selected native DSQL engine requires its matching PHP extension');
        }
        if (\DsqlNativeEngine::apiVersion() !== 1) throw new \RuntimeException('Native DSQL engine API mismatch');
        if (!method_exists(\DsqlNativeEngine::class, 'connectionStats')) {
            throw new \RuntimeException('Native DSQL connection telemetry requires the matching 0.13 engine');
        }
        if (version_compare(\DsqlNativeEngine::engineVersion(), '0.14.0', '<')) {
            throw new \RuntimeException('Ordered DSQL result columns require the matching 0.14 engine');
        }
        parent::__construct($nativeConfig);
        $region=$nativeConfig->region;
        if (!$region && preg_match('/\.dsql\.([a-z0-9-]+)\.on\.aws$/D',$nativeConfig->host,$m)) $region=$m[1];
        $file=$nativeConfig->credentialsFile ?: (getenv('AWS_SHARED_CREDENTIALS_FILE') ?: null);
        $this->native=new \DsqlNativeEngine(
            $nativeConfig->host, $region ?: '', $nativeConfig->profile ?: 'default',
            $nativeConfig->user, $nativeConfig->schema, $nativeConfig->tablePrefix,
            'native-engine-v1', $file, $nativeConfig->valueCodec, $nativeConfig->sqlMode, $nativeConfig->cacheEnabled,
        );
        if($nativeConfig->automaticSchema) {
            if(!method_exists($this->native,'invalidateSchema'))throw new \RuntimeException('Automatic schema upgrades require the matching native extension');
            $this->automatic=new \WPDSQL\Schema\AutomaticSchema($nativeConfig,fn()=>$this->native->invalidateSchema());
            self::$schemaClients??=new \WeakMap();self::$schemaClients[$this]=true;
        }
    }
    /** WordPress's updater must let its loopback request migrate the new plugin. */
    public static function suspendSchemaForHttp(): bool {
        if(!self::$schemaClients||!\WPDSQL\Schema\SchemaGate::canSuspend())return false;
        foreach(self::$schemaClients as $client=>$unused)if($client->inTransaction())return false;
        foreach(self::$schemaClients as $client=>$unused)$client->native->invalidateSchema();
        \WPDSQL\Schema\SchemaGate::suspendForHttp();return true;
    }
    private function call(callable $operation): mixed {
        return $this->requestTiming->measure(fn()=>$this->invoke($operation));
    }
    private function invoke(callable $operation): mixed {
        try { $this->automatic?->ensureAccess();return $operation(); }
        catch (\Throwable $error) {
            $info=$this->native->errorInfo();
            $state=$info['sqlstate']??null;
            $failure=is_string($state)&&preg_match('/^[A-Z0-9]{5}$/D',$state)
                ? new NativeDatabaseException($error->getMessage(),$state)
                : new \RuntimeException($error->getMessage());
            throw new QueryException($failure,$info['stage']??'native',$info['operation']??'',(int)($info['retries']??0));
        }
    }
    public function query(string $query,bool $deferIndexWait=false): Result {
        return $this->requestTiming->measure(fn()=>$this->executeQuery($query,$deferIndexWait), true);
    }
    private function executeQuery(string $query,bool $deferIndexWait): Result {
        if($this->automatic) {
            try {
                $this->automatic->ensureAccess();
                $this->automatic->assertQueryAllowed($query);
                if(\WPDSQL\Schema\AutomaticSchema::isDdl($query)) {
                    $this->automaticQueries++;
                    if($this->inTransaction())throw new \RuntimeException('Schema changes inside a caller-owned transaction require a controlled migration');
                    $this->automatic->execute($query,$this->sqlMode());
                    return new Result([],[],0,\WPDSQL\Schema\AutomaticSchema::operation($query),true,0,$query,$this->sqlMode());
                }
            } catch(\Throwable $error) {
                $this->automatic->markFailed($query,$error);
                $state=$error instanceof \PDOException?(string)$error->getCode():null;
                if(!is_string($state)||!preg_match('/^[A-Z0-9]{5}$/D',$state))$state=null;
                $this->native->recordSchemaError($state);
                $reason=$error instanceof \PDOException?'Automatic DSQL schema operation failed'.($state?' ('.$state.')':''):$error->getMessage();
                $failure=$state?new NativeDatabaseException($reason,$state):new \RuntimeException($reason);
                throw new QueryException($failure,'schema',\WPDSQL\Schema\AutomaticSchema::operation($query),0);
            }
        }
        $out=$this->call(fn()=>$this->native->query($query));
        $this->requestTiming->recordNative($out['timing']??[]);
        return new Result($out['rows'],$out['metadata'],(int)$out['affected_rows'],
            $out['operation'],(bool)$out['command'],(int)$out['insert_id'],$query,$this->sqlMode(),$out['numeric_rows']??null);
    }
    public function checkConnection(): void {$this->call(fn()=>$this->native->checkConnection());}
    public function close(): void {$this->native->close();if(self::$schemaClients)unset(self::$schemaClients[$this]);}
    /** Request totals include connection acquisition before wpdb starts its query timer. */
    public function connectionStats(): array {return $this->native->connectionStats();}
    /** Request-scoped totals include bootstrap queries and pre-query connection checks. */
    public function timingStats(): array {
        $connection = $this->native->connectionStats();
        return $this->requestTiming->snapshot() + [
            'connection_ms'=>(float)($connection['check_ms']??0),
            'connections_opened'=>(int)($connection['opened']??0),
        ];
    }
    public function isConnected(): bool {return $this->native->isConnected();}
    public function inTransaction(): bool {return $this->native->inTransaction();}
    public function queryCount(): int {return $this->native->queryCount()+$this->automaticQueries;}
    public function sqlMode(): string {return $this->native->sqlMode();}
    public function cacheStats(): array {return $this->native->cacheStats()+['disk_hits'=>0,'writes'=>0,'errors'=>0,'prepared_hits'=>0,'persistent_cache'=>$this->nativeConfig->cacheEnabled,'native_cache'=>true];}
    public function rememberPrepared(string $sql): void {$this->native->rememberPrepared($sql);}
    public function escape(string $value): string {return $this->call(fn()=>$this->native->escape($value));}
    public function serverInfo(): string {return 'Aurora DSQL (native Rust, PostgreSQL-compatible)';}
    public function logicalTable(string $name): ?array {return $this->call(fn()=>$this->native->logicalTable($name));}
    public function columnMeta(Result $result,int $position): array|false {
        $meta=$result->getColumnMeta($position);
        if (!$meta || empty($meta['table'])) return $meta;
        $table=$this->logicalTable($meta['table']);
        if (!$table) return $meta;
        foreach($table['columns'] as $column) {
            if (strcasecmp($column['Field'],$meta['orgname']??$meta['name'])===0) {
                return \WPDSQL\Schema\Model::columnDescriptor($table,$column,$meta,$this->nativeConfig->database);
            }
        }
        return $meta;
    }
    public function logFailure(string $query,string $stage,float $started): array {
        return $this->native->logError($query,\DSQL_Diagnostics::context(),\DSQL_Diagnostics::source(),$stage,max(0,microtime(true)-$started)*1000);
    }
    public function waitForIndexes(): void {}
    public function reseedIdentity(string $table,string $column): void {$this->automatic?->assertQueryAllowed('UPDATE');$this->call(fn()=>$this->native->reseedIdentity($table,$column));}
    public function enableSchemaUpgrade(\WPDSQLUpgrade\Session $session): void {
        throw new \RuntimeException('Controlled schema upgrades must start through the guarded maintenance runner');
    }
    public function verifySchemaUpgrade(): array {throw new \RuntimeException('No controlled maintenance context');}
}
