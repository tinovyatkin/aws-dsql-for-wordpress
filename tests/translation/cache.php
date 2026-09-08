<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/pg4wp/dsql/class-dsql-sql.php';
use WPDSQL\MySQL\Translation\Shape;
use WPDSQL\MySQL\Translation\PlanCache;
$dir=$argv[2]??sys_get_temp_dir().'/dsql-cache-test-'.bin2hex(random_bytes(8));
define('DSQL_TRANSLATION_CACHE_DIR',$dir);
$pdo=new class extends PDO {public function __construct(){}};
$t=new DSQL_SQL($pdo);
if(($argv[1]??'')==='child') {
 $result=$t->translate("SELECT CONCAT('different-secret', LOWER('B'))");
 if($t->stats()['disk_hits']!==1||$t->stats()['compilations']!==0||class_exists(WPDSQL\MySQL\WordPress\WP_Parser::class,false))throw new RuntimeException('Persistent hit loaded the parser');
 echo "PASS separate-process cache hit without parser loading\n";exit;
}
$checks=0;
function verify(bool $ok,string $name):void {global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
try {
 $a=$t->translate("SELECT CONCAT('original-secret', LOWER('A'))");
 $b=$t->translate("SELECT CONCAT('replacement-secret', LOWER('B'))");
 verify($a[0]['params']===['original-secret','A']&&$b[0]['params']===['replacement-secret','B'],'Cache binds current values');
 verify($a[0]['sql']===$b[0]['sql']&&$t->stats()['compilations']===1&&$t->stats()['hits']===1,'Repeated shape compiles once');
 foreach(glob($dir.'/*/*.json') as $file){$raw=file_get_contents($file);verify(!str_contains($raw,'original-secret')&&!str_contains($raw,'replacement-secret'),'Cache stores no parameter values');}
 $command=[PHP_BINARY,__FILE__,'child',$dir];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);verify(proc_close($process)===0,'Child cache: '.$error);echo $output;
 $a=$t->translate('SELECT 10,20 ORDER BY 1');$b=$t->translate('SELECT 30,40 ORDER BY 2');
 verify(str_contains($b[0]['sql'],'ORDER BY 2')&&!str_contains($b[0]['sql'],'10'),'Structural ordinal changes remain current');
 verify((new Shape("SELECT N'x'"))->key!==(new Shape("SELECT N 'x'"))->key,'Whitespace-sensitive national string prefix remains distinct');
 verify((new Shape('SELECT COUNT(1)'))->key!==(new Shape('SELECT COUNT/**/(1)'))->key,'Comments preserve lexical function boundaries');
 verify((new Shape('SELECT 1abc'))->key!==(new Shape('SELECT 2abc'))->key,'Digit-leading identifiers do not collide');
 verify((new Shape('SELECT 1'))->key!==(new Shape('SELECT 1.5'))->key,'Numeric grammar classes do not collide');
 verify((new Shape("SELECT 'a; b'"))->key!==(new Shape("SELECT 'a'; SELECT 'b'"))->key,'Literal semicolons do not collide with statements');
 $sql="SELECT 'remembered-value'";$t->rememberPrepared($sql);$t->translate($sql);verify($t->stats()['prepared_hits']===1,'Unchanged prepared SQL uses its captured shape');
 $t->translate($sql.' AS label');verify($t->stats()['prepared_hits']===1,'Modified SQL takes the general path');
 $a=$t->translate("SELECT 1 AS 'first'");$b=$t->translate("SELECT 2 AS 'second'");verify(str_contains($b[0]['sql'],'"second"')&&!str_contains($b[0]['sql'],'"first"'),'Quoted alias is structural, not a bound value');
 $cache=new PlanCache('bounded',$dir,3);for($i=0;$i<12;$i++)$cache->put(hash('sha256',(string)$i),['kind'=>'test']);
 $sub=$dir.'/'.hash('sha256',PlanCache::buildFingerprint().'|bounded');verify(count(glob($sub.'/*.json'))<=3,'Persistent entry count bounded');
 file_put_contents($sub.'/'.hash('sha256','11').'.json','broken');$cold=new PlanCache('bounded',$dir,3);verify($cold->get(hash('sha256','11'))===null,'Corrupt entry is a cache miss');
 $valid=new PlanCache('tampered',$dir);$valid->put('entry',['kind'=>'test']);$path=$dir.'/'.hash('sha256',PlanCache::buildFingerprint().'|tampered').'/entry.json';$data=json_decode(file_get_contents($path),true);$data['plan']['kind']='changed';file_put_contents($path,json_encode($data));verify((new PlanCache('tampered',$dir))->get('entry')===null,'Valid JSON with a corrupt plan is a miss');
 $disabled=new PlanCache('disabled','');$disabled->put('test',['kind'=>'ok']);verify($disabled->get('test')!==null,'Memory cache works without persistence');
 echo "PASS $checks translation-cache checks\n";
} finally {
 foreach(glob($dir.'/*')?:[] as $sub)if(is_dir($sub)&&!is_link($sub)){foreach(glob($sub.'/*')?:[] as $file)if(is_file($file)&&!is_link($file))unlink($file);rmdir($sub);}if(is_dir($dir))rmdir($dir);
}
