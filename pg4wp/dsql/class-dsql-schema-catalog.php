<?php
/** Versioned logical MySQL catalog with unchanged physical-schema fingerprints. */
require_once dirname(__DIR__,2).'/vendor/autoload.php';
use WPDSQL\Schema\Model;
final class DSQL_Schema_Catalog {
    public const TABLE='__wp_dsql_schema';
    public const CONTROL='__catalog__';
    private array $cache=[];
    private ?bool $present=null;
    public function __construct(private PDO $pdo) {}
    public function create(bool $managed=true): void {
        $this->pdo->exec('CREATE TABLE "'.self::TABLE.'" (table_name text PRIMARY KEY, metadata text NOT NULL, fingerprint text NOT NULL)');
        $s=$this->pdo->prepare('INSERT INTO "'.self::TABLE.'" (table_name,metadata,fingerprint) VALUES (?,?,?)');
        $s->execute([self::CONTROL,json_encode(['schema_version'=>2,'managed'=>$managed],JSON_THROW_ON_ERROR),'control']);$this->present=true;
    }
    public function fingerprint(string $table,string $schema='public'): string {
        $s=$this->pdo->prepare("SELECT column_name,data_type,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale,datetime_precision,is_identity FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position");$s->execute([$schema,$table]);$columns=$s->fetchAll(PDO::FETCH_ASSOC);if(!$columns)throw new RuntimeException('Catalogued physical table is missing: '.$table);
        $s=$this->pdo->prepare("SELECT indexname,indexdef FROM pg_indexes WHERE schemaname=? AND tablename=? ORDER BY indexname");$s->execute([$schema,$table]);$indexes=$s->fetchAll(PDO::FETCH_ASSOC);
        $normalize=static fn($rows)=>array_map(static fn($r)=>array_map(static fn($v)=>$v===null?null:(string)$v,$r),$rows);
        return hash('sha256',json_encode([$normalize($columns),$normalize($indexes)],JSON_THROW_ON_ERROR));
    }
    private function metadata(array $table): array {
        $table=Model::normalize($table);
        return ['schema_version'=>2,'name'=>$table['name'],'table_options'=>$table['table_options'],'mysql_ddl'=>$table['mysql_ddl'],'columns'=>$table['columns'],'source_indexes'=>$table['indexes'],
            'indexes'=>array_values(array_filter($table['indexes'],fn($i)=>!in_array($i['Key_name'],$table['omitted_indexes']??[],true))),
            'target_schema'=>$table['target_schema']??'public','archived'=>$table['archived']??false,'omitted_indexes'=>$table['omitted_indexes']??[],'value_codec'=>$table['value_codec']??false];
    }
    public function put(array $table): void {
        $metadata=$this->metadata($table);
        $s=$this->pdo->prepare('INSERT INTO "'.self::TABLE.'" (table_name,metadata,fingerprint) VALUES (?,?,?)');
        $s->execute([$table['name'],json_encode($metadata,JSON_THROW_ON_ERROR),$this->fingerprint($table['name'],$table['target_schema']??'public')]);unset($this->cache[$table['name']]);
    }
    public function raw(string $table): ?array {
        if(!$this->exists())return null;
        try{$s=$this->pdo->prepare('SELECT metadata,fingerprint FROM "'.self::TABLE.'" WHERE table_name=?');$s->execute([$table]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
        catch(PDOException $e){if((string)$e->getCode()==='42P01')return null;throw $e;}
    }
    public function get(string $table): ?array {
        if(str_starts_with($table,'__'))throw new RuntimeException('Internal catalog rows are not application tables');
        if(array_key_exists($table,$this->cache))return $this->cache[$table];
        $r=$this->raw($table);if(!$r)return $this->cache[$table]=null;
        $metadata=json_decode($r['metadata'],true,512,JSON_THROW_ON_ERROR);
        if(($metadata['state']??'ready')!=='ready')throw new RuntimeException('Incomplete installation schema: '.$table.'; inspect or reset the new installation before continuing');
        if(!hash_equals($r['fingerprint'],$this->fingerprint($table,$metadata['target_schema']??'public')))throw new RuntimeException('DSQL schema differs from its restored metadata: '.$table);
        return $this->cache[$table]=Model::normalize($metadata+['name'=>$table]);
    }
    public function replace(array $table,string $previous): void {
        $this->pdo->beginTransaction();
        try{$this->remove($previous);$this->put($table);$this->pdo->commit();}
        catch(Throwable $error){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $error;}
        $this->clear();
    }
    public function remove(string $table): void {
        $s=$this->pdo->prepare('DELETE FROM "'.self::TABLE.'" WHERE table_name=?');$s->execute([$table]);unset($this->cache[$table]);
    }
    public function clear(): void {$this->cache=[];$this->present=null;}
    public function exists(): bool {
        if($this->present!==null)return $this->present;
        $s=$this->pdo->prepare("SELECT 1 AS catalog_exists FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relname=? AND c.relkind='r'");$s->execute([self::TABLE]);
        return $this->present=$s->fetchColumn()!==false;
    }
    public function managed(): bool {
        if(!$this->exists())return false;
        $control=$this->raw(self::CONTROL);if(!$control)return true; // Legacy catalogs stay protected.
        $data=json_decode($control['metadata'],true,512,JSON_THROW_ON_ERROR);
        if(($data['schema_version']??null)!==2||!is_bool($data['managed']??null))throw new RuntimeException('Unsupported catalog control record');
        return $data['managed'];
    }
    public function assertReadable(string $prefix): void {
        if(!$this->exists())return;
        try{$rows=$this->pdo->query('SELECT table_name FROM "'.self::TABLE.'" WHERE fingerprint='.$this->pdo->quote('pending'))->fetchAll(PDO::FETCH_COLUMN);}
        catch(PDOException $e){if((string)$e->getCode()==='42P01')return;throw $e;}
        foreach($rows as $name)if(str_starts_with($name,$prefix))throw new RuntimeException('Incomplete installation schema: '.$name.'; inspect or reset the new installation before continuing');
    }
    public function names(): array {
        if(!$this->exists())return [];
        $s=$this->pdo->prepare('SELECT table_name FROM "'.self::TABLE.'" WHERE table_name<>? ORDER BY table_name');$s->execute([self::CONTROL]);return $s->fetchAll(PDO::FETCH_COLUMN);
    }
    public function beginInstallation(array $table): void {
        if(!$this->exists())$this->create(false);
        if($this->managed())throw new RuntimeException('Managed schema requires the controlled upgrade runner');
        if($this->raw($table['name']))throw new RuntimeException('Logical table already exists or installation is incomplete');
        $metadata=$this->metadata($table);$metadata['state']='pending';
        $s=$this->pdo->prepare('INSERT INTO "'.self::TABLE.'" (table_name,metadata,fingerprint) VALUES (?,?,?)');$s->execute([$table['name'],json_encode($metadata,JSON_THROW_ON_ERROR),'pending']);$this->clear();
    }
    public function finishInstallation(array $table): void {
        $raw=$this->raw($table['name']);if(!$raw||$raw['fingerprint']!=='pending')throw new RuntimeException('Missing pending installation catalog entry');
        $metadata=$this->metadata($table);
        $s=$this->pdo->prepare('UPDATE "'.self::TABLE.'" SET metadata=?,fingerprint=? WHERE table_name=? AND metadata=? AND fingerprint=?');
        $s->execute([json_encode($metadata,JSON_THROW_ON_ERROR),$this->fingerprint($table['name'],$table['target_schema']??'public'),$table['name'],$raw['metadata'],'pending']);
        if($s->rowCount()!==1)throw new RuntimeException('Installation catalog changed concurrently');$this->clear();
    }
    /** Explicit controlled-session migration; legacy JSON is journaled before each conditional update. */
    public function migrate(string $journalDirectory,?Closure $afterWrite=null): int {
        $count=0;
        foreach($this->names() as $name){
            $raw=$this->raw($name);$stored=json_decode($raw['metadata'],true,512,JSON_THROW_ON_ERROR);
            $logical=$this->get($name);if(($stored['schema_version']??1)===2)continue;
            $path=$journalDirectory.'/catalog-v1-'.hash('sha256',$name).'.json';
            if(!is_file($path)){\WPDSQLUpgrade\Session::write($path,['table'=>$name]+$raw);}else{
                $saved=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);if($saved['metadata']!==$raw['metadata']||$saved['fingerprint']!==$raw['fingerprint'])throw new RuntimeException('Legacy catalog differs from its migration journal');
            }
            $s=$this->pdo->prepare('UPDATE "'.self::TABLE.'" SET metadata=? WHERE table_name=? AND metadata=? AND fingerprint=?');
            $s->execute([json_encode($logical,JSON_THROW_ON_ERROR),$name,$raw['metadata'],$raw['fingerprint']]);
            if($s->rowCount()!==1)throw new RuntimeException('Catalog changed during migration');$count++;if($afterWrite)$afterWrite();
        }
        if(!$this->raw(self::CONTROL)){
            $s=$this->pdo->prepare('INSERT INTO "'.self::TABLE.'" (table_name,metadata,fingerprint) VALUES (?,?,?)');$s->execute([self::CONTROL,json_encode(['schema_version'=>2,'managed'=>true],JSON_THROW_ON_ERROR),'control']);
        }
        $this->clear();return $count;
    }
}
