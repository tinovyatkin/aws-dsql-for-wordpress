<?php
namespace WPDSQL\Schema;
use WPDSQL\MySQL\WordPress\WP_Parser_Node as Node;
use WPDSQL\MySQL\WordPress\WP_Parser_Token as Token;

/** Small structural helpers over the standalone parser's named grammar nodes. */
final class Ast {
    public static function child(Node $n,string $rule): ?Node { return $n->get_first_child_node($rule); }
    public static function nodes(Node $n,string $rule): array { return $n->get_descendant_nodes($rule); }
    public static function tokens(Node|Token $n): array { return $n instanceof Token ? [$n] : $n->get_descendant_tokens(); }
    public static function text(Node|Token $n,string $separator=''): string { return implode($separator,array_map(fn($t)=>$t->get_bytes(),self::tokens($n))); }
    public static function words(Node $n): array { return array_map(fn($t)=>strtoupper($t->get_bytes()),self::tokens($n)); }
    public static function id(Node|Token $n): string {
        $tokens=self::tokens($n);
        if(count($tokens)!==1)throw new \RuntimeException('Expected one schema identifier');
        $id=$tokens[0]->get_value();
        if(!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]{0,62}$/D',$id))throw new \RuntimeException('Unsupported schema identifier');
        return $id;
    }
    public static function table(Node $n,string $schema): string {
        $ids=$n->get_child_nodes('ident');
        if(count($ids)===2){if(self::id($ids[0])!==$schema)throw new \RuntimeException('Only the active application schema may be used');array_shift($ids);}
        if(count($ids)!==1)throw new \RuntimeException('Expected one schema table');
        $name=self::id($ids[0]);if(str_starts_with($name,'__'))throw new \RuntimeException('System schema objects are protected');return $name;
    }
    public static function shape(Node $n,array $nodes,array $tokens): void {
        foreach($n->get_children() as $c){
            if($c instanceof Node){if($c->has_child()&&!in_array($c->rule_name,$nodes,true))throw new \RuntimeException('Unsupported schema construct: '.$c->rule_name);}
            elseif(!in_array(strtoupper($c->get_bytes()),$tokens,true))throw new \RuntimeException('Unsupported schema syntax');
        }
    }
    public static function literal(Node|Token $n): array {
        $tokens=self::tokens($n);$raw=self::text($n);$upper=strtoupper($raw);
        if($upper==='NULL')return ['value'=>null,'expression'=>false];
        if(in_array($upper,['CURRENT_TIMESTAMP','CURRENT_TIMESTAMP()'],true))return ['value'=>'CURRENT_TIMESTAMP','expression'=>true];
        if(count($tokens)===1&&in_array($tokens[0]->get_bytes()[0]??'',["'",'"'],true))return ['value'=>$tokens[0]->get_value(),'expression'=>false];
        if(preg_match('/^[+-]?\d+(?:\.\d+)?$/D',$raw))return ['value'=>$raw,'expression'=>false];
        throw new \RuntimeException('Only literal or CURRENT_TIMESTAMP defaults are supported');
    }
    public static function statement(\WPDSQL\MySQL\ParsedQuery $query): Node {
        $simple=self::nodes($query->tree,'simple_statement');
        if(count($simple)!==1)throw new \RuntimeException('One schema or metadata statement required');
        return $simple[0]->get_first_child_node();
    }
}
