<?php
namespace WPDSQL\MySQL;
use WPDSQL\MySQL\WordPress\WP_Parser_Node;
use WPDSQL\MySQL\WordPress\WP_Parser_Token;

/** Normalize Bison grammar structure into the compiler's semantic node vocabulary. */
final class TreeAdapter {
    private const MAP=[
        'TEXT_STRING_validated'=>'TextStringLiteral','TEXT_STRING_sys'=>'TextStringLiteral','start_entry'=>'Query','simple_statement'=>'SimpleStatement','select_stmt'=>'SelectStatement',
        'insert_stmt'=>'InsertStatement','replace_stmt'=>'ReplaceStatement','update_stmt'=>'UpdateStatement','delete_stmt'=>'DeleteStatement',
        'table_ident'=>'TableRef','opt_table_alias'=>'TableAlias','ident'=>'Identifier','IDENT_sys'=>'PureIdentifier',
        'simple_ident'=>'ColumnRef','simple_ident_nospvar'=>'SimpleIdentifier','table_ident_opt_wild'=>'TableRefWithWildcard',
        'opt_group_clause'=>'GroupByClause','opt_having_clause'=>'HavingClause','group_clause'=>'GroupByClause','order_expr'=>'OrderExpression','ordering_direction'=>'Direction',
        'insert_columns'=>'Fields','insert_column'=>'InsertIdentifier','values_list'=>'ValueList',
        'update_list'=>'LoadDatasetList','update_elem'=>'LoadDataset','opt_insert_update_list'=>'InsertUpdateList',
        'opt_simple_limit'=>'SimpleLimitClause','function_call_generic'=>'FunctionCallGeneric',
        'function_call_keyword'=>'RuntimeFunctionCall','function_call_nonkeyword'=>'RuntimeFunctionCall','function_call_conflict'=>'RuntimeFunctionCall',
        'opt_union_option'=>'UnionOption','union_option'=>'UnionOption','opt_windowing_clause'=>'WindowingClause',
        'group_concat_sep'=>'TextString','limit_clause'=>'LimitClause','comp_op'=>'CompOp',
        'begin_stmt'=>'BeginWork','commit'=>'TransactionStatement','rollback'=>'SavepointStatement',
        'begin'=>'BeginWork','start'=>'TransactionStatement','create_table_stmt'=>'CreateStatement','create'=>'CreateStatement',
        'alter_table_stmt'=>'AlterStatement','alter'=>'AlterStatement','drop_table_stmt'=>'DropStatement','drop'=>'DropStatement',
        'rename'=>'RenameTableStatement','truncate'=>'TruncateTableStatement',
    ];
    private const FLAT=['select_item_list','expr_list','udf_expr_list','order_list','group_list','table_reference_list','table_alias_ref_list','insert_columns','values_list','values','update_list','select_option_list'];
    private const INLINE=['sql_statement','simple_statement_or_begin','select_options','select_option_list','opt_from_clause','from_tables','opt_where_clause','opt_order_clause','opt_limit_clause','opt_as','opt_ignore','opt_INTO','opt_udf_expr_list','opt_values','expr_or_default','set_function_specification','opt_ordering_direction','and','or','not','not2','opt_escape'];
    private function flatten(WP_Parser_Node $node):array {
        $out=[];foreach($node->get_children() as $c)if($c instanceof WP_Parser_Node&&$c->rule_name===$node->rule_name&&in_array($node->rule_name,self::FLAT,true))$out=array_merge($out,$this->flatten($c));else $out[]=$c;return $out;
    }
    public function convert(WP_Parser_Node|WP_Parser_Token $raw):Node|Token|array {
        if($raw instanceof WP_Parser_Token)return new Token($raw);
        $rule=$raw->rule_name;$children=[];
        foreach($this->flatten($raw) as $c){$child=$this->convert($c);if(is_array($child))$children=array_merge($children,$child);else $children[]=$child;}
        if(in_array($rule,self::INLINE,true))return $children;
        $kind=self::MAP[$rule]??str_replace(' ','',ucwords(str_replace('_',' ',$rule)));
        $word=static fn($c)=>$c instanceof Token?strtoupper($c->getText()):'';
        $words=array_map($word,$children);$first=$words[0]??'';
        if($rule==='select_alias'&&!array_filter($children,fn($c)=>$c instanceof Node&&$c->kind==='Identifier'))foreach($children as $i=>$c)if($c instanceof Token&&in_array($c->getText()[0]??'',["'",'"'],true))$children[$i]=new Node('TextStringLiteral',[$c]);
        if($rule==='group_concat_sep')$children=array_values(array_filter($children,fn($c)=>!($c instanceof Token&&strtoupper($c->getText())==='SEPARATOR')));
        if($rule==='row_value')return $children;
        if($rule==='expr') {
            $kind='ExprIs';
            if($first==='NOT')$kind='ExprNot';
            else foreach(['AND'=>'ExprAnd','&&'=>'ExprAnd','OR'=>'ExprOr','||'=>'ExprOr','XOR'=>'ExprXor'] as $op=>$label)if(in_array($op,$words,true)){$kind=$label;break;}
        }
        if($rule==='bool_pri') {
            $kind='PrimaryExprPredicate';
            if(in_array('IS',$words,true))$kind='PrimaryExprIsNull';
            elseif(array_filter($children,fn($n)=>$n instanceof Node&&$n->kind==='CompOp'))$kind='PrimaryExprCompare';
        }
        if($rule==='predicate'&&count($children)>1) {
            $operation=null;$offset=null;
            foreach(['IN'=>'PredicateExprIn','BETWEEN'=>'PredicateExprBetween','LIKE'=>'PredicateExprLike','REGEXP'=>'PredicateExprRegex','RLIKE'=>'PredicateExprRegex'] as $op=>$label){$i=array_search($op,$words,true);if($i!==false){$operation=$label;$offset=$i;break;}}
            if($operation){
                $suffix=array_slice($children,$offset);
                if($operation==='PredicateExprIn') {
                    $expressions=[];foreach($suffix as $c)if($c instanceof Token&&$c->getText()===',')$expressions[]=$c;elseif($c instanceof Node){if(Node::family($c->kind,'Expr'))$expressions[]=$c;elseif($c->kind==='ExprList')$expressions=array_merge($expressions,$c->children);}
                    if($expressions){$suffix=array_values(array_filter($suffix,fn($c)=>!($c instanceof Node&&(Node::family($c->kind,'Expr')||$c->kind==='ExprList'))&&!($c instanceof Token&&$c->getText()===',')));array_splice($suffix,count($suffix)-1,0,[new Node('ExprList',$expressions)]);}
                }
                $prefix=array_slice($children,0,$offset);if(count($prefix)>1&&$word($prefix[1])==='NOT')$prefix[1]=new Node('NotRule',[$prefix[1]]);return new Node('Predicate',[...$prefix,new Node($operation,$suffix)]);}
        }
        if($rule==='simple_expr') {
            $kind='SimpleExprLiteral';
            if($first==='CAST')$kind='SimpleExprCast';
            elseif($first==='CONVERT')$kind=in_array('USING',$words,true)?'SimpleExprConvertUsing':'SimpleExprConvert';
            elseif($first==='VALUES')$kind='SimpleExprValues';
            elseif($first==='CASE')$kind='SimpleExprCase';
            elseif($first==='EXISTS')$kind='SimpleExprSubQuery';
            elseif($first==='BINARY')$kind='SimpleExprBinary';
            elseif($first==='MATCH')$kind='SimpleExprMatch';
            elseif(in_array($first,['+','-','~'],true))$kind='SimpleExprUnary';
            elseif(in_array($first,['!','NOT'],true))$kind='SimpleExprNot';
            elseif($first==='(')$kind='SimpleExprList';
            elseif(in_array('||',$words,true))$kind='SimpleExprConcat';
            elseif(in_array('COLLATE',$words,true))$kind='SimpleExprCollate';
            elseif(in_array('->',$words,true)||in_array('->>',$words,true))$children=[new Node('JsonOperator',$children)];
        }
        if($rule==='subquery')$kind='QueryExpressionParens';
        if($rule==='table_subquery')return new Node('Subquery',$children);
        if($rule==='function_call_nonkeyword'&&in_array($first,['SUBSTRING','SUBSTR'],true))$kind='SubstringFunction';
        if($rule==='function_call_keyword'&&$first==='TRIM')$kind='TrimFunction';
        return new Node($kind,$children);
    }
}
