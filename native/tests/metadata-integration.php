<?php
require __DIR__.'/bootstrap.php';
$n=native_client();$p=php_client();$f=fixture();$t=$f['posts'];$checks=0;
$queries=[
 "SHOW FULL COLUMNS FROM `$t`",
 "SHOW COLUMNS FROM `$t` LIKE 'post_%'",
 "DESCRIBE `$t`",
 "SHOW KEYS FROM `$t` WHERE Key_name='PRIMARY'",
 "SHOW CREATE TABLE `$t`",
 "SHOW TABLE STATUS LIKE '$t'",
 "SHOW FULL TABLES LIKE '{$f['prefix']}%'",
 "SHOW VARIABLES LIKE 'max_connections'",
 "SELECT TABLE_NAME AS 'table',TABLE_ROWS AS 'rows',SUM(DATA_LENGTH+INDEX_LENGTH) AS 'bytes' FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$t' GROUP BY TABLE_NAME",
 "SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_NAME='$t' ORDER BY ORDINAL_POSITION LIMIT 0,3",
 "SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_NAME='$t' ORDER BY INDEX_NAME",
 "SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_NAME='$t'",
 "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()",
 "CHECK TABLE `$t`","REPAIR TABLE `$t`","OPTIMIZE TABLE `$t`","ANALYZE TABLE `$t`",
 "CHECK TABLE `{$t}_missing`",
];
foreach($queries as $i=>$sql){$actual=$n->query($sql)['rows'];$expected=$p->query($sql)->fetchAll();if($actual!==$expected)throw new RuntimeException('Metadata parity failed at case '.$i.' '.json_encode(['actual'=>$actual,'expected'=>$expected]));$checks++;}
$empty=$n->query("SELECT post_title,ID FROM `$t` WHERE ID=-1");
if($empty['columns']!==['post_title','ID']||count($empty['metadata'])!==2||$empty['rows']!==[])throw new RuntimeException('Empty result metadata contract');$checks++;
$ordered=$n->query("SELECT post_title,ID FROM `$t` ORDER BY ID LIMIT 1");
if(array_keys($ordered['rows'][0])!==['post_title','ID'])throw new RuntimeException('Projection order lost');$checks++;
foreach(["SELECT SUM(DATA_LENGTH) FROM information_schema.TABLES","SELECT TABLE_NAME FROM information_schema.TABLES GROUP BY ENGINE","SELECT TABLE_NAME FROM information_schema.TABLES WHERE unsupported=1","SHOW COLUMNS FROM another.`$t`","SHOW TABLE STATUS LIKE '$t' trailing"] as $sql){try{$n->query($sql);}catch(Throwable){$checks++;continue;}throw new RuntimeException('Unsupported metadata was accepted');}
$n->close();$p->close();
echo "PASS $checks native metadata/result contracts\n";

foreach (["SELECT CAST(1.20 AS DECIMAL(10,2)) AS n", "SELECT 1.5+0 AS n", "SELECT CAST(0 AS DECIMAL(10,2)) AS n", "SELECT CURRENT_USER AS u", "SELECT CURTIME() AS t"] as $sql) {
 $native=native_client();$php=php_client();$a=$native->query($sql)['rows'];$b=$php->query($sql)->fetchAll();
 if(str_contains($sql,'CURTIME')) {if(!preg_match('/^\d{2}:\d{2}:/',$a[0]['t']))throw new RuntimeException('Time decode');}
 elseif($a!==$b)throw new RuntimeException('Native scalar parity: '.$sql.' '.json_encode([$a,$b]));
 $native->close();$php->close();
}
echo "PASS numeric display scale and temporal/identity decoding\n";
