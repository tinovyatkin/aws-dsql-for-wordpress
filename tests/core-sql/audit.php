<?php
/** Report translation acceptance, not execution correctness. No database connection. */
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/pg4wp/dsql/class-dsql-schema-catalog.php';
use WPDSQL\MySQL\Translation\{Shape,Compiler,Renderer};
use WPDSQL\Schema\{Introspection,Ddl};

// Only enough synthetic physical metadata to render these probes. Unexpected queries fail.
$pdo = new class extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if (!str_starts_with($query,'SELECT column_name,') && !str_starts_with($query,'SELECT i.indexrelid,')) throw new LogicException('Audit metadata fixture lacks: '.$query);
        return new class($query) extends PDOStatement {
            public function __construct(private string $sql) {}
            public function execute(?array $params=null):bool {return true;}
            public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
                if (str_starts_with($this->sql,'SELECT i.indexrelid,')) return [['indexrelid'=>'1','indisprimary'=>false,'indisunique'=>true,'attname'=>'option_name','ordinality'=>1]];
                $columns=['ID'=>'bigint','post_id'=>'bigint','user_id'=>'bigint','option_id'=>'bigint','post_date'=>'timestamp without time zone','post_title'=>'text','post_excerpt'=>'text','post_content'=>'text','post_password'=>'text','meta_key'=>'text','meta_value'=>'text','option_name'=>'text','option_value'=>'text','autoload'=>'text'];
                $rows=[];foreach($columns as $name=>$type)$rows[]=['column_name'=>$name,'data_type'=>$type,'is_nullable'=>'NO','column_default'=>null,'is_identity'=>'NO'];return $rows;
            }
        };
    }
};
$report=[];
foreach (require __DIR__.'/cases.php' as $name=>[$source,$sql]) {
    $phase='compile';
    try {
        if (preg_match('/^(SHOW|CHECK|REPAIR|OPTIMIZE)\b/i',$sql) || str_contains($sql,'information_schema.')) {
            $phase='metadata';
            (new Introspection($pdo,new DSQL_Schema_Catalog($pdo),'wp_live','postgres'))->query($sql);
        } elseif (str_starts_with($sql,'ALTER ')) {
            $phase='ddl'; (new Ddl($sql))->parse();
        } else {
            $shape=new Shape($sql);$plan=(new Compiler($shape))->compile();$phase='render';
            $translated=(new Renderer($pdo,'wp_live',false))->statements($plan['body'],$shape);
        }
        $report[$name]=['source'=>$source,'status'=>'accepted-not-executed','phase'=>$phase];
    } catch (RuntimeException $e) {
        $report[$name]=['source'=>$source,'status'=>'rejected','phase'=>$phase,'error'=>$e->getMessage()];
    }
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
