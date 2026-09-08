<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use WPDSQL\MySQL\SqlParser;
use WPDSQL\MySQL\ParseException;
$root=dirname(__DIR__,2);
$corpora=['inherited'=>[], 'wordpress'=>[], 'probes'=>[]];
foreach(glob($root.'/tests/stubs/*.txt') as $file) $corpora['inherited'][basename($file)]=json_decode(file_get_contents($file),true,flags:JSON_THROW_ON_ERROR)['mysql'];
$capture=$root.'/.local/parser-evaluation/wordpress-queries.json';
if(is_file($capture)) foreach(array_values(array_unique(json_decode(file_get_contents($capture),true,flags:JSON_THROW_ON_ERROR))) as $i=>$sql) $corpora['wordpress']['query-'.$i]=$sql;
$probes=require $root.'/tests/parser-evaluation/cases.php';
foreach($probes as $name=>[$valid,$sql]) $corpora['probes'][$name]=$sql;
$report=[];$unexpected=[];$treeChecks=0;
foreach($corpora as $name=>$queries) {
 $results=[];$times=[];
 foreach($queries as $id=>$sql) {
  $start=hrtime(true);
  try {
   $q=SqlParser::parse($sql);$results[$id]=['accepted'=>true];
   foreach($q->tokens as $token)if(substr($sql,$token->start,$token->length)!==$token->get_bytes())$unexpected[]=$name.'/'.$id.': byte-span mismatch';
   if($q->sql!==$sql)$unexpected[]=$name.'/'.$id.': source mismatch';
   $treeChecks++;
   unset($q);
  }
  catch(ParseException $e){$results[$id]=['accepted'=>false,'line'=>$e->errorLine,'column'=>$e->errorColumn];}
  $times[]=(hrtime(true)-$start)/1e6;
  $expected=$name!=='probes'||$probes[$id][0];
  if($id==='multiple_statements')$expected=false; // The public API accepts one statement.
  if($results[$id]['accepted']!==$expected)$unexpected[]=$name.'/'.$id.': unexpected acceptance/rejection';
 }
 $report[$name]=['queries'=>count($queries),'accepted'=>count(array_filter($results,fn($r)=>$r['accepted'])),'results'=>$results];
 $report[$name]['first_pass_ms']=array_sum($times);
 echo $name.': '.$report[$name]['accepted'].'/'.count($queries)." parsed\n";
}
$report['source_span_checks']=$treeChecks;
$report['unexpected']=$unexpected;
$report['peak_allocated_mb']=memory_get_peak_usage(true)/1048576;
if(!is_dir($root.'/.local/standalone-parser'))mkdir($root.'/.local/standalone-parser',0700,true);
file_put_contents($root.'/.local/standalone-parser/corpus.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
if($unexpected){echo implode("\n",$unexpected)."\n";exit(1);}
echo "PASS $treeChecks source/span checks; zero unexpected results\n";
