<?php
namespace WPDSQL\Schema;
use WPDSQL\MySQL\SqlParser;
use WPDSQL\MySQL\WordPress\WP_Parser_Node as Node;
use WPDSQLMigration\Plan;

/** Derive bounded schema operations from the parsed grammar; never tokenize SQL again. */
final class Ddl {
    public function __construct(private string $sql,private string $schema='wp_live',private string $mode='') {}
    public function parse(): array {
        $root=Ast::statement(SqlParser::parse($this->sql,sqlMode:$this->mode));
        $kind=$root->rule_name;
        if($kind==='create_table_stmt')return $this->create($root);
        if($kind==='alter_table_stmt'){
            Ast::shape($root,['table_ident','opt_alter_table_actions'],['ALTER','TABLE']);
            $table=Ast::table(Ast::child($root,'table_ident'),$this->schema);
            $actions=Ast::child($root,'opt_alter_table_actions');
            if(!$actions)throw new \RuntimeException('Missing ALTER actions');
            $this->alterContainers($actions);
            $changes=[];foreach(Ast::nodes($actions,'alter_list_item') as $item)$changes=array_merge($changes,$this->alter($item,$table));
            if(!$changes)throw new \RuntimeException('Unsupported ALTER options');
            return ['kind'=>'alter','table'=>$table,'if_exists'=>false,'changes'=>$changes];
        }
        if(in_array($kind,['create_index_stmt','drop_index_stmt'],true)){
            Ast::shape($root,['opt_unique','ident','table_ident','key_list_with_expression','opt_index_options'],['CREATE','DROP','INDEX','ON','(',')']);
            $table=Ast::table(Ast::child($root,'table_ident'),$this->schema);$name=Ast::id(Ast::child($root,'ident'));
            $changes=$kind==='drop_index_stmt'?[['op'=>'drop_index','name'=>$name]]:[['op'=>'add_index','indexes'=>$this->indexOptions($root,$this->keyParts(Ast::child($root,'key_list_with_expression'),$table,$name,Ast::child($root,'opt_unique')!==null,'BTREE'))]];
            return ['kind'=>'alter','table'=>$table,'if_exists'=>false,'changes'=>$changes];
        }
        if($kind==='drop_table_stmt'){
            Ast::shape($root,['table_or_tables','if_exists','table_list'],['DROP']);
            $tables=Ast::nodes($root,'table_ident');if(count($tables)!==1)throw new \RuntimeException('One table per DROP required');
            return ['kind'=>'drop','table'=>Ast::table($tables[0],$this->schema),'if_exists'=>Ast::child($root,'if_exists')!==null,'changes'=>[]];
        }
        if($kind==='rename'){
            Ast::shape($root,['table_or_tables','table_to_table_list'],['RENAME']);
            $pairs=Ast::nodes($root,'table_to_table');if(count($pairs)!==1)throw new \RuntimeException('One table per RENAME required');
            $ids=$pairs[0]->get_child_nodes('table_ident');
            return ['kind'=>'alter','table'=>Ast::table($ids[0],$this->schema),'if_exists'=>false,'changes'=>[['op'=>'rename','name'=>Ast::table($ids[1],$this->schema)]]];
        }
        throw new \RuntimeException('Unsupported schema statement: '.$kind);
    }
    private function create(Node $root): array {
        Ast::shape($root,['opt_if_not_exists','table_ident','table_element_list','opt_create_table_options_etc'],['CREATE','TABLE','(',')']);
        $table=Ast::table(Ast::child($root,'table_ident'),$this->schema);$columns=[];$indexes=[];$indexNames=[];
        $append=function(array $group)use(&$indexes,&$indexNames){foreach(array_unique(array_column($group,'Key_name')) as $name){$key=strtolower($name);if(isset($indexNames[$key]))throw new \RuntimeException('Duplicate index definition');$indexNames[$key]=true;}$indexes=array_merge($indexes,$group);};
        $list=Ast::child($root,'table_element_list');if(!$list)throw new \RuntimeException('Explicit column definitions required');
        foreach(Ast::nodes($list,'table_element') as $element){
            Ast::shape($element,['column_def','table_constraint_def'],[]);
            if($def=Ast::child($element,'column_def')){
                Ast::shape($def,['ident','field_def'],[]);
                [$column,$inline]=$this->column(Ast::id(Ast::child($def,'ident')),Ast::child($def,'field_def'));
                $columns[]=$column;$append($this->inlineIndexes($inline,$table,$column['Field']));
            }else $append($this->index(Ast::child($element,'table_constraint_def'),$table));
        }
        $options=self::tableOptions($root,true);
        return ['kind'=>'create','table'=>$table,'if_exists'=>Ast::child($root,'opt_if_not_exists')!==null,'columns'=>$columns,'indexes'=>$indexes,'collation'=>$options['collation'],'table_options'=>$options];
    }
    /** Table options are also read from preserved legacy DDL, independently of its column types. */
    public static function tableOptions(Node $root,bool $strict=false): array {
        $options=['engine'=>'InnoDB','charset'=>'utf8mb4','collation'=>null,'comment'=>''];
        $container=Ast::child($root,'opt_create_table_options_etc');
        if($container&&$strict)Ast::shape($container,['create_table_options'],[]);
        foreach(Ast::nodes($root,'create_table_option') as $option){
            $words=Ast::words($option);$first=$words[0]??'';
            if($first==='DEFAULT')$first=$words[1]??'';
            if($first==='ENGINE'){$n=Ast::child($option,'ident_or_text');$options['engine']=Ast::tokens($n)[0]->get_value();if($strict&&strcasecmp($options['engine'],'InnoDB')!==0)throw new \RuntimeException('Only InnoDB source semantics supported');}
            elseif(in_array($first,['CHARSET','CHARACTER'],true)){$n=Ast::nodes($option,'charset_name')[0];$options['charset']=Ast::tokens($n)[0]->get_value();if($strict&&strtolower($options['charset'])!=='utf8mb4')throw new \RuntimeException('Unsupported table charset');}
            elseif($first==='COLLATE'){$n=Ast::nodes($option,'collation_name')[0];$options['collation']=Ast::tokens($n)[0]->get_value();if(!preg_match('/^[a-zA-Z0-9_]+$/D',$options['collation']))throw new \RuntimeException('Invalid table collation');}
            elseif($first==='COMMENT')$options['comment']=Ast::literal(Ast::child($option,'TEXT_STRING_sys'))['value'];
            elseif($strict)throw new \RuntimeException('Unsupported CREATE TABLE option');
        }
        return $options;
    }
    private function column(string $name,Node $field): array {
        Ast::shape($field,['type','opt_column_attribute_list'],[]);
        $typeNode=Ast::child($field,'type');$typeParts=$typeNode->get_children();
        if($charset=Ast::child($typeNode,'opt_charset_with_opt_binary')){
            Ast::shape($charset,['character_set','charset_name'],[]);$nameNode=Ast::child($charset,'charset_name');
            if(!$nameNode||strcasecmp(Ast::tokens($nameNode)[0]->get_value(),'utf8mb4')!==0)throw new \RuntimeException('Character-set conversion needs an explicit migration');
            $typeParts=array_filter($typeParts,fn($c)=>$c!==$charset);
        }
        $type=strtolower(implode('',array_map(fn($n)=>Ast::text($n),$typeParts)));
        $type=str_replace('unsigned',' unsigned',$type);
        if(!preg_match('/^(?:(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?|(?:decimal|numeric)\(\d+,\d+\)(?: unsigned)?|(?:var)?char\(\d+\)|(?:tiny|medium|long)?(?:text|blob)|varbinary\(\d+\)|(?:datetime|timestamp)(?:\([0-6]\))?|date|json)$/D',$type))throw new \RuntimeException('Unsupported column type syntax');
        $column=['Field'=>$name,'Type'=>$type,'Collation'=>null,'Null'=>'YES','Key'=>'','Default'=>null,'Extra'=>'','Privileges'=>'select,insert,update,references','Comment'=>'','HasDefault'=>false,'DefaultExpression'=>false];$inline=[];
        foreach(Ast::nodes($field,'column_attribute') as $attribute){
            $words=Ast::words($attribute);$first=$words[0];
            if($words===['NOT','NULL'])$column['Null']='NO';
            elseif($words===['NULL'])$column['Null']='YES';
            elseif($words===['AUTO_INCREMENT']){$column['Extra']='auto_increment';$column['Null']='NO';}
            elseif(in_array($words,[['PRIMARY','KEY'],['KEY']],true)){$inline[]='PRIMARY';$column['Null']='NO';}
            elseif(in_array($words,[['UNIQUE'],['UNIQUE','KEY']],true))$inline[]=$name;
            elseif($first==='DEFAULT'){
                Ast::shape($attribute,['now_or_signed_literal'],['DEFAULT']);
                $value=Ast::literal(Ast::child($attribute,'now_or_signed_literal'));
                $column['Default']=$value['value'];$column['HasDefault']=true;$column['DefaultExpression']=$value['expression'];
            }elseif($first==='COMMENT'){$column['Comment']=Ast::literal(Ast::child($attribute,'TEXT_STRING_sys'))['value'];}
            elseif($first==='COLLATE'){$column['Collation']=Ast::id(Ast::tokens(Ast::child($attribute,'collation_name'))[0]);}
            else throw new \RuntimeException('Unsupported column attribute');
        }
        Plan::type($column);if(Plan::type($column)==='bytea'&&$column['Default']!==null)throw new \RuntimeException('Binary defaults require an explicit migration');
        return [$column,$inline];
    }
    private function keyParts(Node $list,string $table,?string $name,bool $unique,string $type): array {
        $parts=[];
        foreach(Ast::nodes($list,'key_part_with_expression') as $item){
            Ast::shape($item,['key_part'],[]);$part=Ast::child($item,'key_part');if(!$part)throw new \RuntimeException('Expression indexes require explicit support');
            Ast::shape($part,['ident','opt_ordering_direction'],['(',')',...array_filter(Ast::words($part),'ctype_digit')]);
            $field=Ast::id(Ast::child($part,'ident'));$prefix=null;$words=Ast::words($part);
            if(in_array('DESC',$words,true))throw new \RuntimeException('Descending index upgrades need explicit support');
            if(($pos=array_search('(',$words,true))!==false){$prefix=(int)$words[$pos+1];if($prefix<1)throw new \RuntimeException('Invalid index prefix');}
            $parts[]=[$field,$prefix];
        }
        if(!$parts)throw new \RuntimeException('Empty index');$name??=$parts[0][0];
        return array_map(static fn($part,$i)=>['Table'=>$table,'Non_unique'=>$unique?0:1,'Key_name'=>$name,'Seq_in_index'=>$i+1,'Column_name'=>$part[0],'Collation'=>'A','Cardinality'=>null,'Sub_part'=>$part[1],'Packed'=>null,'Null'=>'','Index_type'=>$type,'Comment'=>'','Index_comment'=>'','Visible'=>'YES','Expression'=>null],$parts,array_keys($parts));
    }
    private function index(Node $node,string $table): array {
        Ast::shape($node,['constraint_key_type','key_or_index','opt_index_name_and_type','key_list_with_expression','opt_index_options'],['(',')','FULLTEXT']);
        $words=Ast::words($node);$primary=($words[0]??'')==='PRIMARY';$unique=$primary||($words[0]??'')==='UNIQUE';
        $nameNode=Ast::child($node,'opt_index_name_and_type');$name=null;
        if($nameNode){Ast::shape($nameNode,['opt_ident'],[]);$ident=Ast::nodes($nameNode,'ident');if($ident)$name=Ast::id($ident[0]);}
        return $this->indexOptions($node,$this->keyParts(Ast::child($node,'key_list_with_expression'),$table,$primary?'PRIMARY':$name,$unique,($words[0]??'')==='FULLTEXT'?'FULLTEXT':'BTREE'));
    }
    private function indexOptions(Node $root,array $indexes): array {
        $options=Ast::child($root,'opt_index_options');if(!$options)return $indexes;
        $walk=function(Node $node)use(&$walk,&$indexes){
            if($node->rule_name==='common_index_option'){
                Ast::shape($node,['TEXT_STRING_sys'],['COMMENT']);$comment=Ast::literal(Ast::child($node,'TEXT_STRING_sys'))['value'];
                foreach($indexes as &$index)$index['Index_comment']=$comment;unset($index);return;
            }
            Ast::shape($node,['index_options','index_option','common_index_option'],[]);
            foreach($node->get_child_nodes() as $child)$walk($child);
        };$walk($options);return $indexes;
    }
    private function inlineIndexes(array $inline,string $table,string $column): array {
        $out=[];foreach($inline as $name)$out[]=['Table'=>$table,'Non_unique'=>0,'Key_name'=>$name,'Seq_in_index'=>1,'Column_name'=>$column,'Collation'=>'A','Cardinality'=>null,'Sub_part'=>null,'Packed'=>null,'Null'=>'','Index_type'=>'BTREE','Comment'=>'','Index_comment'=>'','Visible'=>'YES','Expression'=>null];return $out;
    }
    private function alterContainers(Node $node): void {
        if($node->rule_name==='alter_list_item')return;
        Ast::shape($node,['opt_alter_command_list','alter_list','alter_list_item'],[',']);
        foreach($node->get_child_nodes() as $child)$this->alterContainers($child);
    }
    private function alter(Node $node,string $table): array {
        $words=Ast::words($node);$first=$words[0];$ids=$node->get_child_nodes('ident');
        if($first==='CONVERT'){
            Ast::shape($node,['character_set','charset_name','opt_collate'],['CONVERT','TO']);
            $charset=Ast::nodes($node,'charset_name')[0]??throw new \RuntimeException('Charset required');
            if(strtolower(Ast::tokens($charset)[0]->get_value())!=='utf8mb4')throw new \RuntimeException('Only UTF-8 widening is supported; other charset conversion requires an explicit migration');
            $collate=Ast::nodes($node,'collation_name');
            if(count($collate)!==1)throw new \RuntimeException('UTF-8 widening requires an explicit unchanged collation family');
            return [['op'=>'utf8mb4_upgrade','collation'=>strtolower(Ast::tokens($collate[0])[0]->get_value())]];
        }
        if(in_array($first,['ADD','CHANGE','MODIFY'],true)){
            Ast::shape($node,['opt_column','ident','field_def','opt_place','table_constraint_def'],['ADD','CHANGE','MODIFY']);
            if($index=Ast::child($node,'table_constraint_def'))return [['op'=>'add_index','indexes'=>$this->index($index,$table)]];
            $name=Ast::id($ids[$first==='CHANGE'?1:0]);[$column,$inline]=$this->column($name,Ast::child($node,'field_def'));
            if($first!=='ADD'&&$inline)throw new \RuntimeException('Use explicit ADD KEY when modifying columns');
            $position=null;if($place=Ast::child($node,'opt_place')){$position=Ast::words($place)[0]==='FIRST'?['first'=>true]:['after'=>Ast::id(Ast::child($place,'ident'))];}
            $changes=[['op'=>$first==='ADD'?'add':'change','column'=>$column,'position'=>$position]];
            if($first!=='ADD')$changes[0]['old']=$first==='CHANGE'?Ast::id($ids[0]):$name;
            if($inline)$changes[]=['op'=>'add_index','indexes'=>$this->inlineIndexes($inline,$table,$name)];return $changes;
        }
        if($first==='DROP'){
            Ast::shape($node,['opt_column','key_or_index','ident'],['DROP','PRIMARY','KEY']);
            if(($words[1]??'')==='PRIMARY')return [['op'=>'drop_index','name'=>'PRIMARY']];
            return [['op'=>Ast::child($node,'key_or_index')?'drop_index':'drop','name'=>Ast::id($ids[0])]];
        }
        if($first==='ALTER'){
            Ast::shape($node,['opt_column','ident','signed_literal_or_null'],['ALTER','SET','DROP','DEFAULT']);
            $literal=Ast::child($node,'signed_literal_or_null');$value=$literal?Ast::literal($literal):['value'=>null,'expression'=>false];
            return [['op'=>'default','name'=>Ast::id($ids[0]),'value'=>$value['value'],'has_default'=>in_array('SET',$words,true),'default_expression'=>$value['expression']]];
        }
        if($first==='RENAME'){
            Ast::shape($node,['ident','opt_to','table_ident'],['RENAME','COLUMN','TO']);
            return Ast::child($node,'table_ident')?[['op'=>'rename','name'=>Ast::table(Ast::child($node,'table_ident'),$this->schema)]]:[['op'=>'rename_column','old'=>Ast::id($ids[0]),'name'=>Ast::id($ids[1])]];
        }
        throw new \RuntimeException('Unsupported ALTER operation');
    }
}
