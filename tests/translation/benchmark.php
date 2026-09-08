<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/pg4wp/dsql/class-dsql-sql.php';
$directory=$argv[1]??throw new RuntimeException('Private cache directory required');
define('DSQL_TRANSLATION_CACHE_DIR',$directory);
// Fixed schema responses isolate translation CPU from network/database latency.
$pdo=new class extends PDO {
 public function __construct(){}
 public function prepare(string $query,array $options=[]):PDOStatement|false {
  return new class extends PDOStatement {
   public function execute(?array $params=null):bool {return true;}
   public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
    return array_map(fn($r)=>['column_name'=>$r[0],'data_type'=>$r[1],'is_nullable'=>'NO','column_default'=>null,'is_identity'=>'NO'],[['ID','bigint'],['post_title','text'],['post_status','character varying']]);
   }
  };
 }
};
$sql=static fn($id)=>"SELECT p.ID,p.post_title FROM wp_posts p WHERE p.post_status='publish' AND p.ID IN ($id,".($id+1).','.($id+2).') ORDER BY p.ID DESC LIMIT 20,10';
$start=hrtime(true);$translator=new DSQL_SQL($pdo);$translator->translate($sql(1000));$first=(hrtime(true)-$start)/1e6;
$loaded=class_exists(WPDSQL\MySQL\Generated\MySQLParser::class,false);
$times=[];for($i=0;$i<1000;$i++){$q=$sql(1000+$i);$start=hrtime(true);$translator->translate($q);$times[]=(hrtime(true)-$start)/1e6;}
sort($times);
echo json_encode(['first_translation_ms'=>$first,'parser_loaded'=>$loaded,'median_ms'=>$times[499],'p95_ms'=>$times[949],'mean_ms'=>array_sum($times)/count($times),'peak_allocated_mb'=>memory_get_peak_usage(true)/1048576,'stats'=>$translator->stats()],JSON_PRETTY_PRINT)."\n";
