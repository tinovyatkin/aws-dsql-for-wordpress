<?php
namespace WPDSQL\Schema;

use PDO;
use WPDSQL\Engine\AwsConnection;
use WPDSQL\Engine\Config;
use WPDSQLMigration\Backup;
use WPDSQLMigration\Plan;

/** Recoverable native-DDL fast paths. Ordinary data queries still use Rust. */
final class AutomaticSchema {
    private SchemaGate $gate;
    private ?PDO $pdo=null;
    private ?\DSQL_Schema_Catalog $catalog=null;
    private bool $failed=false;
    public function __construct(private Config $config,private \Closure $invalidate,private ?\Closure $faultHandler=null,bool $recover=true) {
        if(!$config->schemaUser||!$config->schemaStateDirectory)throw new \RuntimeException('Automatic schema upgrades need a schema role and state directory');
        $this->gate=SchemaGate::get($config->schemaStateDirectory);
        if(defined('ABSPATH')&&str_starts_with($this->gate->directory.'/',rtrim(realpath(ABSPATH)?:ABSPATH,'/').'/'))throw new \RuntimeException('Schema journals must be outside the WordPress document root');
        if($recover && (is_file($this->path('pending.json'))||is_file($this->path('release.json'))))$this->gate->exclusive(fn()=>$this->recover());
    }
    public static function operation(string $sql): string {
        // Routing only; schema statements are subsequently parsed in full.
        $offset=0;$length=strlen($sql);
        while($offset<$length) {
            $offset+=strspn($sql," \t\r\n\f\v",$offset);
            if(substr($sql,$offset,2)==='/*'){$end=strpos($sql,'*/',$offset+2);if($end===false)return ''; $offset=$end+2;continue;}
            if(($sql[$offset]??'')==='#'||(substr($sql,$offset,2)==='--'&&ctype_space($sql[$offset+2]??''))){$end=strpos($sql,"\n",$offset);if($end===false)return ''; $offset=$end+1;continue;}
            break;
        }
        return preg_match('/\G([a-z]+)/Ai',$sql,$m,0,$offset)?strtoupper($m[1]):'';
    }
    public static function isDdl(string $sql): bool {return in_array(self::operation($sql),['CREATE','ALTER','DROP','RENAME','TRUNCATE'],true);}
    public function assertQueryAllowed(string $sql): void {
        if($this->failed&&!in_array(self::operation($sql),['SELECT','SHOW','DESCRIBE','DESC','ROLLBACK'],true))throw new \RuntimeException('An earlier schema upgrade failed; subsequent writes in this request are stopped');
    }
    private function fault(string $point,array $op=[]): void {if($this->faultHandler)($this->faultHandler)($point,$op);}
    private function path(string $file): string {return $this->gate->directory.'/'.$file;}
    private function write(string $file,array $data): void {
        $path=$this->path($file);$temp=$path.'.'.bin2hex(random_bytes(6));$handle=fopen($temp,'xb');if(!$handle)throw new \RuntimeException('Cannot write schema journal');chmod($temp,0660);
        try{$bytes=json_encode($data,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";if(fwrite($handle,$bytes)!==strlen($bytes))throw new \RuntimeException('Incomplete schema journal write');fflush($handle);fsync($handle);}finally{fclose($handle);}
        if(!rename($temp,$path))throw new \RuntimeException('Cannot publish schema journal');$dir=fopen($this->gate->directory,'r');if($dir){fsync($dir);fclose($dir);}
    }
    private function connect(): PDO {
        if($this->pdo)return $this->pdo;
        $c=$this->config;
        $this->pdo=AwsConnection::connect(new Config(host:$c->host,user:$c->schemaUser,database:$c->database,region:$c->region,profile:$c->profile,credentialsFile:$c->credentialsFile,schema:$c->schema,valueCodec:$c->valueCodec,tablePrefix:$c->tablePrefix));
        $this->pdo->exec('SET search_path TO '.Backup::qi($c->schema).', pg_catalog');
        if($this->pdo->query('SELECT current_user')->fetchColumn()!==$c->schemaUser)throw new \RuntimeException('Unexpected schema connection role');
        require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
        require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-value-codec.php';
        $this->catalog=new \DSQL_Schema_Catalog($this->pdo);
        return $this->pdo;
    }
    private function target(string $table): string {return Backup::qi($this->config->schema).'.'.Backup::qi($table);}
    private function row(string $sql,array $params=[]): array|false {$s=$this->connect()->prepare($sql);$s->execute($params);return $s->fetch(PDO::FETCH_ASSOC);}
    private function scalar(string $sql,array $params=[]): mixed {$s=$this->connect()->prepare($sql);$s->execute($params);return $s->fetchColumn();}
    private function exec(string $sql,array $params=[]): int {$s=$this->connect()->prepare($sql);$s->execute($params);return $s->rowCount();}
    private function oid(string $table): ?string {return $this->scalar('SELECT to_regclass(?)::text',[$this->target($table)])?:null;}
    private function defaultSql(array $column): string {
        if($column['Default']===null)return 'NULL';
        if($column['DefaultExpression']??false){if(!preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]?\))?$/i',$column['Default']))throw new \RuntimeException('Unsupported automatic default expression');return $column['Default'];}
        $value=Plan::convert((string)$column['Default'],$column,false,$this->config->valueCodec&&Plan::type($column)!=='bytea');
        return $this->connect()->quote($value);
    }
    private function reserve(string $id): void {
        $table=$this->target('__wp_dsql_upgrade_lock');
        try{$this->exec("INSERT INTO $table (id,run_id) VALUES ('schema',?)",[$id]);}
        catch(\PDOException $e){if(in_array((string)$e->getCode(),['23505','40001'],true))throw new SchemaBusy('Another database schema controller is active');throw $e;}
    }
    private function release(string $id): void {$this->exec('DELETE FROM '.$this->target('__wp_dsql_upgrade_lock')." WHERE id='schema' AND run_id=?",[$id]);}
    public function execute(string $sql,string $mode): void {
        $this->assertQueryAllowed($sql);
        try {$this->gate->exclusive(function()use($sql,$mode){
            $this->connect();if(is_file($this->path('pending.json')))$this->recover();
            $id='auto-'.bin2hex(random_bytes(16));$this->reserve($id);
            try {
                $parsed=(new \WPDSQLUpgrade\Schema($sql,$this->config->schema,$mode))->parse();
                $this->catalog->clear();$before=$this->catalog->get($parsed['table']);
                if(!$before&&$this->oid($parsed['table']))throw new \RuntimeException('Existing physical table is not catalogued');
                $plan=AutomaticPlan::build($sql,$before,$this->config->tablePrefix,$this->config->schema,$this->config->valueCodec,$mode);
                $plan['id']=$id;$plan['endpoint']=$this->config->host;$plan['schema']=$this->config->schema;$plan['phase']='apply';$plan['position']=0;$plan['sql_fingerprint']=hash('sha256',$sql);
                $this->prepare($plan);
                if(!$plan['steps']&&$plan['before']==$plan['after']){$this->release($id);return;}
                $this->write('pending.json',$plan);$this->fault('planned',$plan);$this->run($plan);
            } catch(\Throwable $error) {
                if(!is_file($this->path('pending.json')))$this->release($id);
                throw $error;
            }
        });} catch(\Throwable $error) {
            $this->markFailed($sql,$error);
            throw $error;
        } finally {($this->invalidate)();}
    }
    public function markFailed(string $sql,\Throwable $error): void {
        if($this->failed)return;
        $this->failed=true;
        if($error instanceof SchemaBusy)return;
        $state=$error instanceof \PDOException?(string)$error->getCode():null;
        $reason=$error instanceof \PDOException?'Database rejected an automatic schema operation':$error->getMessage();
        $this->write('last-error.json',['timestamp'=>gmdate('c'),'fingerprint'=>hash('sha256',$sql),'reason'=>$reason,'sqlstate'=>$state]);
    }
    public function recover(): void {
        $this->connect();
        if(!is_file($this->path('pending.json'))&&is_file($this->path('release.json'))){$marker=json_decode(file_get_contents($this->path('release.json')),true,512,JSON_THROW_ON_ERROR);$this->release($marker['id']);unlink($this->path('release.json'));return;}
        $op=json_decode(file_get_contents($this->path('pending.json')),true,512,JSON_THROW_ON_ERROR);
        if($op['endpoint']!==$this->config->host||$op['schema']!==$this->config->schema)throw new \RuntimeException('Schema journal belongs to another database');
        if($this->scalar('SELECT run_id FROM '.$this->target('__wp_dsql_upgrade_lock')." WHERE id='schema'")!==$op['id'])throw new \RuntimeException('Schema recovery reservation changed');
        $this->run($op);($this->invalidate)();
    }
    public function cancel(): void {
        $this->gate->exclusive(function(){
            $this->connect();$op=json_decode(file_get_contents($this->path('pending.json')),true,512,JSON_THROW_ON_ERROR);
            if($op['endpoint']!==$this->config->host||$op['schema']!==$this->config->schema||$this->scalar('SELECT run_id FROM '.$this->target('__wp_dsql_upgrade_lock')." WHERE id='schema'")!==$op['id'])throw new \RuntimeException('Schema rollback target or reservation changed');
            if($op['phase']!=='rollback'){$op['phase']='rollback';$op['rollback_position']=min($op['position'],count($op['steps'])-1);$this->write('pending.json',$op);}
            $this->rollback($op);
        });($this->invalidate)();
    }
    private function complete(array $op): void {
        $this->catalog->clear();
        $target=$op['phase']==='rollback'?$op['before']:$op['after'];
        $this->pdo->beginTransaction();
        try {
            foreach(array_unique(array_filter([$op['before']['name']??null,$op['after']['name']??null])) as $name)$this->catalog->remove($name);
            if($target)$this->catalog->put($target);
            $this->pdo->commit();
        } catch(\Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        $this->fault('catalog-published',$op);
        $this->write('last-operation.json',['id'=>$op['id'],'finished'=>gmdate('c'),'rolled_back'=>$op['phase']==='rollback','fingerprint'=>$op['sql_fingerprint']]);
        $this->write('release.json',['id'=>$op['id']]);
        unlink($this->path('pending.json'));$this->fault('journal-completed',$op);
        $this->release($op['id']);unlink($this->path('release.json'));$this->catalog->clear();
    }
    private function run(array $op): void {
        if($op['phase']==='rollback'){$this->rollback($op);return;}
        try {
            while($op['position']<count($op['steps'])) {
                $i=$op['position'];$this->fault('before-step',$op);$this->apply($op['steps'][$i],$op);$this->fault('after-step',$op);
                $op['position']++;$this->write('pending.json',$op);
            }
            $this->complete($op);
        } catch(\Throwable $error) {
            if(!is_file($this->path('pending.json')))throw $error;
            // Transport/unknown outcomes remain recoverable; never replay arbitrary writes.
            $state=$error instanceof \PDOException?(string)$error->getCode():'';
            if($error instanceof \PDOException&&($state==='HY000'||$state==='0'||str_starts_with($state,'08')))throw $error;
            $op['phase']='rollback';$op['rollback_position']=min($op['position'],count($op['steps'])-1);$this->write('pending.json',$op);
            $this->rollback($op);throw $error;
        }
    }
    private function prepare(array &$op): void {
        $this->connect();$sources=[];
        foreach($op['before']['columns']??[] as $c)$sources[$c['Field']]=Backup::qi($c['Field']);
        foreach($op['steps'] as &$step) {
            $kind=$step['kind'];
            if($kind==='create') {
                $step['ddl']=Plan::ddl($step['table'],$this->pdo);
                continue;
            }
            if($kind==='add_column') {
                $c=$step['column'];$type=Plan::type($c);
                if($type==='bytea'&&$c['Default']!==null)throw new \RuntimeException('Binary column defaults require a controlled migration');
                $step['default_sql']=$this->defaultSql($c);
                // Validate conversion before recording/mutating the schema.
                if($c['Default']!==null)$this->scalar('SELECT CAST('.$step['default_sql'].' AS '.$type.')');
                $step['fill_sql']=$step['default_sql'];
                if($c['DefaultExpression']??false)$step['fill_sql']=$this->pdo->quote((string)$this->scalar('SELECT ('.$step['default_sql'].')::text'));
                $step['pk']=array_column(Plan::indexes($op['before'])['PRIMARY']??[],'Column_name');
                $count=(int)$this->scalar('SELECT COUNT(*) FROM '.$this->target($step['table']));
                if($c['Null']==='NO'&&$c['Default']===null&&$count)throw new \RuntimeException('A new required column needs a default for existing rows');
                if($c['Default']!==null&&$count&&!$step['pk'])throw new \RuntimeException('Automatic backfill needs a primary key');
                $sources[$c['Field']]='CAST('.$step['fill_sql'].' AS '.$type.')';
            } elseif($kind==='default') {
                $step['default_sql']=$this->defaultSql($step['column']);$step['undo_default_sql']=$this->defaultSql($step['before']);
                if($step['column']['Default']!==null)$this->scalar('SELECT CAST('.$step['default_sql'].' AS '.Plan::type($step['column']).')');
            } elseif($kind==='rename_column') {
                $sources[$step['new']]=$sources[$step['old']];unset($sources[$step['old']]);
            } elseif($kind==='rename') {
                if($this->oid($step['new'])||$this->catalog->raw($step['new']))throw new \RuntimeException('Table rename destination exists');
            } elseif(in_array($kind,['add_index','drop_index'],true)) {
                $groups=Plan::indexes($step['definition']);$group=$groups[$step['key']];
                foreach($group as $index) {
                    $c=AutomaticPlan::column($step['definition'],$index['Column_name']);
                    if(in_array(Plan::type($c),['bytea','json'],true))throw new \RuntimeException('This index data type requires a controlled migration');
                    if($this->config->valueCodec && str_contains((string)$c['Default'],"\0"))throw new \RuntimeException('Encoded NUL values cannot be indexed');
                    if($this->config->valueCodec&&isset($sources[$c['Field']])&&Plan::type($c)==='text') {
                        $expr=$sources[$c['Field']];$q=$this->pdo->query('SELECT '.$expr.' FROM '.$this->target($op['table']).' WHERE '.$expr." LIKE '~dsqlb64:v1:%'");
                        while(($v=$q->fetchColumn())!==false)if(str_contains(\DSQL_Value_Codec::decode($v),"\0"))throw new \RuntimeException('Encoded NUL values cannot be indexed');
                    }
                }
                if($kind==='drop_index') {
                    $actual=$this->physicalIndex($step['table'],$step['key'],$group);
                    $step['physical_name']=$actual['name'];$step['undo_sql']=preg_replace('/^CREATE INDEX /','CREATE INDEX ASYNC ',$actual['definition']);
                } else {
                    $name=$step['table'].'_'.$step['key'];if(strlen($name)>63)$name=substr($name,0,46).'_'.substr(hash('sha256',$name),0,16);
                    if($this->scalar('SELECT to_regclass(?)::text',[Backup::qi($this->config->schema).'.'.Backup::qi($name)]))throw new \RuntimeException('Physical index name already exists');
                    $step['physical_name']=$name;
                    $step['sql']='CREATE '.(!$group[0]['Non_unique']?'UNIQUE ':'').'INDEX ASYNC '.Backup::qi($name).' ON '.$this->target($step['table']).' ('.implode(',',array_map(static fn($i)=>Backup::qi($i['Column_name']),$group)).')';
                    if(!$group[0]['Non_unique']) {
                        $expressions=[];foreach($group as $i=>$index)$expressions[]=($sources[$index['Column_name']]??throw new \RuntimeException('Index column is missing')).' AS k'.$i;
                        $keys=array_map(static fn($i)=>'k'.$i,array_keys($group));
                        $sql='SELECT 1 FROM (SELECT '.implode(',',$expressions).' FROM '.$this->target($op['table']).') AS candidate WHERE '.implode(' AND ',array_map(static fn($k)=>$k.' IS NOT NULL',$keys)).' GROUP BY '.implode(',',$keys).' HAVING COUNT(*)>1 LIMIT 1';
                        if($this->scalar($sql))throw new \RuntimeException('Duplicate values prevent the requested unique index');
                    }
                }
            }
        }unset($step);
    }
    private function physicalIndex(string $table,string $key,array $group): array {
        $s=$this->pdo->prepare("SELECT idx.relname AS name,pg_get_indexdef(i.indexrelid) AS definition,i.indisunique,i.indisprimary,a.attname,k.ordinality FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace ns ON ns.oid=t.relnamespace JOIN pg_class idx ON idx.oid=i.indexrelid CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum WHERE ns.nspname=? AND t.relname=? AND i.indisvalid AND i.indexprs IS NULL AND i.indpred IS NULL AND k.ordinality<=i.indnkeyatts ORDER BY idx.relname,k.ordinality");$s->execute([$this->config->schema,$table]);$indexes=[];
        foreach($s->fetchAll() as $r){$indexes[$r['name']]??=['name'=>$r['name'],'definition'=>$r['definition'],'unique'=>(bool)$r['indisunique'],'primary'=>(bool)$r['indisprimary'],'columns'=>[]];$indexes[$r['name']]['columns'][]=$r['attname'];}
        $matches=array_values(array_filter($indexes,static fn($i)=>!$i['primary']&&$i['unique']===!((bool)$group[0]['Non_unique'])&&$i['columns']===array_column($group,'Column_name')));
        foreach($matches as $index)if($index['name']===$table.'_'.$key)return $index;
        if(count($matches)!==1)throw new \RuntimeException('Physical index mapping is ambiguous');return $matches[0];
    }
    private function columnInfo(string $table,string $column): array|false {return $this->row('SELECT data_type,character_maximum_length,numeric_precision,numeric_scale,is_nullable,column_default,is_identity FROM information_schema.columns WHERE table_schema=? AND table_name=? AND column_name=?',[$this->config->schema,$table,$column]);}
    private function checkInfo(string $table,string $name): array|false {return $this->row("SELECT oid::text AS oid,convalidated,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=to_regclass(?) AND conname=? AND contype='c'",[$this->target($table),$name]);}
    private function ensureCheck(string $table,array $column): void {
        $name=$column['dsql_not_null_constraint'];$check=$this->checkInfo($table,$name);
        if($check) {
            $actual=preg_replace('/[\s"]+/','',$check['definition']);$expected='CHECK(('.$column['Field'].'ISNOTNULL))';
            if(str_replace('NOTVALID','',$actual)!==$expected)throw new \RuntimeException('Not-null constraint definition differs from the journal');
        }else $this->pdo->exec('ALTER TABLE '.$this->target($table).' ADD CONSTRAINT '.Backup::qi($name).' CHECK ('.Backup::qi($column['Field']).' IS NOT NULL) NOT VALID');
    }
    private function validateCheck(string $table,array $column,array $op): void {
        $check=$this->checkInfo($table,$column['dsql_not_null_constraint']);
        if(!$check)throw new \RuntimeException('Expected not-null constraint is missing');
        if($check['convalidated'])return;
        $job=$this->row("SELECT job_id,status FROM sys.jobs WHERE class_id='pg_constraint'::regclass AND object_id=? ORDER BY start_time DESC LIMIT 1",[$check['oid']]);
        if(!$job) {
            $stmt=$this->pdo->query('ALTER TABLE ASYNC '.$this->target($table).' VALIDATE CONSTRAINT '.Backup::qi($column['dsql_not_null_constraint']));
            $job=['job_id'=>$stmt->fetchColumn()];$this->fault('validation-submitted',$op);
        }
        $s=$this->pdo->prepare('CALL sys.wait_for_job(?)');$s->execute([$job['job_id']]);
        if(!$s->fetchColumn())throw new \RuntimeException('Not-null validation failed');
        if(!$this->checkInfo($table,$column['dsql_not_null_constraint'])['convalidated'])throw new \PDOException('Constraint validation is not yet visible',0);
    }
    private function setDefault(string $table,array $column,string $sql): void {
        $this->pdo->exec('ALTER TABLE '.$this->target($table).' ALTER COLUMN '.Backup::qi($column['Field']).(($column['HasDefault']??false)?' SET DEFAULT '.$sql:' DROP DEFAULT'));
    }
    private function waitIndex(string $name): void {
        $deadline=microtime(true)+90;
        do {
            $state=$this->row('SELECT i.indisvalid,i.indexrelid::text AS oid FROM pg_index i WHERE i.indexrelid=to_regclass(?)',[$this->target($name)]);
            if(!$state)throw new \RuntimeException('Index disappeared during automatic migration');
            if($state['indisvalid'])return;
            $job=$this->row('SELECT job_id,status FROM sys.jobs WHERE object_id=? ORDER BY start_time DESC LIMIT 1',[$state['oid']]);
            if($job){$s=$this->pdo->prepare('CALL sys.wait_for_job(?)');$s->execute([$job['job_id']]);if(!$s->fetchColumn())throw new \RuntimeException('Index build failed');}
            else usleep(200000);
        }while(microtime(true)<$deadline);
        throw new \PDOException('Index build remains in progress',0);
    }
    private function apply(array &$step,array &$op): void {
        $kind=$step['kind'];$p=$this->connect();
        if($kind==='create') {
            $t=$step['table'];$name=$t['name'];
            if(!$this->oid($name))$p->exec($step['ddl'][0]);
            $actual=$p->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');$actual->execute([$this->config->schema,$name]);
            if($actual->fetchAll(PDO::FETCH_COLUMN)!==array_column($t['columns'],'Field'))throw new \RuntimeException('Existing CREATE recovery target has unexpected columns');
            foreach($t['columns'] as $c) {
                $info=$this->columnInfo($name,$c['Field']);$expected=Plan::type($c);$type=$info['data_type'];
                if(str_contains($expected,'GENERATED BY DEFAULT'))$expected='bigint';
                if(str_starts_with($expected,'varchar('))$type='varchar('.$info['character_maximum_length'].')';
                if(str_starts_with($expected,'numeric('))$type='numeric('.$info['numeric_precision'].','.$info['numeric_scale'].')';
                if($type!==$expected||$info['is_nullable']!==$c['Null']||($info['is_identity']==='YES')!==str_contains($c['Extra'],'auto_increment'))throw new \RuntimeException('CREATE recovery type or constraint differs from the journal');
            }
            foreach(array_slice($step['ddl'],1) as $sql){preg_match('/INDEX ASYNC "((?:[^"]|"")+)"/',$sql,$m);$index=str_replace('""','"',$m[1]);if(!$this->scalar('SELECT to_regclass(?)::text',[$this->target($index)]))$p->query($sql);$this->waitIndex($index);}
            $role=Backup::qi($this->config->user);$p->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.$this->target($name).' TO '.$role);
            foreach($t['columns'] as $column)if(str_contains($column['Extra'],'auto_increment')) {
                $sequence=$this->scalar('SELECT pg_get_serial_sequence(?,?)',[$this->target($name),$column['Field']]);
                $parts=explode('.',str_replace('"','',$sequence));if(count($parts)!==2)throw new \RuntimeException('Unexpected identity sequence name');
                $p->exec('GRANT USAGE,SELECT,UPDATE ON SEQUENCE '.Backup::qi($parts[0]).'.'.Backup::qi($parts[1]).' TO '.$role);
            }
            return;
        }
        $table=$step['table']??null;$target=$table?$this->target($table):null;
        if($kind==='add_column') {
            $c=$step['column'];$field=Backup::qi($c['Field']);$info=$this->columnInfo($table,$c['Field']);
            if(!$info){$p->exec("ALTER TABLE $target ADD COLUMN $field ".Plan::type($c));$this->fault('column-added',$op);}
            else {
                $type=Plan::type($c);$actual=$info['data_type'];if(str_starts_with($type,'varchar('))$actual='varchar('.$info['character_maximum_length'].')';if(str_starts_with($type,'numeric('))$actual='numeric('.$info['numeric_precision'].','.$info['numeric_scale'].')';
                if($type!==$actual)throw new \RuntimeException('Existing ADD recovery column differs from the journal');
            }
            if($c['Default']!==null)$p->exec("ALTER TABLE $target ALTER COLUMN $field SET DEFAULT ".$step['default_sql']);
            if($c['Null']==='NO')$this->ensureCheck($table,$c);
            if($c['Default']!==null&&$step['pk']) {
                $keys=implode(',',array_map([Backup::class,'qi'],$step['pk']));
                $sql="UPDATE $target SET $field=".$step['fill_sql']." WHERE ($keys) IN (SELECT $keys FROM $target WHERE $field IS NULL LIMIT 100) AND $field IS NULL";
                while($p->exec($sql)>0){$this->fault('backfill-batch',$op);}
            }
            if($c['Null']==='NO') {
                $this->validateCheck($table,$c,$op);
            }
        } elseif($kind==='default')$this->setDefault($table,$step['column'],$step['default_sql']);
        elseif($kind==='nullable') {
            $old=$step['before'];$field=Backup::qi($step['column']['Field']);
            if(isset($old['dsql_not_null_constraint']))$p->exec("ALTER TABLE $target DROP CONSTRAINT IF EXISTS ".Backup::qi($old['dsql_not_null_constraint']));
            else $p->exec("ALTER TABLE $target ALTER COLUMN $field DROP NOT NULL");
        } elseif($kind==='add_index') {if(!$this->scalar('SELECT to_regclass(?)::text',[$this->target($step['physical_name'])]))$p->query($step['sql']);$this->waitIndex($step['physical_name']);}
        elseif($kind==='drop_index')$p->exec('DROP INDEX IF EXISTS '.$this->target($step['physical_name']));
        elseif($kind==='rename_column') {if($this->columnInfo($table,$step['old']))$p->exec("ALTER TABLE $target RENAME COLUMN ".Backup::qi($step['old']).' TO '.Backup::qi($step['new']));elseif(!$this->columnInfo($table,$step['new']))throw new \RuntimeException('Column rename recovery target is missing');}
        elseif($kind==='rename') {if($this->oid($step['old']))$p->exec('ALTER TABLE '.$this->target($step['old']).' RENAME TO '.Backup::qi($step['new']));elseif(!$this->oid($step['new']))throw new \RuntimeException('Table rename recovery target is missing');}
        else throw new \RuntimeException('Unknown automatic schema operation');
    }
    private function rollback(array $op): void {
        $p=$this->connect();
        while($op['rollback_position']>=0) {
            $step=$op['steps'][$op['rollback_position']];$kind=$step['kind'];$target=isset($step['table'])&&is_string($step['table'])?$this->target($step['table']):null;
            if($kind==='create') {if($this->oid($step['table']['name'])){if((int)$this->scalar('SELECT COUNT(*) FROM '.$this->target($step['table']['name'])))throw new \RuntimeException('Refusing to remove a populated CREATE recovery target');$p->exec('DROP TABLE '.$this->target($step['table']['name']));}}
            elseif($kind==='add_column') {
                if($this->columnInfo($step['table'],$step['column']['Field'])) {
                    $field=Backup::qi($step['column']['Field']);$type=Plan::type($step['column']);$expected=$step['fill_sql'];
                    if($this->scalar("SELECT 1 FROM $target WHERE $field IS NOT NULL AND CAST($field AS text) IS DISTINCT FROM CAST(CAST($expected AS $type) AS text) LIMIT 1"))throw new \RuntimeException('New column contains concurrent data; automatic rollback stopped');
                    $p->exec("ALTER TABLE $target DROP COLUMN $field");
                }
            }
            elseif($kind==='default'){$column=$step['before'];$column['Field']=$step['column']['Field'];$this->setDefault($step['table'],$column,$step['undo_default_sql']);}
            elseif($kind==='add_index')$p->exec('DROP INDEX IF EXISTS '.$this->target($step['physical_name']));
            elseif($kind==='drop_index') {if(!$this->scalar('SELECT to_regclass(?)::text',[$this->target($step['physical_name'])]))$p->query($step['undo_sql']);$this->waitIndex($step['physical_name']);}
            elseif($kind==='rename_column') {if($this->columnInfo($step['table'],$step['new']))$p->exec("ALTER TABLE $target RENAME COLUMN ".Backup::qi($step['new']).' TO '.Backup::qi($step['old']));}
            elseif($kind==='rename') {if($this->oid($step['new']))$p->exec('ALTER TABLE '.$this->target($step['new']).' RENAME TO '.Backup::qi($step['old']));}
            elseif($kind==='nullable') {
                $c=$step['before'];$c['Field']=$step['column']['Field'];
                $c['dsql_not_null_constraint']??='__wpd_nn_'.substr(hash('sha256',$step['table'].'|'.$c['Field']),0,24);
                $this->ensureCheck($step['table'],$c);
                $this->validateCheck($step['table'],$c,$op);
                foreach($op['before']['columns'] as &$old)if($old['Field']===$step['before']['Field'])$old['dsql_not_null_constraint']=$c['dsql_not_null_constraint'];unset($old);
            }
            $op['rollback_position']--;$this->write('pending.json',$op);
        }
        $this->complete($op);
    }
}
