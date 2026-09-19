<?php
require __DIR__.'/bootstrap.php';
$n=native_client();$p=php_client();$f=fixture();$t=$f['posts'];$m=$f['meta'];$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function normalized(array $rows):array{foreach($rows as &$row)ksort($row);return $rows;}
function first(array $r):mixed{return reset($r['rows'][0]);}
function rejects(callable $f,string $label):void{try{$f();}catch(Throwable $e){check(true,$label);return;}throw new RuntimeException('Expected rejection: '.$label);}
foreach(fixture_queries() as $q){$a=$n->query($q);$b=$p->query($q)->fetchAll();check(normalized($a['rows'])===normalized($b),'Native/PHP read parity');}
foreach(["🌍 apostrophe ' and slash ".chr(92), "payload'); DROP TABLE fake; --", '', '0'] as $value){
    $q="SELECT '".$p->escape($value)."' AS value";
    check(first($n->query($q))===$value,'Literal round trip');
}
check(first($n->query("SELECT LENGTH('🌍') AS bytes"))==='4','MySQL byte length');
check(first($n->query("SELECT CHAR_LENGTH('🌍') AS characters"))==='1','MySQL character length');
check($n->query("SELECT NULL AS value")['rows'][0]['value']===null,'NULL result');
$a=$n->query("SELECT ID FROM `$t` WHERE post_status='publish' AND ID=1");
$b=$n->query("SELECT ID FROM `$t` WHERE post_status='draft' AND ID=3");
check($b['timing']['plan_hit']===1.0&&first($b)==='3','Changing literal values reuse plan safely');
$n->query('BEGIN');
$insert=$n->query("INSERT INTO `$t` (post_title,post_status,stamp) VALUES ('native 🌍','publish','0000-00-00 00:00:00')");
$id=$insert['insert_id'];check((int)$id>=1001&&$insert['affected_rows']===1,'Identity and affected rows');
check(first($n->query("SELECT stamp FROM `$t` WHERE ID=$id"))==='0000-00-00 00:00:00','Temporal sentinel round trip');
check($n->query("UPDATE `$t` SET post_title='updated' WHERE ID=$id")['affected_rows']===1,'UPDATE count');
check(first($n->query("SELECT post_title FROM `$t` WHERE ID=$id"))==='updated','UPDATE effect');
$n->query('ROLLBACK');check(first($n->query("SELECT COUNT(*) AS n FROM `$t` WHERE ID=$id"))==='0','Rollback effect');
$insert=$n->query("INSERT INTO `$t` (post_title,post_status,stamp) VALUES ('committed','publish','2026-09-19 12:00:00')");
$id=$insert['insert_id'];check($p->query("SELECT post_title FROM `$t` WHERE ID=$id")->fetchColumn()==='committed','Autocommit visibility');
check($n->query("DELETE FROM `$t` WHERE ID=$id")['affected_rows']===1,'DELETE count');
$n->query('BEGIN');$n->query("UPDATE `$t` SET post_title='commit test' WHERE ID=1");$n->query('COMMIT');
check($p->query("SELECT post_title FROM `$t` WHERE ID=1")->fetchColumn()==='commit test','Explicit COMMIT visible to PHP');
$n->query("UPDATE `$t` SET post_title='Synthetic story 1' WHERE ID=1");
$abandoned=native_client();$abandoned->query('BEGIN');$abandoned->query("UPDATE `$t` SET post_title='abandoned' WHERE ID=1");unset($abandoned);
check(first($n->query("SELECT post_title FROM `$t` WHERE ID=1"))==='Synthetic story 1','Destructor discards unfinished transaction');
$n->query('BEGIN');
rejects(fn()=>$n->query("INSERT INTO `$m` (meta_id,post_id,meta_key,meta_value) VALUES (1,1,'secret-value','x')"),'Duplicate key error');
$n->query('ROLLBACK');check(first($n->query('SELECT 1 AS ok'))==='1','Connection usable after failed transaction rollback');
foreach(["SELECT * FROM `{$f['prefix']}missing`","ALTER TABLE `$t` ADD COLUMN forbidden text","SELECT 1; SELECT 2","SET sql_mode='made_up'","REPLACE INTO `$t` (post_title) VALUES ('x')"] as $q)rejects(fn()=>$n->query($q),'Fail closed');
// A schema attempt conservatively invalidates cached plans, even on rejection.
$n->query("SELECT ID FROM `$t` WHERE post_status='draft' AND ID=3");
$another=native_client();$r=$another->query("SELECT ID FROM `$t` WHERE post_status='draft' AND ID=3");
check($r['timing']['backend_reused']===1.0&&$r['timing']['plan_hit']===1.0,'Native backend and plan survive PHP object lifetime');
for($i=0;$i<40;$i++)check(first($n->query("SELECT $i AS eviction_$i"))===(string)$i,'Prepared statement eviction');
rejects(fn()=>dsql_native_reset_worker(),'Live client reset guard');
$another->close();
$n->close();rejects(fn()=>$n->query('SELECT 1'),'Closed client');$p->close();dsql_native_reset_worker();
$renewed=native_client();check(first($renewed->query("SELECT 7 AS fresh"))==='7','Fresh pool after reset');$renewed->close();dsql_native_reset_worker();
echo "PASS $checks native/DSQL integration checks\n";
