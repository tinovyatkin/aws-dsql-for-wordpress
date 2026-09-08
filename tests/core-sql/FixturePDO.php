<?php
/** Strict, synthetic physical metadata for offline compiler/catalog tests. */
final class CoreSqlFixturePDO extends PDO {
    public array $queries=[];
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {return new CoreSqlFixtureStatement($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {$s=$this->prepare($query);$s->execute();return $s;}
    public function rows(string $query,array $params):array {
        $this->queries[]=$query;
        if(str_contains($query,'AS catalog_exists'))return [];
        if(str_contains($query,'FROM pg_catalog.pg_tables')){
            $names=['wp_posts','wp_options','wp_postmeta','wp_usermeta'];
            if(isset($params[1]))$names=array_values(array_filter($names,fn($n)=>strcasecmp($n,$params[1])===0));
            return array_map(fn($n)=>['tablename'=>$n],$names);
        }
        if(str_starts_with($query,'SELECT COUNT(*) FROM '))return [['count'=>'21']];
        if(str_starts_with($query,'SELECT 1 FROM '))return [['readable'=>'1']];
        if(str_starts_with($query,'SELECT column_name,')){
            if(!in_array($params[1],['wp_posts','wp_options','wp_postmeta','wp_usermeta'],true))return [];
            $columns=['ID'=>'bigint','post_id'=>'bigint','user_id'=>'bigint','option_id'=>'bigint','post_date'=>'timestamp without time zone','post_title'=>'text','post_excerpt'=>'text','post_content'=>'text','post_password'=>'text','meta_key'=>'text','meta_value'=>'text','option_name'=>'text','option_value'=>'text','autoload'=>'text'];
            $rows=[];foreach($columns as $name=>$type)$rows[]=['column_name'=>$name,'data_type'=>$type,'is_nullable'=>'NO','column_default'=>null,'is_identity'=>'NO','character_maximum_length'=>null];return $rows;
        }
        if(str_starts_with($query,'SELECT i.indexrelid,'))return [
            ['indexrelid'=>'1','indisprimary'=>true,'indisunique'=>true,'attname'=>'option_id','ordinality'=>1],
            ['indexrelid'=>'2','indisprimary'=>false,'indisunique'=>true,'attname'=>'option_name','ordinality'=>1],
        ];
        if(str_starts_with($query,'SELECT t.relname AS '))return [];
        throw new LogicException('Unexpected core SQL fixture query: '.$query);
    }
}
final class CoreSqlFixtureStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private CoreSqlFixturePDO $pdo,private string $sql) {}
    public function execute(?array $params=null):bool {$this->rows=$this->pdo->rows($this->sql,$params??[]);return true;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $mode===PDO::FETCH_COLUMN?array_map(fn($r)=>reset($r),$this->rows):$this->rows;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {return array_shift($this->rows)?:false;}
    public function fetchColumn(int $column=0):mixed {return array_values($this->rows[0]??[])[$column]??false;}
}
