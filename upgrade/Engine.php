<?php
namespace WPDSQLUpgrade;
use WPDSQLMigration\Backup;
use WPDSQLMigration\Plan;
require_once __DIR__.'/Session.php';
require_once dirname(__DIR__).'/migration/Backup.php';
require_once dirname(__DIR__).'/migration/Plan.php';
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-value-codec.php';
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
require_once __DIR__.'/Schema.php';

/** Offline schema executor. All replacement data is verified before publishing. */
final class Engine {
    private \DSQL_Schema_Catalog $catalog;
    private bool $synchronousIndexes=false;
    public function __construct(private \PDO $pdo,public Session $session) {
        $this->catalog=new \DSQL_Schema_Catalog($pdo);
        if($pdo->query('SELECT current_user')->fetchColumn()!==$session->data['target']['user'])throw new \RuntimeException('Wrong upgrade database role');
        $pdo->exec('SET search_path TO wp_live, pg_catalog');
        // Replacements are empty at index creation. Use DSQL's supported fast
        // path there; retain asynchronous jobs for endpoints without it.
        try{$pdo->exec('SET disable_sync_create_index=off');$this->synchronousIndexes=true;}
        catch(\PDOException $error){if(!in_array((string)$error->getCode(),['0A000','42704'],true))throw $error;}
        $s=$pdo->query("SELECT run_id FROM wp_live.__wp_dsql_upgrade_lock WHERE id='schema'");
        if($s->fetchColumn()!==$session->data['id'])throw new \RuntimeException('Database is not reserved by this upgrade session');
    }
    private static function q(string $name): string {return Backup::qi($name);}
    private static function table(string $name,string $schema='wp_live'): string {return self::q($schema).'.'.self::q($name);}
    private function oid(string $name,string $schema='wp_live'): ?string {
        $s=$this->pdo->prepare("SELECT c.oid::text FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=? AND c.relname=? AND c.relkind='r'");$s->execute([$schema,$name]);return $s->fetchColumn()?:null;
    }
    private function metadata(string $name): ?array {
        $m=$this->catalog->get($name);if(!$m)return null;
        return array_replace($m,['name'=>$name,'indexes'=>$m['source_indexes']??$m['indexes']]);
    }
    public function execute(string $sql): void {
        $op=$this->plan($sql);if($op===null)return;
        $this->session->pending($op);$this->run($op);
    }
    public function plan(string $sql): ?array {
        if($this->pdo->inTransaction())throw new \RuntimeException('Schema changes inside application transactions require a dedicated migration');
        $this->session->assertActive();if($this->session->operation())throw new \RuntimeException('Pending DDL must be recovered first');

        $ddl=(new Schema($sql))->parse();$before=$this->metadata($ddl['table']);$oid=$this->oid($ddl['table']);
        if($oid&&!$before)throw new \RuntimeException('Existing table is not in the migration catalog');
        if(!$oid&&$before)throw new \RuntimeException('Catalogued table is missing');
        if($ddl['kind']==='drop'&&!$oid&&$ddl['if_exists'])return null;
        $after=Schema::apply($ddl,$before,$this->session->data);
        if($after&&$after['name']!==$ddl['table']&&($this->oid($after['name'])||$this->metadata($after['name'])))throw new \RuntimeException('Rename destination already exists');
        $mode='rebuild';
        if(!$before)$mode='create';elseif(!$after)$mode='drop';
        elseif($ddl['kind']==='create'&&$ddl['if_exists'])return null;
        elseif($after['name']!==$before['name']&&count($ddl['changes'])===1&&$ddl['changes'][0]['op']==='rename')$mode='rename';
        elseif(Plan::ddl($before,$this->pdo)===Plan::ddl($after,$this->pdo))$mode='metadata';
        if($before) {
            $s=$this->pdo->prepare("SELECT COUNT(*) FROM pg_constraint WHERE conrelid=?::oid AND contype NOT IN ('p','u')");$s->execute([$oid]);
            if((int)$s->fetchColumn())throw new \RuntimeException('Tables with CHECK/foreign-key constraints require a dedicated migration');
        }
        $id=bin2hex(random_bytes(8));
        $op=['id'=>$id,'mode'=>$mode,'sql'=>$sql,'before'=>$before,'after'=>$after,'original'=>$ddl['table'],'source_oid'=>$oid,
             'source_fingerprint'=>$before?$this->catalog->fingerprint($ddl['table'],'wp_live'):null,
             'temp'=>'__dsql_new_'.$id,'retained'=>'__dsql_old_'.$id,'phase'=>'planned'];
        if($after)Plan::ddl($after,$this->pdo); // Validate every resulting type/index before DDL.
        return $op;
    }
    public function recover(): void {
        $this->session->data['failed']=false;$this->session->save();$this->session->assertActive();
        if($this->session->data['catalog_migration']??false){$this->migrateCatalog();return;}
        if($op=$this->session->operation())$this->run($op);
    }
    public function cancel(): void {
        $op=$this->session->operation();if(!$op)throw new \RuntimeException('No pending operation');
        if($op['before']&&$this->oid($op['original'])!==$op['source_oid'])throw new \RuntimeException('Publication started: recover instead of cancelling');
        if(!in_array($op['phase'],['planned','copying','verified'],true))throw new \RuntimeException('Operation cannot be cancelled');
        if($this->oid($op['temp'])){$this->requireOid($op['temp'],$op['temp_oid']??'');$this->pdo->exec('DROP TABLE '.self::table($op['temp']));}
        $op['cancelled']=true;$this->session->complete($op);$this->session->data['failed']=false;$this->session->save();
    }
    private function phase(array &$op,string $phase): void {$op['phase']=$phase;$this->session->pending($op);}
    private function fault(string $point): void {
        if(($this->session->data['target']['classification']??'')==='synthetic'&&getenv('DSQL_UPGRADE_FAULT')===$point)exit(86);
    }
    private function requireOid(string $name,string $expected,string $schema='wp_live'): void {
        if($this->oid($name,$schema)!==$expected)throw new \RuntimeException('Schema object identity changed; refusing automatic recovery');
    }
    private function moveOld(array &$op): void {
        $old=$op['original'];$retained=$op['retained'];$oid=$op['source_oid'];
        if($this->oid($old)===$oid) {
            if(!hash_equals($op['source_fingerprint'],$this->catalog->fingerprint($old,'wp_live')))throw new \RuntimeException('Source schema drifted during upgrade');
            $this->pdo->exec('ALTER TABLE '.self::table($old).' RENAME TO '.self::q($retained));$this->fault('after-old-rename');
        }
        if($this->oid($retained)===$oid) {
            $this->pdo->exec('ALTER TABLE '.self::table($retained).' SET SCHEMA wp_upgrade_archive');$this->fault('after-old-move');
        }
        $this->requireOid($retained,$oid,'wp_upgrade_archive');
        $this->pdo->exec('REVOKE ALL ON '.self::table($retained,'wp_upgrade_archive').' FROM '.self::q($this->session->data['runtime_role']));
    }
    private function run(array $op): void {
        $before=$op['before'];$after=$op['after'];$mode=$op['mode'];
        if($mode==='metadata') {
            $this->requireOid($op['original'],$op['source_oid']);
            if(!hash_equals($op['source_fingerprint'],$this->catalog->fingerprint($op['original'],'wp_live')))throw new \RuntimeException('Source schema drift');
            $this->catalog->replace($after,$op['original']);$this->fault('after-catalog');$this->session->complete($op);return;
        }
        if($mode==='rename') {
            if($this->oid($op['original'])===$op['source_oid']) {
                if(!hash_equals($op['source_fingerprint'],$this->catalog->fingerprint($op['original'],'wp_live')))throw new \RuntimeException('Source schema drift');
                $this->pdo->exec('ALTER TABLE '.self::table($op['original']).' RENAME TO '.self::q($after['name']));$this->fault('after-rename');
            }
            $this->requireOid($after['name'],$op['source_oid']);$this->catalog->replace($after,$op['original']);$this->fault('after-catalog');$this->session->complete($op);return;
        }
        if($mode==='drop') {
            $this->moveOld($op);$this->catalog->remove($op['original']);$this->fault('after-catalog');$this->session->complete($op);return;
        }
        if(in_array($op['phase'],['planned','copying'],true)) {
            if($before){$this->requireOid($op['original'],$op['source_oid']);if(!hash_equals($op['source_fingerprint'],$this->catalog->fingerprint($op['original'],'wp_live')))throw new \RuntimeException('Source schema drift');}
            // Copy recovery restarts only the disposable replacement. The original
            // table remains authoritative until its entire projection verifies.
            if($this->oid($op['temp'])) {
                if(!isset($op['temp_oid'])) {
                    $s=$this->pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema='wp_live' AND table_name=? ORDER BY ordinal_position");$s->execute([$op['temp']]);
                    if($s->fetchAll(\PDO::FETCH_COLUMN)!==array_column($after['columns'],'Field')||(int)$this->pdo->query('SELECT COUNT(*) FROM '.self::table($op['temp']))->fetchColumn())throw new \RuntimeException('Unrecorded temporary table is not an empty matching replacement');
                    $op['temp_oid']=$this->oid($op['temp']);$this->session->pending($op);
                }
                if(isset($op['temp_oid'])&&$this->oid($op['temp'])!==$op['temp_oid'])throw new \RuntimeException('Unexpected temporary table');
                $this->pdo->exec('DROP TABLE '.self::table($op['temp']));
            }
            $temp=$after;$temp['name']=$op['temp'];
            $ddls=Plan::ddl($temp,$this->pdo);$this->pdo->exec(array_shift($ddls));
            $this->fault('after-create');
            $op['temp_oid']=$this->oid($op['temp']);$this->phase($op,'copying');
            $op['index_jobs']=[];
            foreach($ddls as $sql){if($this->synchronousIndexes)$sql=str_replace('INDEX ASYNC','INDEX',$sql);$statement=$this->pdo->query($sql);$job=$statement->columnCount()?$statement->fetchColumn():false;if($job){$op['index_jobs'][]=$job;$this->session->pending($op);}}
            foreach($op['index_jobs'] as $job){$s=$this->pdo->prepare('CALL sys.wait_for_job(?)');$s->execute([$job]);if(!$s->fetchColumn())throw new \RuntimeException('Replacement index build failed');}
            [$expressions,$orders]=$this->projection($op);
            $op['expressions']=$expressions;$op['orders']=$orders;$this->session->pending($op);
            $expected=$before?$this->digest($op['original'],$expressions):['count'=>0,'hash'=>Backup::digest([])];
            if($before)$this->copy($op,$expressions,$orders);
            $actual=$this->digest($op['temp'],array_map(static fn($c)=>self::q($c['Field']),$after['columns']));
            if($actual!==$expected)throw new \RuntimeException('Replacement rows failed count/hash verification');
            $op['verified_rows']=$actual;
            foreach($after['columns'] as $c) {
                if(!str_contains($c['Extra'],'auto_increment'))continue;
                $next='1';$source=$after['mapping'][$c['Field']]??null;
                if($before&&$source) {
                    $original=array_values(array_filter($before['columns'],static fn($x)=>$x['Field']===$source))[0];
                    if(str_contains($original['Extra'],'auto_increment')){
                        $s=$this->pdo->prepare('SELECT nextval(pg_get_serial_sequence(?,?))');$s->execute([self::table($op['original']),$source]);$next=(string)$s->fetchColumn();
                    }
                }
                $max=$this->pdo->query('SELECT MAX('.self::q($c['Field']).') FROM '.self::table($op['temp']))->fetchColumn();
                $next=(string)max((int)$next,(int)$max);
                $called=($before&&$source)||($max!==null&&$max!==false);
                $s=$this->pdo->prepare('SELECT setval(pg_get_serial_sequence(?,?),?,?)');$s->execute([self::table($op['temp']),$c['Field'],$next,$called?'true':'false']);
            }
            $op['temp_fingerprint']=$this->catalog->fingerprint($op['temp'],'wp_live');$this->phase($op,'verified');$this->fault('after-copy');
        }
        // Recheck after a pause/crash, not just at the end of the original copy.
        $published=$this->oid($op['temp'])===$op['temp_oid']?$op['temp']:$after['name'];
        $this->requireOid($published,$op['temp_oid']);
        if($this->digest($published,array_map(static fn($c)=>self::q($c['Field']),$after['columns']))!==$op['verified_rows'])throw new \RuntimeException('Verified replacement data changed before publication');
        if($before) {
            if($this->oid($op['original'])===$op['source_oid']&&$this->digest($op['original'],$op['expressions'])!==$op['verified_rows'])throw new \RuntimeException('Source data changed: external writers were not frozen');
            $this->moveOld($op);
        }
        if($this->oid($op['temp'])===$op['temp_oid']) {
            if(!hash_equals($op['temp_fingerprint'],$this->catalog->fingerprint($op['temp'],'wp_live')))throw new \RuntimeException('Replacement schema drift');
            if($this->oid($after['name']))throw new \RuntimeException('Publish destination occupied');
            $this->pdo->exec('ALTER TABLE '.self::table($op['temp']).' RENAME TO '.self::q($after['name']));$this->fault('after-publish');
        }
        $this->requireOid($after['name'],$op['temp_oid']);
        $this->pdo->exec('GRANT SELECT,INSERT,UPDATE,DELETE ON '.self::table($after['name']).' TO '.self::q($this->session->data['runtime_role']));
        foreach($after['columns'] as $c)if(str_contains($c['Extra'],'auto_increment')) {
            $s=$this->pdo->prepare('SELECT pg_get_serial_sequence(?,?)');$s->execute([self::table($after['name']),$c['Field']]);$seq=$s->fetchColumn();
            // Server-generated identifier is split and quoted rather than concatenated raw.
            $parts=explode('.',str_replace('"','',$seq));if(count($parts)!==2)throw new \RuntimeException('Unexpected sequence name');
            $this->pdo->exec('GRANT USAGE,SELECT,UPDATE ON SEQUENCE '.self::q($parts[0]).'.'.self::q($parts[1]).' TO '.self::q($this->session->data['runtime_role']));
        }
        $this->catalog->replace($after,$op['original']);$this->fault('after-catalog');
        $this->session->complete($op);
    }
    private function projection(array &$op): array {
        $after=$op['after'];$before=$op['before'];$expressions=[];$orders=[];
        if($before) {
            $groups=Plan::indexes($before);$orders=array_map(static fn($i)=>self::q($i['Column_name']),$groups['PRIMARY']??[]);
            if(!$orders&&(int)$this->pdo->query('SELECT COUNT(*) FROM '.self::table($before['name']))->fetchColumn())throw new \RuntimeException('Populated table rebuild requires a primary key for deterministic batches');
        }
        foreach($after['columns'] as $c) {
            $source=$after['mapping'][$c['Field']]??null;$type=explode(' GENERATED',Plan::type($c))[0];
            if($source) {
                $old=array_values(array_filter($before['columns'],static fn($x)=>$x['Field']===$source))[0];$oldType=explode(' GENERATED',Plan::type($old))[0];
                if(($type==='bytea')!==($oldType==='bytea'))throw new \RuntimeException('Binary/text type conversion requires an explicit transform');
                $expr=self::q($source);
                // Explicit PostgreSQL varchar casts truncate. Reject before casting.
                if(preg_match('/^varchar\((\d+)\)$/',$type,$m)){$s=$this->pdo->query('SELECT COUNT(*) FROM '.self::table($before['name']).' WHERE char_length(CAST('.$expr.' AS text))>'.(int)$m[1]);if($s->fetchColumn())throw new \RuntimeException('Varchar change would truncate values');}
                if($oldType!==$type&&!in_array($type,['text','bigint','integer'],true)&&!str_starts_with($type,'varchar('))throw new \RuntimeException('This type conversion needs an explicit transform');
                if($oldType!==$type&&in_array($type,['integer','bigint'],true)) {
                    $s=$this->pdo->query('SELECT '.self::q($source).' FROM '.self::table($before['name']));while(($v=$s->fetchColumn())!==false)if($v!==null&&!preg_match('/^[+-]?\d+$/D',(string)$v))throw new \RuntimeException('Integer conversion would change a noninteger value');
                }
                $expr='CAST('.$expr.' AS '.$type.')';
            } else {
                if($c['Default']==='CURRENT_TIMESTAMP'&&Plan::temporal($c)){$op['default_timestamp']??=$this->pdo->query('SELECT CURRENT_TIMESTAMP::text')->fetchColumn();$value=$op['default_timestamp'];}
                else $value=$c['Default']===null?null:Plan::convert((string)$c['Default'],$c,false,$after['value_codec']);
                if($value===null&&$c['Null']==='NO'&&$before&&(int)$this->pdo->query('SELECT COUNT(*) FROM '.self::table($before['name']))->fetchColumn())throw new \RuntimeException('New NOT NULL column needs an explicit default/backfill');
                $expr='CAST('.($value===null?'NULL':$this->pdo->quote($value)).' AS '.$type.')';
            }
            $expressions[]=$expr.' AS '.self::q($c['Field']);
        }
        return [$expressions,$orders];
    }
    private static function row(array $row): array {return array_map(static fn($v)=>is_resource($v)?stream_get_contents($v):($v===null?null:(string)$v),$row);}
    private function digest(string $table,array $expressions): array {
        $s=$this->pdo->query('SELECT '.implode(',',$expressions).' FROM '.self::table($table));$hashes=[];
        while($row=$s->fetch(\PDO::FETCH_NUM))$hashes[]=hash('sha256',Backup::row(self::row($row)));
        return ['count'=>count($hashes),'hash'=>Backup::digest($hashes)];
    }
    private function copy(array $op,array $expressions,array $orders): void {
        $offset=0;$after=$op['after'];$columns=$after['columns'];$groups=Plan::indexes($after);$indexed=[];foreach($groups as $g)foreach($g as $i)$indexed[]=$i['Column_name'];
        do {
            $sql='SELECT '.implode(',',$expressions).' FROM '.self::table($op['original']).($orders?' ORDER BY '.implode(',',$orders):'').' LIMIT 100 OFFSET '.$offset;
            $rows=$this->pdo->query($sql)->fetchAll(\PDO::FETCH_NUM);$batch=[];$bytes=0;
            foreach($rows as $raw) {
                $row=self::row($raw);$size=strlen(Backup::row($row));
                foreach($row as $i=>$value) {
                    $c=$columns[$i];$type=Plan::type($c);
                    if($value===null&&$c['Null']==='NO')throw new \RuntimeException('NOT NULL change would discard existing NULL values');
                    if($value!==null&&$type!=='bytea') {
                        $decoded=$after['value_codec']?\DSQL_Value_Codec::decode($value):$value;
                        if(str_contains($decoded,"\0")&&($type!=='text'||in_array($c['Field'],$indexed,true)))throw new \RuntimeException('Binary text cannot become indexed or non-TEXT');
                        if(str_contains(strtolower($c['Type']),'unsigned')&&preg_match('/^-\d/',$value))throw new \RuntimeException('Unsigned change rejects negative values');
                    }
                }
                if($batch&&$bytes+$size>900000){$this->insert($op['temp'],$columns,$batch);$batch=[];$bytes=0;}
                $batch[]=$row;$bytes+=$size;
            }
            if($batch)$this->insert($op['temp'],$columns,$batch);
            $offset+=count($rows);$this->fault('during-copy');
        }while(count($rows)===100);
    }
    private function insert(string $table,array $columns,array $rows): void {
        $values=[];$params=[];
        foreach($rows as $row){$parts=[];foreach($row as $i=>$v){$binary=Plan::type($columns[$i])==='bytea';$parts[]=$binary?"decode(?,'base64')":'?';$params[]=$binary&&$v!==null?base64_encode($v):$v;}$values[]='('.implode(',',$parts).')';}
        $s=$this->pdo->prepare('INSERT INTO '.self::table($table).' ('.implode(',',array_map(static fn($c)=>self::q($c['Field']),$columns)).') VALUES '.implode(',',$values));$s->execute($params);
    }
    public function migrateCatalog(): array {
        $this->session->assertActive();
        if($this->session->operation())throw new \RuntimeException('Recover pending schema changes before catalog migration');
        $this->session->data['verified']=false;$this->session->data['catalog_migration']=2;$this->session->save();
        $count=$this->catalog->migrate($this->session->directory,function(){
            if(($this->session->data['target']['classification']??'')==='synthetic'&&getenv('DSQL_UPGRADE_FAULT')==='catalog-after-write'){$this->session->fail();exit(86);}
        });
        unset($this->session->data['catalog_migration']);$this->session->save();
        return ['schema_version'=>2,'migrated_tables'=>$count];
    }
    public function verify(): array {
        if($this->session->data['catalog_migration']??false)throw new \RuntimeException('Recover the incomplete catalog migration before verification');
        $this->session->assertActive();if($this->session->operation())throw new \RuntimeException('Pending schema operation');
        $tables=$this->catalog->names();$count=0;
        foreach($tables as $table){$this->catalog->get($table);$count++;}
        return ['catalogued_tables'=>$count,'pending_operations'=>0];
    }
}
