<?php
require __DIR__.'/bootstrap.php';
require $root.'/vendor/autoload.php';
$s=lab();$f=fixture();
$c=new WPDSQL\Engine\Config(host:$s['endpoint'],profile:$s['profile'],region:$s['region'],tablePrefix:$f['prefix']);
$d=new WPDSQL\Engine\NativeDriver($c);
if($d->isConnected())throw new RuntimeException('Native construction must remain lazy');
if($d->escape("a'b")!=="a\\'b")throw new RuntimeException('Escape contract');
$d->rememberPrepared("SELECT ID,post_title FROM `{$f['posts']}` WHERE ID=1");
$r=$d->query("SELECT ID,post_title FROM `{$f['posts']}` WHERE ID=1");
if($r->columnCount()!==2||$r->rowCount()!==1||$r->fetch(PDO::FETCH_NUM)!==['1','Synthetic story 1'])throw new RuntimeException('Buffered result contract');
$meta=$d->columnMeta($r,0);
if(($meta['mysqli:type']??null)!==8||($meta['mysqli:orgname']??null)!=='ID')throw new RuntimeException('Logical column descriptor');
try{$d->query("SELECT name FROM `{$f['prefix']}missing`");throw new RuntimeException('Missing table was accepted');}
catch(WPDSQL\Engine\QueryException $e){if($e->sqlState()!=='42P01')throw new RuntimeException('Native SQLSTATE was lost');}
if($d->cacheStats()['prepared_hits']<1)throw new RuntimeException('Prepare capture contract');
$d->close();echo "PASS native PHP engine bridge contracts\n";
