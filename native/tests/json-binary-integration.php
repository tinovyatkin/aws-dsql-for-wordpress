<?php
/** Run only after native/tests/fixture.php creates the separately tagged synthetic lab. */
require __DIR__.'/bootstrap.php';require $root.'/vendor/autoload.php';$s=lab();$f=fixture();
$process=proc_open(['aws','--profile',$s['profile'],'--region',$s['region'],'dsql','get-cluster','--identifier',explode('.',$s['endpoint'])[0]],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
$cluster=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($process)!==0||($cluster['tags']['Purpose']??'')!=='synthetic-wordpress-compatibility')throw new RuntimeException('Live synthetic cluster required');
$n=new DsqlNativeEngine($s['endpoint'],$s['region'],$s['profile'],'admin','public',$f['prefix'],$f['revision'],null,true);
$p=WPDSQL\Engine\AwsConnection::connect(new WPDSQL\Engine\Config(host:$s['endpoint'],region:$s['region'],profile:$s['profile']));
$t=$f['prefix'].'types';$b=$f['prefix'].'jsonb';$checks=0;
function typed_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try {
 $n->query("CREATE TABLE `$t` (id int NOT NULL PRIMARY KEY,doc json,payload blob,small_payload varbinary(255),note longtext)");
 $json='{ "large":123456789012345678901234567890, "duplicate":1, "duplicate":2, "decimal":1.2300, "unicode":"🌍", "array":[true,null] }';
 foreach(["", "abc\\x41\0'🌍",'~dsqlb64:v1:literal'] as $i=>$value){
  $id=$i+1;$q=$n->escape($value);$j=$n->escape($json);
  $n->query("INSERT INTO `$t` (id,doc,payload,small_payload,note) VALUES ($id,'$j','$q','$q','$q')");
  $row=$n->query("SELECT doc,payload,small_payload,note FROM `$t` WHERE id=$id")['rows'][0];
  typed_check($row===['doc'=>$json,'payload'=>$value,'small_payload'=>$value,'note'=>$value],'JSON/binary/TEXT round trip with codec enabled');
  $physical=$p->query("SELECT doc::text AS doc,encode(payload,'hex') AS payload FROM \"$t\" WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
  typed_check($physical===['doc'=>$json,'payload'=>bin2hex($value)],'Physical bytes agree with PDO reference');
 }
 $changed="updated\0\\x00 🌍";$escaped=$n->escape($changed);$updatedJson='[false,null,1.00,{"x":"~dsqlb64:v1:literal"}]';$escapedJson=$n->escape($updatedJson);
 $n->query("UPDATE `$t` SET doc='$escapedJson',payload='$escaped',small_payload='$escaped',note='$escaped' WHERE id=1");
 typed_check($n->query("SELECT doc,payload,small_payload,note FROM `$t` WHERE id=1")['rows'][0]===['doc'=>$updatedJson,'payload'=>$changed,'small_payload'=>$changed,'note'=>$changed],'UPDATE binds JSON and binary values');
 typed_check($n->query("SELECT id FROM `$t` WHERE payload='$escaped'")['rows'] === [['id'=>'1']],'Binary comparison uses the same byte encoding');
 $n->query("INSERT INTO `$t` (id,doc,payload) VALUES (4,NULL,NULL),(5,'null','')");
 typed_check($n->query("SELECT doc,payload FROM `$t` WHERE id=4")['rows'][0]===['doc'=>null,'payload'=>null],'SQL NULL values');
 typed_check($n->query("SELECT doc,payload FROM `$t` WHERE id=5")['rows'][0]===['doc'=>'null','payload'=>''],'JSON null and empty binary are not SQL NULL');
 typed_check($n->query("SELECT CAST('$j' AS JSON) AS doc")['rows'][0]['doc']===$json,'Computed JSON result');
 $p->exec("CREATE TABLE \"$b\" (id bigint PRIMARY KEY,doc jsonb)");
 $insert=$p->prepare("INSERT INTO \"$b\" VALUES (1,CAST(? AS jsonb))");$insert->execute([$json]);
 $expected=$p->query("SELECT doc::text FROM \"$b\" WHERE id=1")->fetchColumn();
 typed_check($n->query("SELECT doc FROM `$b` WHERE id=1")['rows'][0]['doc']===$expected,'JSONB versioned binary result matches PostgreSQL text');
 $n->query("UPDATE `$b` SET doc='$escapedJson' WHERE id=1");
 $expected=$p->query("SELECT doc::text FROM \"$b\" WHERE id=1")->fetchColumn();
 typed_check($n->query("SELECT doc FROM `$b` WHERE id=1")['rows'][0]['doc']===$expected,'JSONB assignment and read');
 typed_check($n->cacheStats()['hits']>0,'Type round trips exercise cached plans');
 echo "PASS $checks native JSON/binary live contracts\n";
} finally {
 $p->exec("DROP TABLE IF EXISTS \"$b\"");$n->query("DROP TABLE IF EXISTS `$t`");$n->close();
}
