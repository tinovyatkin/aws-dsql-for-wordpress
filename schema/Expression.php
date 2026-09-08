<?php
namespace WPDSQL\Schema;
use WPDSQL\MySQL\WordPress\WP_Parser_Node as Node;

/** Bounded evaluator for catalog predicates/projections, never for application SQL. */
final class Expression {
    public static function compile(Node $node,array $columns,string $database): array {
        $rule=$node->rule_name;$children=$node->get_child_nodes();$words=Ast::words($node);
        $directWords=array_map(fn($c)=>$c instanceof Node?null:strtoupper($c->get_bytes()),$node->get_children());
        if($rule==='simple_ident'){
            $tokens=Ast::tokens($node);$name=implode('',array_map(fn($t)=>$t->get_value(),$tokens));
            foreach($columns as $column)if(strcasecmp($column,$name)===0)return ['column',str_contains($column,'.')?substr($column,strrpos($column,'.')+1):$column];
            throw new \RuntimeException('Unknown metadata column: '.$name);
        }
        if(in_array($rule,['literal','literal_or_null','signed_literal','signed_literal_or_null','text_literal','num_literal'],true))return ['literal',Ast::literal($node)['value']];
        if(str_starts_with($rule,'function_call')&&in_array(strtoupper(Ast::text($node)),['DATABASE()','SCHEMA()'],true))return ['literal',$database];
        if($rule==='bool_pri'&&($op=Ast::child($node,'comp_op'))){
            if(count($children)!==3)throw new \RuntimeException('Unsupported metadata comparison');
            $operator=strtoupper(Ast::text($op));if(!in_array($operator,['=','<>','!=','<','>','<=','>=','<=>'],true))throw new \RuntimeException('Unsupported metadata comparison');
            return [$operator,self::compile($children[0],$columns,$database),self::compile($children[2],$columns,$database)];
        }
        if($rule==='expr'){
            foreach(['and'=>'and','or'=>'or','xor'=>'xor'] as $grammar=>$op)if(Ast::child($node,$grammar)){
                $exprs=$node->get_child_nodes('expr');if(count($exprs)!==2)throw new \RuntimeException('Unsupported metadata logic');
                return [$op,self::compile($exprs[0],$columns,$database),self::compile($exprs[1],$columns,$database)];
            }
            if(($words[0]??'')==='NOT'&&count($children)===2)return ['not',self::compile($children[1],$columns,$database)];
        }
        if($rule==='predicate'&&in_array('IN',$directWords,true)){
            Ast::shape($node,['bit_expr','not','expr','expr_list'],['IN','(',')',',']);
            $values=$node->get_child_nodes('expr');
            if($list=Ast::child($node,'expr_list'))$values=array_merge($values,Ast::nodes($list,'expr'));
            if(!$values)throw new \RuntimeException('Literal metadata IN list required');
            $items=[];foreach($values as $value){$literal=Ast::literal($value);if($literal['expression'])throw new \RuntimeException('Literal metadata IN list required');$items[]=['literal',$literal['value']];}
            $expr=['in',self::compile(Ast::child($node,'bit_expr'),$columns,$database),$items];
            return Ast::child($node,'not')?['not',$expr]:$expr;
        }
        if($rule==='predicate'&&in_array('LIKE',$directWords,true)){
            $nodes=array_values(array_filter($children,fn($c)=>$c->rule_name!=='not'));
            if(count($nodes)!==2||in_array('ESCAPE',$words,true))throw new \RuntimeException('Unsupported metadata LIKE');
            $expr=['like',self::compile($nodes[0],$columns,$database),self::compile($nodes[1],$columns,$database)];return in_array('NOT',$words,true)?['not',$expr]:$expr;
        }
        if($rule==='bool_pri'&&in_array('IS',$words,true)&&end($words)==='NULL')return [in_array('NOT',$words,true)?'is_not_null':'is_null',self::compile($children[0],$columns,$database)];
        if(count($children)===1&&in_array($rule,['expr','bool_pri','predicate','bit_expr','simple_expr','set_function_specification'],true)){
            $rawTokens=$node->get_children();foreach($rawTokens as $child)if(!$child instanceof Node&&!in_array($child->get_bytes(),['(',')'],true))throw new \RuntimeException('Unsupported metadata expression');
            return self::compile($children[0],$columns,$database);
        }
        throw new \RuntimeException('Unsupported metadata expression: '.$rule);
    }
    public static function evaluate(array $expr,array $row): mixed {
        $op=$expr[0];if($op==='column')return $row[$expr[1]]??null;if($op==='literal')return $expr[1];
        $a=self::evaluate($expr[1],$row);
        if($op==='not')return $a===null?null:!self::truth($a);if($op==='is_null')return $a===null;if($op==='is_not_null')return $a!==null;
        if($op==='in'){
            if($a===null)return null;$unknown=false;
            foreach($expr[2] as $item){$value=self::evaluate($item,$row);if($value===null){$unknown=true;continue;}if(self::compare($a,$value)===0)return true;}
            return $unknown?null:false;
        }
        $b=self::evaluate($expr[2],$row);
        if($op==='and')return self::truth($a)===false||self::truth($b)===false?false:($a===null||$b===null?null:true);
        if($op==='or')return self::truth($a)===true||self::truth($b)===true?true:($a===null||$b===null?null:false);
        if($op==='<=>')return $a===null||$b===null?$a===$b:self::compare($a,$b)===0;
        if($a===null||$b===null)return null;
        $cmp=self::compare($a,$b);
        return match($op){'='=>$cmp===0,'<>','!='=>$cmp!==0,'<'=>$cmp<0,'>'=>$cmp>0,'<='=>$cmp<=0,'>='=>$cmp>=0,'like'=>self::like((string)$a,(string)$b),'xor'=>self::truth($a)!==self::truth($b),default=>throw new \RuntimeException('Unsupported catalog operation')};
    }
    public static function compare(mixed $a,mixed $b): int {if($a===null||$b===null)return $a===$b?0:($a===null?-1:1);return is_numeric($a)&&is_numeric($b)?($a<=>$b):strcasecmp((string)$a,(string)$b);}
    public static function truth(mixed $value): ?bool {
        if($value===null||is_bool($value))return $value;
        preg_match('/^[\s]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/',(string)$value,$m);
        return (float)($m[0]??0)!==0.0;
    }
    public static function like(string $value,string $pattern): bool {
        $regex='';for($i=0;$i<strlen($pattern);$i++){$c=$pattern[$i];if($c==='\\'&&isset($pattern[$i+1]))$regex.=preg_quote($pattern[++$i],'~');else $regex.=$c==='%'?'.*':($c==='_'?'.':preg_quote($c,'~'));}
        return preg_match('~^'.$regex.'$~isuD',$value)===1;
    }
    public static function tableFilter(?array $expr): ?string {
        if(!$expr)return null;
        if($expr[0]==='and')return self::tableFilter($expr[1])??self::tableFilter($expr[2]);
        if($expr[0]==='='&&$expr[1][0]==='column'&&strcasecmp($expr[1][1],'TABLE_NAME')===0&&$expr[2][0]==='literal')return (string)$expr[2][1];
        return null;
    }
}
