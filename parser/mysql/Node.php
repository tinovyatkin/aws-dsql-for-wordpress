<?php
namespace WPDSQL\MySQL;
/** Semantic grammar node used by the DSQL compiler, independent of parser runtime. */
final class Node {
    public function __construct(public readonly string $kind,public readonly array $children) {}
    public function getText():string {return implode('',array_map(fn($c)=>$c->getText(),$this->children));}
    public function getChildCount():int {return count($this->children);}
    public function getChild(int $index):Node|Token {return $this->children[$index];}
    public static function family(string $kind,string $wanted):bool {
        return $kind===$wanted || ($wanted==='Expr'&&in_array($kind,['ExprIs','ExprAnd','ExprOr','ExprXor','ExprNot'],true))
            ||($wanted==='BoolPri'&&str_starts_with($kind,'PrimaryExpr'))
            ||($wanted==='SimpleExpr'&&str_starts_with($kind,'SimpleExpr'))
            ||($wanted==='PredicateOperations'&&str_starts_with($kind,'PredicateExpr'));
    }
    public function __call(string $method,array $arguments):mixed {
        $wanted=ucfirst($method);
        $items=array_values(array_filter($this->children,fn($n)=>$n instanceof self&&self::family($n->kind,$wanted)));
        if($arguments)return $items[$arguments[0]]??null;
        $lists=['ExprAnd.expr','ExprOr.expr','ExprXor.expr','Predicate.bitExpr','BitExpr.bitExpr','SimpleExprConcat.simpleExpr','SubstringFunction.expr','TrimFunction.expr','SelectItemList.selectItem','QuerySpecification.selectOption','OrderList.orderExpression','Fields.insertIdentifier','ValueList.values','LimitOptions.limitOption','PredicateExprLike.simpleExpr','ExprList.expr'];
        return in_array($this->kind.'.'.$method,$lists,true)?$items:($items[0]??null);
    }
    public function __get(string $name):mixed {
        if($name!=='op')throw new \LogicException('Unknown semantic-node property');
        if(in_array($this->kind,['BitExpr','SimpleExprUnary'],true))foreach($this->children as $c)if($c instanceof Token&&in_array(strtoupper($c->getText()),['+','-','*','/','%','DIV','MOD','^','&','|','<<','>>','~'],true))return $c;
        return null;
    }
}
