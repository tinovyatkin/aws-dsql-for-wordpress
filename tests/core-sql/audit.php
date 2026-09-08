<?php
/** Report translation acceptance, not execution correctness. No database connection. */
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/pg4wp/dsql/class-dsql-schema-catalog.php';
use WPDSQL\MySQL\Translation\{Shape,Compiler,Renderer};
use WPDSQL\Schema\{Introspection,Ddl};

require __DIR__.'/FixturePDO.php';
$pdo=new CoreSqlFixturePDO();
$report=[];
foreach (require __DIR__.'/cases.php' as $name=>[$source,$sql]) {
    $phase='compile';
    try {
        if (preg_match('/^(SHOW|CHECK|REPAIR|OPTIMIZE|ANALYZE)\b/i',$sql) || str_contains($sql,'information_schema.')) {
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

if(in_array('--require-supported',$argv,true))exit(count(array_filter($report,fn($r)=>$r['status']==='rejected'))?1:0);
