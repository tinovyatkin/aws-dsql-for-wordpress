<?php
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-schema-catalog.php';
final class CatalogTestPdo extends PDO {
    public bool $exists=true;
    public array $records=[];
    public array $queries=[];
    public function __construct(){}
    public function quote(string $s,int $type=PDO::PARAM_STR):string|false{return "'".str_replace("'","''",$s)."'";}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new CatalogTestStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{$s=$this->prepare($query);$s->execute();return $s;}
    public function rows(string $query,array $params):array {
        $this->queries[]=$query;
        if(str_contains($query,'AS catalog_exists'))return $this->exists?[['catalog_exists'=>1]]:[];
        if(str_contains($query,"fingerprint='pending'"))return array_map(fn($name)=>['table_name'=>$name],array_keys(array_filter($this->records,fn($row)=>$row['fingerprint']==='pending')));
        if(str_contains($query,'information_schema.columns'))return [['column_name'=>'id','data_type'=>'bigint','is_nullable'=>'YES']];
        if(str_contains($query,'pg_indexes'))return [];
        if(str_contains($query,'SELECT metadata,fingerprint'))return isset($this->records[$params[0]])?[$this->records[$params[0]]]:[];
        throw new RuntimeException('Unexpected test query');
    }
}
final class CatalogTestStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private CatalogTestPdo $pdo,private string $query){}
    public function execute(?array $params=null):bool{$this->rows=$this->pdo->rows($this->query,$params??[]);return true;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return array_shift($this->rows)?:false;}
    public function fetchColumn(int $column=0):mixed{return array_values($this->rows[0]??[])[$column]??false;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $mode===PDO::FETCH_COLUMN?array_map(fn($r)=>reset($r),$this->rows):$this->rows;}
}
final class catalogTest extends TestCase {
    public function test_legacy_and_restored_catalogs_remain_managed():void {
        $p=new CatalogTestPdo();self::assertTrue((new DSQL_Schema_Catalog($p))->managed());
        $p->records[DSQL_Schema_Catalog::CONTROL]=['metadata'=>json_encode(['schema_version'=>2,'managed'=>true]),'fingerprint'=>'control'];
        self::assertTrue((new DSQL_Schema_Catalog($p))->managed());
    }
    public function test_only_an_explicit_installation_catalog_is_unmanaged():void {
        $p=new CatalogTestPdo();$p->records[DSQL_Schema_Catalog::CONTROL]=['metadata'=>json_encode(['schema_version'=>2,'managed'=>false]),'fingerprint'=>'control'];
        self::assertFalse((new DSQL_Schema_Catalog($p))->managed());
        $p->exists=false;self::assertFalse((new DSQL_Schema_Catalog($p))->managed());
    }
    public function test_unknown_control_version_fails_closed():void {
        $p=new CatalogTestPdo();$p->records[DSQL_Schema_Catalog::CONTROL]=['metadata'=>json_encode(['schema_version'=>99,'managed'=>false]),'fingerprint'=>'control'];
        $this->expectException(RuntimeException::class);(new DSQL_Schema_Catalog($p))->managed();
    }
    public function test_pending_installation_blocks_only_its_configured_prefix():void {
        $p=new CatalogTestPdo();$p->records['other_table']=['metadata'=>'{}','fingerprint'=>'pending'];
        $catalog=new DSQL_Schema_Catalog($p);$catalog->assertReadable('wp_');self::assertTrue(true);
        $p->records['wp_table']=['metadata'=>'{}','fingerprint'=>'pending'];
        $this->expectException(RuntimeException::class);$catalog->assertReadable('wp_');
    }
    public function test_legacy_reads_normalize_without_writes_and_detect_physical_drift():void {
        $p=new CatalogTestPdo();$catalog=new DSQL_Schema_Catalog($p);
        $legacy=['mysql_ddl'=>'CREATE TABLE wp_t (id int)','columns'=>[['Field'=>'id','Type'=>'int','Null'=>'YES','Key'=>'','Default'=>null,'Extra'=>'','Collation'=>null]],'indexes'=>[],'target_schema'=>'public'];
        $raw=json_encode($legacy);$p->records['wp_t']=['metadata'=>$raw,'fingerprint'=>$catalog->fingerprint('wp_t')];
        self::assertSame(2,$catalog->get('wp_t')['schema_version']);self::assertSame($raw,$p->records['wp_t']['metadata']);
        foreach($p->queries as $query)self::assertStringStartsWith('SELECT',$query);
        $p->records['wp_t']['fingerprint']='drift';$catalog->clear();
        $this->expectException(RuntimeException::class);$catalog->get('wp_t');
    }
}
