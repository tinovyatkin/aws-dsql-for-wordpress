<?php
/** Separate-process warmed timing; never executes queries. */
$root=dirname(__DIR__,2);
$engine=$argv[1]??'wordpress';
if(!in_array($engine,['wordpress','phpmyadmin'],true))throw new InvalidArgumentException('wordpress or phpmyadmin required');
require $root.($engine==='wordpress'?'/vendor/autoload.php':'/.local/parser-evaluation/vendor/autoload.php');
$parse=$engine==='wordpress'
 ? static fn($sql)=>WPDSQL\MySQL\SqlParser::parse($sql)
 : static function($sql){$l=new PhpMyAdmin\SqlParser\Lexer($sql);$p=new PhpMyAdmin\SqlParser\Parser($l->list);if($l->errors||$p->errors)throw new RuntimeException('Parser errors');return $p;};
$start=hrtime(true);$parse('SELECT 1');$cold=(hrtime(true)-$start)/1e6;
$queries=[];
foreach(glob($root.'/tests/stubs/*.txt') as $file)$queries[]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR)['mysql'];
// Common accepted corpus for a fair timing comparison; includes all 504 inherited fixtures.
foreach($queries as $sql)$parse($sql);
$times=[];
for($round=0;$round<5;$round++)foreach($queries as $sql){$start=hrtime(true);$parse($sql);$times[]=(hrtime(true)-$start)/1e6;}
sort($times);$n=count($times);
$result=['engine'=>$engine,'php'=>PHP_VERSION,'opcache_cli'=>ini_get('opcache.enable_cli'),'corpus'=>'504 inherited fixtures','samples'=>$n,
 'cold_first_parse_ms'=>$cold,'median_ms'=>$times[(int)floor(($n-1)*.5)],'p95_ms'=>$times[(int)floor(($n-1)*.95)],'mean_ms'=>array_sum($times)/$n,
 'peak_allocated_mb'=>memory_get_peak_usage(true)/1048576];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
