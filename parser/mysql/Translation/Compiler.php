<?php
namespace WPDSQL\MySQL\Translation;

use WPDSQL\MySQL\Node;
use WPDSQL\MySQL\Token;
use WPDSQL\MySQL\TreeAdapter;
use WPDSQL\MySQL\SqlParser;

/** Compile normalized WordPress parser nodes into value-free, serializable translation instructions. */
final class Compiler {
    private array $tables=[];
    private array $unqualified=[];
    private bool $calc=false;
    private bool $found=false;
    private ?object $omitLimit=null;
    private ?object $omitOrder=null;
    private ?object $replaceOrder=null;
    private ?array $replacementOrder=null;
    private ?object $rootExpression=null;
    private bool $upsert=false;
    private ?object $termDistinctSpec=null;
    private array $termGroup=[];
    private Shape $shape;
    public function __construct(Shape $shape) {$this->shape=new Shape($shape->template,$shape->sqlMode);}
    private static function tag(object $node):string {return $node->kind;}
    public static function sql(array $parts):array {return ['op'=>'sql','parts'=>$parts];}
    private static function id(string $raw):string {
        if(in_array($raw[0]??'',['`','"'],true)){$q=$raw[0];return str_replace($q.$q,$q,substr($raw,1,-1));}return $raw;
    }
    private function kids(object $ctx):array { $out=[];for($i=0;$i<$ctx->getChildCount();$i++)$out[]=$ctx->getChild($i);return $out; }
    private function contexts(object $ctx,string $tag,bool $deep=true):array {
        $out=[];foreach($this->kids($ctx) as $c)if($c instanceof Node){if(self::tag($c)===$tag)$out[]=$c;elseif($deep)$out=array_merge($out,$this->contexts($c,$tag));}return $out;
    }
    private function has(object $ctx,string $text):bool {foreach($this->kids($ctx) as $c)if($c instanceof Token&&strcasecmp($c->getText(),$text)===0)return true;return false;}
    private function names(object $ctx):array {return array_map(fn($i)=>self::id($i->getText()),$this->contexts($ctx,'Identifier'));}
    private function column(object $ctx):array {
        $parts=$this->names($ctx);if(!$parts)$parts=[self::id($ctx->getText())];
        $name=array_pop($parts);$qualifier=$parts?array_pop($parts):null;
        if($qualifier===null&&$this->upsert&&count(array_unique($this->tables))===1)$qualifier=reset($this->tables);
        if($parts)throw new \RuntimeException('Cross-schema column references require explicit support');
        $tables=$qualifier!==null ? (isset($this->tables[$qualifier])?[$this->tables[$qualifier]]:[]) : ($this->unqualified?:array_values(array_unique($this->tables)));
        return ['op'=>'column','name'=>$name,'qualifier'=>$qualifier,'tables'=>$tables];
    }
    private function tableName(object $ctx):string {
        $parts=$this->names($ctx);if(count($parts)!==1)throw new \RuntimeException('Cross-schema SQL requires an explicit migration');return $parts[0];
    }
    private function bindTables(?object $ctx):void {
        if($ctx===null)return;
        $local=[];
        $walk=function($node)use(&$walk,&$local,$ctx){
            if($node!==$ctx&&self::tag($node)==='QueryExpression')return;
            if(self::tag($node)==='SingleTable') {
                $ref=$node->tableRef();if(!$ref)return;$name=$this->tableName($ref);$local[$name]=$name;
                if($node->tableAlias())$local[self::id($node->tableAlias()->identifier()->getText())]=$name;
                return;
            }
            foreach($this->kids($node) as $c)if($c instanceof Node)$walk($c);
        };
        $walk($ctx);$this->tables=array_replace($this->tables,$local);
        if($local)$this->unqualified=array_values(array_unique($local));
    }
    private function specifications(object $ctx):array {
        if(self::tag($ctx)==='QuerySpecification')return [$ctx];
        $out=[];foreach($this->kids($ctx) as $child)if($child instanceof Node&&in_array(self::tag($child),['QueryExpression','QueryExpressionBody','QueryPrimary','QuerySpecification','QueryExpressionParens','QueryExpressionWithOptLockingClauses'],true))$out=array_merge($out,$this->specifications($child));
        return $out;
    }
    private function globalAggregate(array $items):bool {
        $aggregate=false;$freeColumn=false;
        $walk=function($n)use(&$walk,&$aggregate,&$freeColumn){
            if(!is_array($n))return;
            if(($n['op']??null)==='aggregate'||(($n['op']??null)==='function'&&in_array($n['name'],['COUNT','SUM','AVG','MIN','MAX'],true))){$aggregate=true;return;}
            if(in_array($n['op']??null,['column','query'],true)){$freeColumn=true;return;}
            foreach($n as $child)if(is_array($child))$walk($child);
        };
        foreach($items as $item)$walk($item);return $aggregate&&!$freeColumn;
    }
    public function compile():array {
        $parsed=SqlParser::parse($this->shape->sql,sqlMode:$this->shape->sqlMode);
        $tree=(new TreeAdapter())->convert($parsed->tree);$simple=$tree->simpleStatement();$root=$simple?$this->kids($simple)[0]:$tree->beginWork();
        if(!$root)throw new \RuntimeException('A single nonempty statement is required');
        $tag=self::tag($root);
        if(in_array($tag,['CreateStatement','AlterStatement','DropStatement','RenameTableStatement','TruncateTableStatement'],true))return ['kind'=>'ddl','tag'=>$tag];
        if(!in_array($tag,['SelectStatement','InsertStatement','UpdateStatement','DeleteStatement','ReplaceStatement','TransactionOrLockingStatement','TransactionStatement','SavepointStatement','BeginWork'],true))throw new \RuntimeException('Statement requires the metadata or controlled schema path: '.$tag);
        if($tag==='SelectStatement')$this->rootExpression=$root->queryExpression();
        $body=$this->render($root);$count=null;
        if($this->calc){$this->omitLimit=$this->rootExpression?->limitClause();$count=$this->render($root);$this->omitLimit=null;}
        if($this->found&&($tag!=='SelectStatement'||$this->calc||count($this->contexts($root,'SelectItem'))!==1||$this->contexts($root,'FromClause')))throw new \RuntimeException('FOUND_ROWS must be a standalone query');
        return ['kind'=>$this->found?'found_rows':'statement','body'=>$body,'count'=>$count];
    }
    private function render(object $ctx):array|string {
        if($ctx===$this->omitLimit||$ctx===$this->omitOrder)return '';
        if($ctx===$this->replaceOrder)return $this->replacementOrder;
        if($ctx instanceof Token) {
            $token=$ctx->getSymbol();$raw=$token->getText();
            if($token->isEnd()||$raw===';')return '';
            if(isset($this->shape->offsets[$token->getStartIndex()])) {
                $index=$this->shape->offsets[$token->getStartIndex()];
                return ['op'=>'slot','index'=>$index,'kind'=>$this->shape->slots[$index]['kind']];
            }
            if(str_contains($raw,"'")||str_contains($raw,'"')) {
                if(strtoupper($raw[0])==='N'&&isset($this->shape->offsets[$token->getStartIndex()+1]))return ['op'=>'slot','index'=>$this->shape->offsets[$token->getStartIndex()+1],'kind'=>'string'];
                throw new \RuntimeException('Unsupported quoted token form');
            }
            return match(strtoupper($raw)){'&&'=>'AND','||'=>'OR','<=>'=>'IS NOT DISTINCT FROM','!='=>'<>',default=>$raw};
        }
        $tag=self::tag($ctx);
        if(in_array($tag,['Identifier','PureIdentifier'],true))return ['op'=>'identifier','name'=>self::id($ctx->getText())];
        if($tag==='ColumnRef'||$tag==='FieldIdentifier')return $this->column($ctx);
        if($tag==='TableRef')return ['op'=>'identifier','name'=>$this->tableName($ctx)];
        if(in_array($tag,['IndexHintList','IntoClause','LockingClauseList','SimpleExprMatch','SimpleExprUserVariableAssignment','SimpleExpressionRValue','SimpleExprParamMarker','SimpleExprOdbc','SimpleExprCollate','SimpleExprConvertUsing','SimpleExprCastTime','JsonOperator'],true))throw new \RuntimeException('Unsupported DSQL construct: '.$tag);
        if($tag==='SelectOption') {
            $option=strtoupper($ctx->getText());
            if($option==='SQL_CALC_FOUND_ROWS'){$this->calc=true;return '';}
            if(!in_array($option,['ALL','DISTINCT','DISTINCTROW'],true))throw new \RuntimeException('Unsupported SELECT option');
            return $option==='DISTINCTROW'?'DISTINCT':$option;
        }
        if($tag==='SelectItemList') {
            $parts=[];$position=0;
            foreach($this->kids($ctx) as $item) {
                if($item instanceof Node&&self::tag($item)==='SelectItem'&&$item->expr())$parts[]=['op'=>'select_item','position'=>$position++,'value'=>$this->render($item->expr()),'alias'=>$item->selectAlias()?$this->render($item->selectAlias()):''];
                else $parts[]=$this->render($item);
            }return self::sql($parts);
        }
        if($tag==='SelectAlias') {
            if($ctx->identifier())return self::sql(['AS',$this->render($ctx->identifier())]);
            $tokens=$this->contexts($ctx,'TextStringLiteral');$node=$this->render($tokens[0]);
            return self::sql(['AS',['op'=>'alias','value'=>$node]]);
        }
        if($tag==='QueryExpression') {
            $saved=$this->tables;$savedUnqualified=$this->unqualified;$savedOrder=$this->omitOrder;$savedReplaceOrder=$this->replaceOrder;$savedReplacementOrder=$this->replacementOrder;$savedSpec=$this->termDistinctSpec;$savedGroup=$this->termGroup;$this->bindTables($ctx);
            $specs=$this->specifications($ctx);$order=$ctx->orderClause();$discardedOrder=[];$calendarColumn=null;
            if(count($specs)===1&&$order&&!$specs[0]->groupByClause()) {
                $items=[];foreach($specs[0]->selectItemList()->selectItem() as $item)if($item->expr())$items[]=$this->render($item->expr());
                if($this->globalAggregate($items)){
                    $orderItems=[];$onlyColumns=true;foreach($order->orderList()->orderExpression() as $item){$value=$this->render($item->expr());if(!is_array($value)||$value['op']!=='column')$onlyColumns=false;$orderItems[]=$value;}
                    if($onlyColumns){$this->omitOrder=$order;$discardedOrder=$orderItems;}
                }
            }
            if(count($specs)===1&&$order&&!$specs[0]->groupByClause()) {
                $spec=$specs[0];$distinct=false;foreach($spec->selectOption() as $option)if(strtoupper($option->getText())==='DISTINCT')$distinct=true;
                $projections=[];foreach($spec->selectItemList()->selectItem() as $item)if($item->expr())$projections[]=$this->render($item->expr());
                $orders=$order->orderList()->orderExpression();
                if($distinct&&count($projections)===2&&count($orders)===1) {
                    $ordered=$this->render($orders[0]->expr());$positions=[];
                    foreach($projections as $i=>$projection)if(is_array($projection)&&$projection['op']==='function'&&in_array($projection['name'],['YEAR','MONTH'],true)&&($projection['args'][0]??null)===$ordered)$positions[$projection['name']]=$i+1;
                    if(is_array($ordered)&&$ordered['op']==='column'&&isset($positions['YEAR'],$positions['MONTH'])) {
                        $direction=$orders[0]->direction()?strtoupper($orders[0]->direction()->getText()):'ASC';$calendarColumn=$ordered;
                        $this->replaceOrder=$order;$this->replacementOrder=self::sql(['ORDER BY',(string)$positions['YEAR'],$direction,',',(string)$positions['MONTH'],$direction]);
                    }
                }
            }
            if(count($specs)===1&&$order&&isset($this->tables['t'])&&str_ends_with($this->tables['t'],'_terms')) {
                $spec=$specs[0];$distinct=false;foreach($spec->selectOption() as $o)if(strtoupper($o->getText())==='DISTINCT')$distinct=true;
                $selected=[];foreach($spec->selectItemList()->selectItem() as $item)if($item->expr())$selected[]=$this->render($item->expr());
                $orders=$order->orderList()->orderExpression();$ordered=count($orders)===1?$this->render($orders[0]->expr()):null;
                $valid=$selected&&count($selected)<=2;
                foreach($selected as $c)if(!is_array($c)||$c['op']!=='column'||!in_array(($c['qualifier']??'').'.'.$c['name'],['t.term_id','tr.object_id'],true))$valid=false;
                if($distinct&&$valid&&is_array($ordered)&&$ordered['op']==='column'&&$ordered['qualifier']==='t'&&$ordered['name']==='name'&&!$spec->groupByClause()&&!$spec->havingClause()){$this->termDistinctSpec=$spec;$this->termGroup=[...$selected,$ordered];}
            }
            $out=$this->renderChildren($ctx);$orderedBody=null;if($discardedOrder||$calendarColumn){$oldOmit=$this->omitOrder;$oldReplace=$this->replaceOrder;$this->omitOrder=null;$this->replaceOrder=null;$orderedBody=$this->renderChildren($ctx);$this->omitOrder=$oldOmit;$this->replaceOrder=$oldReplace;}$unionTypes=[];
            $union=false;foreach($this->contexts($ctx,'QueryExpressionBody') as $body)if($this->has($body,'UNION'))$union=true;
            if(count($specs)>1&&$union)foreach($specs as $spec){$before=$this->tables;$beforeUnqualified=$this->unqualified;$this->bindTables($spec->fromClause());$items=[];foreach($spec->selectItemList()->selectItem() as $item)if($item->expr())$items[]=$this->render($item->expr());$unionTypes[]=$items;$this->tables=$before;$this->unqualified=$beforeUnqualified;}
            $this->tables=$saved;$this->unqualified=$savedUnqualified;$this->omitOrder=$savedOrder;$this->replaceOrder=$savedReplaceOrder;$this->replacementOrder=$savedReplacementOrder;$this->termDistinctSpec=$savedSpec;$this->termGroup=$savedGroup;return ['op'=>'query','body'=>$out,'union_types'=>$unionTypes,'discarded_order'=>$discardedOrder,'ordered_body'=>$orderedBody,'calendar_column'=>$calendarColumn];
        }
        if($tag==='QuerySpecification') {
            $saved=$this->tables;$savedUnqualified=$this->unqualified;$this->bindTables($ctx->fromClause());
            try {
                if($ctx!==$this->termDistinctSpec)return $this->renderChildren($ctx);
                $parts=[];foreach($this->kids($ctx) as $child)if(!($child instanceof Node&&self::tag($child)==='SelectOption'&&strtoupper($child->getText())==='DISTINCT'))$parts[]=$this->render($child);
                $parts[]='GROUP BY';foreach($this->termGroup as $i=>$col){if($i)$parts[]=',';$parts[]=$col;}
                return ['op'=>'term_distinct','table'=>$this->tables['t'],'body'=>self::sql($parts)];
            }finally{$this->tables=$saved;$this->unqualified=$savedUnqualified;}
        }
        if($tag==='FromClause'&&strtoupper($ctx->getText())==='FROMDUAL')return '';
        if($tag==='LimitClause') {
            $o=$ctx->limitOptions();$items=$o->limitOption();
            $first=$this->render($items[0]);
            if(count($items)===1)return self::sql(['LIMIT',$first]);
            $second=$this->render($items[1]);return $this->has($o,',')?self::sql(['LIMIT',$second,'OFFSET',$first]):self::sql(['LIMIT',$first,'OFFSET',$second]);
        }
        if(in_array($tag,['ExprAnd','ExprOr','ExprXor'],true)) {
            $args=$ctx->expr();return ['op'=>'logical','operator'=>match($tag){'ExprAnd'=>'AND','ExprOr'=>'OR',default=>'XOR'},'left'=>$this->render($args[0]),'right'=>$this->render($args[1])];
        }
        if(in_array($tag,['ExprNot','SimpleExprNot'],true))return ['op'=>'boolean_sql','value'=>self::sql(['NOT (',['op'=>'truth','value'=>$this->render($tag==='ExprNot'?$ctx->expr():$ctx->simpleExpr())],')'])];
        if($tag==='ExprIs'&&$this->has($ctx,'IS'))return ['op'=>'is_truth','value'=>$this->render($ctx->boolPri()),'suffix'=>strtoupper(substr($ctx->getText(),strlen($ctx->boolPri()->getText())))];
        if($tag==='PrimaryExprCompare')return ['op'=>'compare','operator'=>$ctx->compOp()->getText(),'left'=>$this->render($ctx->boolPri()),'right'=>$this->render($ctx->predicate())];
        if($tag==='PrimaryExprIsNull')return ['op'=>'boolean_sql','value'=>$this->renderChildren($ctx)];
        if($tag==='Predicate'&&$ctx->predicateOperations()) {
            $op=$ctx->predicateOperations();$kind=self::tag($op);$left=$this->render($ctx->bitExpr(0));$neg=$ctx->notRule()!==null;
            if($kind==='PredicateExprIn')return ['op'=>'in','left'=>$left,'not'=>$neg,'values'=>$op->exprList()?array_map(fn($e)=>$this->render($e),$op->exprList()->expr()):null,'subquery'=>$op->subquery()?$this->render($op->subquery()):null];
            if($kind==='PredicateExprBetween')return ['op'=>'between','left'=>$left,'not'=>$neg,'low'=>$this->render($op->bitExpr()),'high'=>$this->render($op->predicate())];
            if($kind==='PredicateExprLike'){$args=$op->simpleExpr();return ['op'=>'like','left'=>$left,'right'=>$this->render($args[0]),'escape'=>isset($args[1])?$this->render($args[1]):null,'not'=>$neg];}
            if($kind==='PredicateExprRegex')return ['op'=>'regex','left'=>$left,'right'=>$this->render($op->bitExpr()),'not'=>$neg];
            return ['op'=>'boolean_sql','value'=>self::sql([$left,$neg?'NOT':'',$this->render($op)])];
        }
        if($tag==='Predicate'&&count($this->kids($ctx))>1)throw new \RuntimeException('Unsupported predicate');
        if($tag==='WhereClause'||$tag==='HavingClause')return self::sql([$tag==='WhereClause'?'WHERE':'HAVING',['op'=>'truth','value'=>$this->render($ctx->expr())]]);
        if($tag==='BitExpr'&&$ctx->interval())return ['op'=>'date_math','subtract'=>$this->has($ctx,'-'),'date'=>$this->render($ctx->bitExpr(0)),'amount'=>$this->render($ctx->expr()),'unit'=>strtolower($ctx->interval()->getText())];
        if($tag==='BitExpr'&&$ctx->op!==null) {
            $args=$ctx->bitExpr();return ['op'=>'arithmetic','operator'=>strtoupper($ctx->op->getText()),'left'=>$this->render($args[0]),'right'=>$this->render($args[1])];
        }
        if($tag==='SimpleExprConcat')return ['op'=>'function','name'=>'CONCAT','args'=>array_map(fn($n)=>$this->render($n),$ctx->simpleExpr())];
        if($tag==='SimpleExprSubQuery'&&$this->has($ctx,'EXISTS'))return ['op'=>'boolean_sql','value'=>$this->renderChildren($ctx)];
        if($tag==='SimpleExprUnary')return ['op'=>'unary','operator'=>$ctx->op->getText(),'value'=>$this->render($ctx->simpleExpr())];
        if($tag==='SimpleExprBinary')return ['op'=>'cast','value'=>$this->render($ctx->simpleExpr()),'type'=>'binary'];
        if($tag==='SimpleExprCast'||$tag==='SimpleExprConvert')return ['op'=>'cast','value'=>$this->render($ctx->expr()),'type'=>$this->castType($ctx->castType())];
        if($tag==='SimpleExprValues') {
            if(!$this->upsert)throw new \RuntimeException('VALUES() is supported only in an upsert assignment');
            return self::sql(['EXCLUDED.',['op'=>'identifier','name'=>self::id($ctx->simpleIdentifier()->getText())]]);
        }
        if($tag==='SubstringFunction')return ['op'=>'function','name'=>'SUBSTRING','args'=>array_map(fn($e)=>$this->render($e),$ctx->expr())];
        if($tag==='TrimFunction') {
            $args=$ctx->expr();if(count($args)!==1||$this->has($ctx,'FROM')||$this->has($ctx,'LEADING')||$this->has($ctx,'TRAILING')||$this->has($ctx,'BOTH'))throw new \RuntimeException('Custom TRIM semantics require explicit support');
            return ['op'=>'function','name'=>'TRIM','args'=>[$this->render($args[0])]];
        }
        if($tag==='RuntimeFunctionCall'&&$this->kids($ctx)[0] instanceof Node) {
            $child=$this->kids($ctx)[0];if(!in_array(self::tag($child),['SubstringFunction','TrimFunction'],true))throw new \RuntimeException('Unsupported runtime function construct');return $this->render($child);
        }
        if(in_array($tag,['FunctionCallGeneric','RuntimeFunctionCall'],true))return $this->functionCall($ctx);
        if($tag==='SumExpr')return $this->aggregate($ctx);
        if(in_array($tag,['InsertStatement','ReplaceStatement'],true))return $this->insert($ctx,$tag==='ReplaceStatement');
        if($tag==='UpdateStatement')return $this->update($ctx);
        if($tag==='DeleteStatement')return $this->delete($ctx);
        if(in_array($tag,['BeginWork','TransactionStatement','RollbackStatement'],true)) {
            $word=strtoupper($this->kids($ctx)[0]->getText());
            if(!in_array($word,['BEGIN','START','COMMIT','ROLLBACK'],true))throw new \RuntimeException('Unsupported transaction command');
            if(!in_array(strtoupper($ctx->getText()),['BEGIN','BEGINWORK','STARTTRANSACTION','COMMIT','COMMITWORK','ROLLBACK','ROLLBACKWORK'],true))throw new \RuntimeException('Transaction options require explicit support');
            return $word==='START'?'BEGIN':$word;
        }
        if($tag==='SavepointStatement'&&in_array(strtoupper($ctx->getText()),['ROLLBACK','ROLLBACKWORK'],true))return 'ROLLBACK';
        if($tag==='SavepointStatement'||$tag==='LockStatement'||$tag==='XaStatement')throw new \RuntimeException('Unsupported DSQL transaction operation');
        return $this->renderChildren($ctx);
    }
    private function renderChildren(object $ctx):array|string {
        $parts=array_map(fn($c)=>$this->render($c),$this->kids($ctx));$parts=array_values(array_filter($parts,fn($p)=>$p!==''));
        return count($parts)===1?$parts[0]:self::sql($parts);
    }
    private function castType(object $ctx):array|string {
        $name=strtoupper($this->kids($ctx)[0]->getText());
        if($name==='BINARY'){if(count($this->kids($ctx))!==1)throw new \RuntimeException('Sized binary casts require explicit support');return 'binary';}
        return match($name){'SIGNED'=>'bigint','UNSIGNED'=>'numeric(20)','CHAR','NCHAR'=>'text','DATETIME'=>'timestamp','DATE'=>'date','TIME'=>'time','JSON'=>'json','DECIMAL'=>self::sql(array_merge(['numeric'],array_map(fn($c)=>$this->render($c),array_slice($this->kids($ctx),1)))),'DOUBLE','REAL'=>'double precision','FLOAT'=>'real',default=>throw new \RuntimeException('Unsupported CAST type')};
    }
    private function functionArgs(object $ctx):array {
        // Collect top-level expression arguments, never descendants of an expression.
        $out=[];
        $walk=function($node)use(&$walk,&$out){foreach($this->kids($node) as $c)if($c instanceof Node){$tag=self::tag($c);if(str_starts_with($tag,'Expr')&&in_array($tag,['ExprIs','ExprAnd','ExprOr','ExprNot','ExprXor'],true))$out[]=$this->render($c);elseif(in_array($tag,['ExprList','ExprListWithParentheses','ExprWithParentheses','UdfExprList','UdfExpr'],true))$walk($c);}};
        $walk($ctx);return $out;
    }
    private function functionCall(object $ctx):array {
        $name=strtoupper($this->kids($ctx)[0]->getText());$args=$this->functionArgs($ctx);
        if($name==='VALUES') {
            if(!$this->upsert||count($args)!==1||!is_array($args[0])||$args[0]['op']!=='column')throw new \RuntimeException('VALUES requires an upsert column');
            return self::sql(['EXCLUDED.',['op'=>'identifier','name'=>$args[0]['name']]]);
        }
        if(in_array($name,['AVG','SUM','MIN','MAX','COUNT'],true))return ['op'=>'aggregate','name'=>$name,'args'=>$args,'distinct'=>false,'order'=>null,'separator'=>null];
        if($name==='FOUND_ROWS'){$this->found=true;return ['op'=>'found_rows'];}
        if(in_array($name,['UTC_TIMESTAMP','UTC_DATE','UTC_TIME'],true))return ['op'=>'function','name'=>$name,'args'=>$args];
        if(in_array($name,['DATE_ADD','DATE_SUB','ADDDATE','SUBDATE'],true)) {
            $unit=$this->contexts($ctx,'Interval');if(!$unit)throw new \RuntimeException('DATE arithmetic requires an explicit interval');
            return ['op'=>'date_math','subtract'=>in_array($name,['DATE_SUB','SUBDATE'],true),'date'=>$args[0],'amount'=>$args[1],'unit'=>strtolower($unit[0]->getText())];
        }
        $allowed=['VERSION','CURRENT_USER','SESSION_USER','USER','CURRENT_DATABASE','COALESCE','NULLIF','LOWER','UPPER','CONCAT','CONCAT_WS','IF','IFNULL','FIELD','RAND','NOW','CURRENT_TIMESTAMP','CURDATE','CURTIME','SYSDATE','YEAR','MONTH','DAY','DAYOFMONTH','DAYOFYEAR','DAYOFWEEK','WEEKDAY','WEEK','HOUR','MINUTE','SECOND','UNIX_TIMESTAMP','ABS','ROUND','CEIL','CEILING','FLOOR','LENGTH','CHAR_LENGTH','CHARACTER_LENGTH','SUBSTRING','SUBSTR','TRIM','LTRIM','RTRIM','REPLACE','LEFT','RIGHT','MOD','MD5','REVERSE','DATE_FORMAT','GREATEST','LEAST'];
        if(!in_array($name,$allowed,true))throw new \RuntimeException('Unsupported MySQL function: '.$name);
        return ['op'=>'function','name'=>$name,'args'=>$args];
    }
    private function aggregate(object $ctx):array {
        $name=strtoupper($this->kids($ctx)[0]->getText());
        if($ctx->windowingClause())throw new \RuntimeException('Windowed aggregates require explicit translation');
        if($name==='COUNT'&&$this->has($ctx,'*'))return ['op'=>'function','name'=>'COUNT','args'=>['*']];
        $args=$this->functionArgs($ctx);
        if(!$args){foreach($this->contexts($ctx,'InSumExpr',false) as $arg)$args[]=$this->render($arg);}
        $allowed=['COUNT','SUM','AVG','MIN','MAX','GROUP_CONCAT','STD','STDDEV_SAMP','VARIANCE','VAR_SAMP'];
        if(!in_array($name,$allowed,true)||$ctx->windowingClause())throw new \RuntimeException('Unsupported aggregate form');
        $separator=$ctx->textString()?$this->render($ctx->textString()):null;
        return ['op'=>'aggregate','name'=>$name,'args'=>$args,'distinct'=>$this->has($ctx,'DISTINCT'),'order'=>$ctx->orderClause()?$this->render($ctx->orderClause()):null,'separator'=>$separator];
    }
    private function assignments(object $list,string $table):array {
        $out=[];$assigned=[];foreach($this->kids($list) as $c)if($c instanceof Node) {
            $kids=$this->kids($c);$field=$this->column($kids[0]);$field['tables']=[$table];$field['qualifier']=null;
            $value=$this->render($kids[count($kids)-1]);
            $references=[];$scan=function($n)use(&$scan,&$references){if(!is_array($n))return;if(($n['op']??null)==='column')$references[]=strtolower($n['name']);foreach($n as $v)if(is_array($v))$scan($v);};$scan($value);
            if(array_intersect($assigned,$references))throw new \RuntimeException('Sequentially dependent assignments require explicit translation');
            $out[]=['field'=>$field,'value'=>$value];$assigned[]=strtolower($field['name']);
        }return $out;
    }
    private function insert(object $ctx,bool $replace):array {
        if($ctx->usePartition()||(method_exists($ctx,'insertLockOption')&&$ctx->insertLockOption()))throw new \RuntimeException('Unsupported INSERT options');
        $table=$this->tableName($ctx->tableRef());$this->tables=[$table=>$table];$this->unqualified=[$table];
        $constructor=$ctx->insertFromConstructor();if(!$constructor)throw new \RuntimeException('INSERT currently requires VALUES');
        $columns=$constructor->fields()?array_map(fn($n)=>self::id($n->getText()),$constructor->fields()->insertIdentifier()):null;
        $rows=[];
        foreach($constructor->insertValues()->valueList()->values() as $row) {
            $values=[];foreach($this->kids($row) as $c)if(!($c instanceof Token&&$c->getText()===','))$values[]=$this->render($c);
            if($columns!==null&&count($values)!==count($columns))throw new \RuntimeException('INSERT column/value count mismatch');$rows[]=$values;
        }
        $updates=[];
        if(!$replace&&$ctx->insertUpdateList()){$this->upsert=true;$updates=$this->assignments($ctx->insertUpdateList()->loadDatasetList(),$table);$this->upsert=false;}
        return ['op'=>$replace?'replace':'insert','table'=>$table,'columns'=>$columns,'rows'=>$rows,'ignore'=>$this->has($ctx,'IGNORE'),'updates'=>$updates];
    }
    private function update(object $ctx):array {
        $this->tables=[];$this->unqualified=[];$this->bindTables($ctx->tableReferenceList());
        $singles=$this->contexts($ctx->tableReferenceList(),'SingleTable');
        if(count($singles)!==1||$this->contexts($ctx,'JoinedTable')||$this->has($ctx,'IGNORE'))throw new \RuntimeException('Multi-table/IGNORE UPDATE requires explicit support');
        $single=$singles[0];$table=$this->tableName($single->tableRef());$alias=$single->tableAlias()?self::id($single->tableAlias()->identifier()->getText()):null;
        return ['op'=>'update','table'=>$table,'alias'=>$alias,'assignments'=>$this->assignments($ctx->loadDatasetList(),$table),'where'=>$ctx->whereClause()?$this->render($ctx->whereClause()):'','order'=>$ctx->orderClause()?$this->render($ctx->orderClause()):'','limit'=>$ctx->simpleLimitClause()?$this->render($ctx->simpleLimitClause()):''];
    }
    private function delete(object $ctx):array {
        $this->tables=[];$this->unqualified=[];
        if($ctx->tableReferenceList()) {
            $this->bindTables($ctx->tableReferenceList());$singles=$this->contexts($ctx->tableReferenceList(),'SingleTable');
            if(count($singles)!==2)throw new \RuntimeException('Unsupported multi-table DELETE');
            $tables=array_map(fn($s)=>$this->tableName($s->tableRef()),$singles);
            if($tables[0]!==$tables[1])throw new \RuntimeException('Deleting from different physical tables requires an explicit transaction');
            $aliases=array_map(fn($s)=>$s->tableAlias()?self::id($s->tableAlias()->identifier()->getText()):null,$singles);
            $targets=$this->names($ctx->tableAliasRefList());if(in_array(null,$aliases,true)||array_diff($targets,$aliases))throw new \RuntimeException('Unsupported DELETE target aliases');
            if(count($targets)===1)return ['op'=>'delete_self_join','table'=>$tables[0],'target'=>$targets[0],'from'=>$this->render($ctx->tableReferenceList()),'where'=>$ctx->whereClause()?$this->render($ctx->whereClause()):''];
            if($this->contexts($ctx,'JoinedTable'))throw new \RuntimeException('Joined DELETE with multiple targets requires explicit support');
            return ['op'=>'delete_join','table'=>$tables[0],'aliases'=>$aliases,'targets'=>$targets,'where'=>$this->render($ctx->whereClause()->expr())];
        }
        $table=$this->tableName($ctx->tableRef());$alias=$ctx->tableAlias()?self::id($ctx->tableAlias()->identifier()->getText()):null;$this->tables=[$table=>$table];$this->unqualified=[$table];if($alias)$this->tables[$alias]=$table;
        return ['op'=>'delete','table'=>$table,'alias'=>$alias,'where'=>$ctx->whereClause()?$this->render($ctx->whereClause()):'','order'=>$ctx->orderClause()?$this->render($ctx->orderClause()):'','limit'=>$ctx->simpleLimitClause()?$this->render($ctx->simpleLimitClause()):''];
    }
}
