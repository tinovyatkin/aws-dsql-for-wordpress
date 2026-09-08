<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
require dirname(__DIR__,2).'/parser/mysql/WordPress/bootstrap.php';
use WPDSQL\MySQL\WordPress\WP_MySQL_Lexer;
use WPDSQL\MySQL\SqlParser;
use WPDSQL\MySQL\ParseException;
$checks=0;
function check(bool $ok,string $label):void{global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function lex(string $sql,string $mode=''):array{return (new WP_MySQL_Lexer($sql,80410,array_filter(explode(',',$mode))))->remaining_tokens();}
function rejects(string $sql,string $mode=''):bool{try{SqlParser::parse($sql,sqlMode:$mode);return false;}catch(ParseException $e){return true;}}
$numbers=['0'=>'INT_NUMBER','00002147483647'=>'INT_NUMBER','2147483647'=>'INT_NUMBER','2147483648'=>'LONG_NUMBER','9223372036854775807'=>'LONG_NUMBER','9223372036854775808'=>'ULONGLONG_NUMBER','18446744073709551615'=>'ULONGLONG_NUMBER','18446744073709551616'=>'DECIMAL_NUMBER'];
foreach($numbers as $n=>$name)check(lex((string)$n)[0]->get_name()===$name,'Numeric boundary '.$n);
foreach(['t.select','t.列','`表`.列'] as $sql){$tokens=lex($sql);check(implode('',array_map(fn($t)=>$t->get_bytes(),$tokens))===$sql,'Qualified identifier preservation');foreach($tokens as $t)check(substr($sql,$t->start,$t->length)===$t->get_bytes(),'UTF-8 byte span');}
check(lex('||')[0]->get_name()==='LOGICAL_OR_OPERATOR','Default OR');
check(lex('||','PIPES_AS_CONCAT')[0]->id!==lex('||')[0]->id,'Concatenation mode');
check(lex('NOT','HIGH_NOT_PRECEDENCE')[0]->get_name()==='NOT2_SYMBOL','NOT mode');
check(lex('"title"')[0]->id===lex("'title'")[0]->id,'Double-quoted string');
check(lex('"title"','ANSI_QUOTES')[0]->get_name()==='BACK_TICK_QUOTED_ID','ANSI identifier');
check(lex('COUNT (1)')[0]->get_name()==='IDENTIFIER','Function adjacency');
$t=lex("COUNT \n(1)",'IGNORE_SPACE');check($t[0]->get_name()==='COUNT'&&rtrim($t[0]->get_bytes())==='COUNT','IGNORE_SPACE function token');
check($t[1]->get_bytes()==='('&&$t[1]->start===7,'Whitespace retained in source positions');
check(lex("_UTF8MB4 'x'")[0]->get_name()==='UNDERSCORE_CHARSET','Charset introducer');
check(lex('_unknown')[0]->get_name()==='IDENTIFIER','Unknown charset is identifier');
check(!rejects("SELECT COUNT \n(*) FROM t",'IGNORE_SPACE'),'Spaced function parses');
check(!rejects("SELECT 'a\\'b'"),'Backslash escapes');
check(rejects("SELECT 'a\\'b'",'NO_BACKSLASH_ESCAPES'),'NO_BACKSLASH_ESCAPES');
check(!rejects('SELECT 1 INTERSECT SELECT 1'),'8.4 INTERSECT');
try{SqlParser::parse('SELECT 1',80031);throw new RuntimeException('Unsupported grammar version accepted');}catch(InvalidArgumentException $e){check(true,'Other grammar families require an explicit parser build');}
foreach(['SELECT /*!80400 1 + */ 2','SELECT /*!90500 garbage !! */ 2','SELECT /*! 1 + */ 2'] as $sql)check(!rejects($sql),'Executable-comment version handling');
foreach(['SELECT 1 /* unfinished','SELECT /*!80400 1','SELECT /*! 1','SELECT 1; SELECT 2','/* comment only */','',';','SELECT (1 + )','SELECT id FROM t WHERE id =','SELECT COALESCE()'] as $sql)check(rejects($sql),'Reject malformed/empty/multiple statements');
$lexer=new WP_MySQL_Lexer('SELECT t.id');$lexer->next_token();$rest=$lexer->remaining_tokens();check($rest[0]->get_bytes()==='t'&&$lexer->remaining_tokens()===[],'Pull and bulk tokenization can be mixed');
$sql="SELECT COALESCE(NULLIF(LOWER(title), ''), CONCAT('🌍 LIMIT 9', id)), (SELECT id FROM t LIMIT 1) FROM t LIMIT 5,10";
$q=SqlParser::parse($sql);$functions=[];
foreach($q->tree->get_descendant_nodes() as $node)if(str_starts_with($node->rule_name,'function_call_'))$functions[]=$node->get_first_descendant_token()->get_bytes();
check(array_diff(['COALESCE','NULLIF','LOWER','CONCAT'],$functions)===[],'Nested function nodes');
$limits=array_map(fn($n)=>implode('',array_map(fn($t)=>$t->get_bytes(),$n->get_descendant_tokens())),$q->tree->get_descendant_nodes('limit_clause'));
check($limits===['LIMIT1','LIMIT5,10'],'Inner and outer LIMIT nodes');
check($q->sql===$sql,'Original source retained');
check((new ParseException())->errorColumn===null,'Unknown error positions are not invented');
echo "PASS $checks standalone lexer/parser boundary checks\n";
