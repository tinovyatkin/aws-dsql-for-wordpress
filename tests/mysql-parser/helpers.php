<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\Token;
use WPDSQL\MySQL\Generated\MySQLLexer;
use WPDSQL\MySQL\Generated\MySQLParserBaseVisitor;
use WPDSQL\MySQL\Generated\Context\LimitClauseContext;
use WPDSQL\MySQL\ParseException;
use WPDSQL\MySQL\SqlParser;

$checks=0;
function check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function lex(string $sql,string $modes='',int $version=80400):array {
 $l=new MySQLLexer(InputStream::fromString($sql));$l->serverVersion=$version;$l->sqlModeFromString($modes);
 $l->removeErrorListeners();$l->addErrorListener(new WPDSQL\MySQL\ThrowingErrorListener());
 $t=new CommonTokenStream($l);$t->fill();return $t->getAllTokens();
}
function rejects(string $sql,int $version=80400,string $mode=''):bool {
 try{SqlParser::parse($sql,$version,$mode);return false;}catch(ParseException $e){return true;}
}
$numbers=['0'=>'INT_NUMBER','00002147483647'=>'INT_NUMBER','2147483647'=>'INT_NUMBER',
 '2147483648'=>'LONG_NUMBER','9223372036854775807'=>'LONG_NUMBER',
 '9223372036854775808'=>'ULONGLONG_NUMBER','18446744073709551615'=>'ULONGLONG_NUMBER',
 '18446744073709551616'=>'DECIMAL_NUMBER'];
foreach($numbers as $number=>$type)check(lex((string)$number)[0]->getType()===constant(MySQLLexer::class.'::'.$type),'Numeric boundary '.$number);
foreach(['t.select','t.列','`表`.列'] as $sql){
 $tokens=lex($sql);$parts=array_filter($tokens,fn($t)=>$t->getType()!==Token::EOF);
 check(implode('',array_map(fn($t)=>$t->getText(),$parts))===$sql,'Split-dot text preservation');
 foreach($parts as $token) check(mb_substr($sql,$token->getStartIndex(),$token->getStopIndex()-$token->getStartIndex()+1)===$token->getText(),'Unicode token span');
}
check(lex('||')[0]->getType()===MySQLLexer::LOGICAL_OR_OPERATOR,'Default OR mode');
check(lex('||','PIPES_AS_CONCAT')[0]->getType()===MySQLLexer::CONCAT_PIPES_SYMBOL,'Concatenation mode');
check(lex('NOT','HIGH_NOT_PRECEDENCE')[0]->getType()===MySQLLexer::NOT2_SYMBOL,'NOT precedence mode');
check(lex('"title"')[0]->getType()===MySQLLexer::DOUBLE_QUOTED_TEXT,'Default double-quoted string');
check(lex('"title"','ANSI_QUOTES')[0]->getType()===MySQLLexer::IDENTIFIER,'ANSI quoted identifier');
check(lex('COUNT (1)')[0]->getType()===MySQLLexer::IDENTIFIER,'Function adjacency');
$t=lex("COUNT \n(1)",'IGNORE_SPACE');
check($t[0]->getType()===MySQLLexer::COUNT_SYMBOL && $t[0]->getChannel()===Token::DEFAULT_CHANNEL,'IGNORE_SPACE function stays visible');
check($t[1]->getText()===" \n" && $t[1]->getChannel()===Token::HIDDEN_CHANNEL,'IGNORE_SPACE preserves separate whitespace');
check(lex("_UTF8MB4 'x'")[0]->getType()===MySQLLexer::UNDERSCORE_CHARSET,'Case-insensitive charset introducer');
check(lex('_unknown')[0]->getType()===MySQLLexer::IDENTIFIER,'Unknown charset is identifier');
check(SqlParser::parse("SELECT COUNT \n(*) FROM t",80400,'IGNORE_SPACE')->tree!==null,'Function parsing with IGNORE_SPACE');
check(!rejects("SELECT 'a\\'b'"),'Default backslash escape');
check(rejects("SELECT 'a\\'b'",80400,'NO_BACKSLASH_ESCAPES'),'NO_BACKSLASH_ESCAPES changes lexical boundary');
check(rejects('SELECT 1 INTERSECT SELECT 1',80030),'Version gate before INTERSECT support');
check(!rejects('SELECT 1 INTERSECT SELECT 1',80031),'Version gate enables INTERSECT');
check(!rejects('SELECT /*!80400 1 + */ 2'),'Enabled version comment');
check(!rejects('SELECT /*!90500 garbage !! */ 2'),'Future version comment ignored');
check(!rejects('SELECT /*! 1 + */ 2'),'Unversioned executable comment');
foreach(['SELECT 1 /* unfinished','SELECT /*!80400 1','SELECT /*! 1','SELECT 1; SELECT 2','/* comment only */','','SELECT (1 + )','SELECT id FROM t WHERE id ='] as $bad)check(rejects($bad),'Reject malformed or non-single statement');
// Lexer reuse must not retain pending dot tokens or comment state.
$l=new MySQLLexer(InputStream::fromString('.select'));$first=$l->nextToken();$l->reset();
check($l->nextToken()->getType()===$first->getType() && $l->nextToken()->getText()==='select','Reset clears pending tokens');
$sql="SELECT COALESCE(NULLIF(LOWER(title), ''), CONCAT('🌍 LIMIT 9', id)), (SELECT id FROM t LIMIT 1) FROM t LIMIT 5,10";
$q=SqlParser::parse($sql);
$visitor=new class extends MySQLParserBaseVisitor {
 public array $limits=[];
 public array $functions=[];
 public function visitFunctionCallGeneric(WPDSQL\MySQL\Generated\Context\FunctionCallGenericContext $context) {$this->functions[]=strtok($context->getText(),'(');return $this->visitChildren($context);}
 public function visitRuntimeFunctionCall(WPDSQL\MySQL\Generated\Context\RuntimeFunctionCallContext $context) {$this->functions[]=strtok($context->getText(),'(');return $this->visitChildren($context);}
 public function visitLimitClause(LimitClauseContext $context) { $this->limits[]=$context->getText();return $this->visitChildren($context); }
};
$visitor->visit($q->tree);
check(array_diff(['COALESCE','NULLIF','LOWER','CONCAT'],$visitor->functions)===[],'Nested functions are separate traversable nodes');
check($visitor->limits===['LIMIT1','LIMIT5,10'],'Visitor distinguishes inner and outer LIMIT nodes');
$roundtrip=implode('',array_map(fn($t)=>$t->getType()===Token::EOF?'':$t->getText(),$q->tokens->getAllTokens()));
check($roundtrip===$sql,'Full token stream preserves source text');
check(!str_contains((new ParseException(1,2))->getMessage(),'SELECT'),'Diagnostic excludes SQL');
echo "PASS $checks lexer, modes, version, syntax, spans, reset and visitor checks\n";
