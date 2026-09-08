<?php
namespace WPDSQL\Schema;
use WPDSQL\MySQL\SqlParser;
use WPDSQL\MySQL\WordPress\WP_Parser_Node as Node;

/** AST-dispatched MySQL metadata backed by the shared logical catalog. */
final class Introspection {
    private array $inferred=[];
    public function __construct(private \PDO $pdo,private \DSQL_Schema_Catalog $catalog,private string $schema,private string $database) {}
    public function clear(): void {$this->inferred=[];}
    public function qualifiedTable(string $name): ?array {
        $parts=explode('.',$name);if(count($parts)===2)$this->database(array_shift($parts));
        if(count($parts)!==1||!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]{0,62}$/D',$parts[0]))throw new \RuntimeException('Unsupported metadata table name');
        return $this->table($parts[0]);
    }
    public function table(string $name): ?array {
        if($saved=$this->catalog->get($name))return $saved;
        if(array_key_exists($name,$this->inferred))return $this->inferred[$name];
        $s=$this->pdo->prepare('SELECT column_name,data_type,is_nullable,column_default,character_maximum_length,is_identity FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');$s->execute([$this->schema,$name]);$rows=$s->fetchAll(\PDO::FETCH_ASSOC);
        if(!$rows)return $this->inferred[$name]=null;
        $columns=[];foreach($rows as $r){
            $type=match($r['data_type']){'character varying'=>'varchar('.$r['character_maximum_length'].')','timestamp without time zone'=>'datetime','integer'=>'int',default=>$r['data_type']};
            $default=$r['column_default'];if(is_string($default)&&preg_match("/^'((?:[^']|'')*)'::/",$default,$m))$default=str_replace("''","'",$m[1]);
            if($default==='0001-01-01 00:00:00')$default='0000-00-00 00:00:00';
            $columns[]=['Field'=>$r['column_name'],'Type'=>$type,'Null'=>$r['is_nullable'],'Key'=>'','Default'=>$default,'Extra'=>$r['is_identity']==='YES'?'auto_increment':'','Collation'=>null];
        }
        $s=$this->pdo->prepare("SELECT t.relname AS \"Table\",CASE WHEN i.indisunique THEN 0 ELSE 1 END AS \"Non_unique\",CASE WHEN i.indisprimary THEN 'PRIMARY' ELSE substr(idx.relname,length(t.relname)+2) END AS \"Key_name\",k.ordinality AS \"Seq_in_index\",a.attname AS \"Column_name\",NULL AS \"Sub_part\",'BTREE' AS \"Index_type\",'A' AS \"Collation\" FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace JOIN pg_class idx ON idx.oid=i.indexrelid CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum WHERE t.relname=? AND n.nspname=? AND i.indisvalid ORDER BY idx.relname,k.ordinality");$s->execute([$name,$this->schema]);$indexes=$s->fetchAll(\PDO::FETCH_ASSOC);
        return $this->inferred[$name]=Model::normalize(['name'=>$name,'columns'=>$columns,'indexes'=>$indexes,'mysql_ddl'=>'','inferred'=>true]);
    }
    private function names(?string $name=null): array {
        $s=$this->pdo->prepare("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname=?".($name!==null?' AND lower(tablename)=lower(?)':'').' ORDER BY tablename');$s->execute($name!==null?[$this->schema,$name]:[$this->schema]);return array_values(array_filter($s->fetchAll(\PDO::FETCH_COLUMN),fn($n)=>!str_starts_with($n,'__')));
    }
    public function query(string $sql,string $mode=''): ?array {
        $root=Ast::statement(SqlParser::parse($sql,sqlMode:$mode));$kind=$root->rule_name;
        if($kind==='select_stmt')return $this->select($root);
        if(!in_array($kind,['show_columns_stmt','show_keys_stmt','show_create_table_stmt','show_tables_stmt','describe_stmt'],true))throw new \RuntimeException('Unsupported metadata statement: '.$kind);
        $showType=Ast::child($root,'opt_show_cmd_type');if($showType&&Ast::words($showType)!==['FULL'])throw new \RuntimeException('Unsupported SHOW modifier');
        $full=$showType!==null;
        if($kind==='show_tables_stmt'){
            Ast::shape($root,['opt_show_cmd_type','opt_db','opt_wild_or_where'],['SHOW','TABLES']);
            if($db=Ast::child($root,'opt_db'))$this->database(Ast::id(Ast::child($db,'ident')));
            $field='Tables_in_'.$this->database;$rows=[];foreach($this->names() as $name)$rows[]=[$field=>$name]+($full?['Table_type'=>'BASE TABLE']:[]);
        }else{
            Ast::shape($root,['opt_show_cmd_type','from_or_in','table_ident','opt_db','opt_wild_or_where','opt_where_clause','keys_or_index','describe_command'],['SHOW','COLUMNS','FIELDS','CREATE','TABLE','DESCRIBE','DESC']);
            $tableNode=Ast::child($root,'table_ident');$parts=$tableNode->get_child_nodes('ident');if(count($parts)===2)$this->database(Ast::id($parts[0]));
            $name=Ast::table($tableNode,count($parts)===2?Ast::id($parts[0]):$this->schema);
            if($db=Ast::child($root,'opt_db'))$this->database(Ast::id(Ast::child($db,'ident')));
            $table=$this->table($name);
            if(!$table&&$kind==='describe_stmt')return []; // dbDelta's missing-table probe must not poison a controlled session.
            if(!$table)throw new \RuntimeException('Unknown metadata table: '.$name);
            if($kind==='show_create_table_stmt')return [['Table'=>$name,'Create Table'=>Model::mysql($table)]];
            if($kind==='show_keys_stmt'){$rows=$table['indexes'];$field='Key_name';}
            else{$rows=Model::columns($table,$full);$field='Field';}
        }
        $filter=Ast::child($root,'opt_wild_or_where')??Ast::child($root,'opt_where_clause');
        if($filter){
            if(($literal=Ast::child($filter,'TEXT_STRING_literal'))!==null){$pattern=Ast::literal($literal)['value'];$rows=array_values(array_filter($rows,fn($r)=>Expression::like($r[$field],$pattern)));}
            elseif($where=Ast::child($filter,'where_clause')){$expr=Expression::compile(Ast::child($where,'expr'),array_keys($rows[0]??$this->emptyShowRow($kind,$full)),$this->database);$rows=$this->filter($rows,$expr);}
            else throw new \RuntimeException('Unsupported metadata filter');
        }
        return $rows;
    }
    private function emptyShowRow(string $kind,bool $full): array {
        return array_fill_keys($kind==='show_keys_stmt'?['Table','Non_unique','Key_name','Seq_in_index','Column_name','Collation','Sub_part','Index_type']:($kind==='show_tables_stmt'?['Tables_in_'.$this->database,...($full?['Table_type']:[])]:['Field','Type','Collation','Null','Key','Default','Extra','Privileges','Comment']),null);
    }
    private function database(string $name): void {if(!in_array($name,[$this->database,$this->schema],true))throw new \RuntimeException('Only the active database may be introspected');}
    private function filter(array $rows,?array $predicate): array {return $predicate?array_values(array_filter($rows,fn($row)=>Expression::truth(Expression::evaluate($predicate,$row))===true)):$rows;}
    private function infoColumns(string $kind): array {
        return match($kind){
            'TABLES'=>['TABLE_CATALOG','TABLE_SCHEMA','TABLE_NAME','TABLE_TYPE','ENGINE','TABLE_COLLATION','TABLE_COMMENT'],
            'COLUMNS'=>array_keys(Model::informationColumns(['name'=>'x','columns'=>[['Field'=>'x','Type'=>'varchar(1)','Null'=>'YES','Key'=>'','Default'=>null,'Extra'=>'','Collation'=>null]],'indexes'=>[],'mysql_ddl'=>''],$this->database)[0]),
            'STATISTICS'=>['TABLE_CATALOG','TABLE_SCHEMA','TABLE_NAME','NON_UNIQUE','INDEX_SCHEMA','INDEX_NAME','SEQ_IN_INDEX','COLUMN_NAME','COLLATION','CARDINALITY','SUB_PART','PACKED','NULLABLE','INDEX_TYPE','COMMENT','INDEX_COMMENT','IS_VISIBLE','EXPRESSION'],
            'SCHEMATA'=>['CATALOG_NAME','SCHEMA_NAME','DEFAULT_CHARACTER_SET_NAME','DEFAULT_COLLATION_NAME','SQL_PATH'],
            default=>throw new \RuntimeException('Unsupported INFORMATION_SCHEMA table')};
    }
    private function infoRows(string $kind,?string $name): array {
        if($kind==='SCHEMATA')return [['CATALOG_NAME'=>'def','SCHEMA_NAME'=>$this->database,'DEFAULT_CHARACTER_SET_NAME'=>'utf8mb4','DEFAULT_COLLATION_NAME'=>'utf8mb4_unicode_ci','SQL_PATH'=>null]];
        $rows=[];foreach($this->names($name) as $tableName){$table=$this->table($tableName);if(!$table)continue;
            if($kind==='COLUMNS')$rows=array_merge($rows,Model::informationColumns($table,$this->database));
            elseif($kind==='TABLES')$rows[]=['TABLE_CATALOG'=>'def','TABLE_SCHEMA'=>$this->database,'TABLE_NAME'=>$tableName,'TABLE_TYPE'=>'BASE TABLE','ENGINE'=>$table['table_options']['engine'],'TABLE_COLLATION'=>$table['table_options']['collation'],'TABLE_COMMENT'=>$table['table_options']['comment']];
            else foreach($table['indexes'] as $i)$rows[]=['TABLE_CATALOG'=>'def','TABLE_SCHEMA'=>$this->database,'TABLE_NAME'=>$tableName,'NON_UNIQUE'=>(string)$i['Non_unique'],'INDEX_SCHEMA'=>$this->database,'INDEX_NAME'=>$i['Key_name'],'SEQ_IN_INDEX'=>(string)$i['Seq_in_index'],'COLUMN_NAME'=>$i['Column_name'],'COLLATION'=>$i['Collation']??null,'CARDINALITY'=>$i['Cardinality']??null,'SUB_PART'=>$i['Sub_part']??null,'PACKED'=>$i['Packed']??null,'NULLABLE'=>$i['Null']??'','INDEX_TYPE'=>$i['Index_type'],'COMMENT'=>$i['Comment']??'','INDEX_COMMENT'=>$i['Index_comment']??'','IS_VISIBLE'=>$i['Visible']??'YES','EXPRESSION'=>$i['Expression']??null];
        }return $rows;
    }
    private function select(Node $root): ?array {
        $tables=Ast::nodes($root,'table_ident');$references=[];
        foreach($tables as $table){$ids=$table->get_child_nodes('ident');if(count($ids)===2&&strcasecmp(Ast::id($ids[0]),'information_schema')===0)$references[]=strtoupper(Ast::id($ids[1]));}
        if(!$references)return null;
        if(count($tables)!==1||count($references)!==1)throw new \RuntimeException('Metadata joins are not supported');
        $specs=Ast::nodes($root,'query_specification');if(count($specs)!==1)throw new \RuntimeException('Metadata subqueries/unions are not supported');
        $spec=$specs[0];Ast::shape($root,['query_expression'],[]);
        $query=Ast::child($root,'query_expression');Ast::shape($query,['query_expression_body','opt_order_clause','opt_limit_clause'],[]);
        Ast::shape($spec,['select_item_list','opt_from_clause','opt_where_clause'],['SELECT']);
        $kind=$references[0];$columns=$this->infoColumns($kind);
        $single=Ast::nodes($root,'single_table')[0]??throw new \RuntimeException('A single metadata table is required');
        Ast::shape($single,['table_ident','opt_table_alias'],[]);
        $qualifiers=[$kind];if($alias=Ast::child($single,'opt_table_alias'))$qualifiers[]=Ast::id(Ast::child($alias,'ident'));
        $expressionColumns=$columns;foreach($qualifiers as $qualifier)foreach($columns as $c)$expressionColumns[]=$qualifier.'.'.$c;
        $where=Ast::nodes($spec,'where_clause');
        $predicate=$where?Expression::compile(Ast::child($where[0],'expr'),$expressionColumns,$this->database):null;
        $items=Ast::nodes(Ast::child($spec,'select_item_list'),'select_item');$projections=[];$count=false;
        foreach($items as $item){
            Ast::shape($item,['expr','select_alias','table_wild'],['*']);$text=Ast::text($item);
            if($text==='*'){foreach($columns as $c)$projections[]=[$c,['column',$c]];continue;}
            $expr=Ast::child($item,'expr');if(!$expr)throw new \RuntimeException('Unsupported metadata projection');
            $alias=Ast::child($item,'select_alias');$aliasTokens=$alias?Ast::tokens($alias):[];$label=$alias?end($aliasTokens)->get_value():Ast::text($expr);
            if(strtoupper(Ast::text($expr))==='COUNT(*)'){$count=true;$projections[]=[$label,['count']];}
            else {$compiled=Expression::compile($expr,$expressionColumns,$this->database);if(!$alias&&$compiled[0]==='column'){$tokens=Ast::tokens($expr);$label=end($tokens)->get_value();}$projections[]=[$label,$compiled];}
        }
        $labels=array_column($projections,0);if(count(array_unique($labels))!==count($labels))throw new \RuntimeException('Duplicate metadata output labels are not supported');
        if($count&&count($projections)!==1)throw new \RuntimeException('Only standalone metadata COUNT(*) is supported');
        $orders=[];foreach(Ast::nodes($query,'order_expr') as $order)$orders[]=[Expression::compile(Ast::child($order,'expr'),$expressionColumns,$this->database),in_array('DESC',Ast::words($order),true)?-1:1];
        $rows=$this->filter($this->infoRows($kind,Expression::tableFilter($predicate)),$predicate);
        if($orders)usort($rows,function($a,$b)use($orders){foreach($orders as [$expr,$direction]){$c=Expression::compare(Expression::evaluate($expr,$a),Expression::evaluate($expr,$b));if($c)return $c*$direction;}return 0;});
        $output=[];if($count)$output=[[$projections[0][0]=>(string)count($rows)]];
        else foreach($rows as $row){$projected=[];foreach($projections as [$label,$expr])$projected[$label]=Expression::evaluate($expr,$row);$output[]=$projected;}
        $limits=Ast::nodes($query,'limit_options');if($limits){$options=$limits[0]->get_child_nodes('limit_option');$values=[];foreach($options as $option){$value=Ast::text($option);if(!ctype_digit($value))throw new \RuntimeException('Literal metadata LIMIT required');$values[]=(int)$value;}if(count($values)===1)$output=array_slice($output,0,$values[0]);elseif(in_array('OFFSET',Ast::words($limits[0]),true))$output=array_slice($output,$values[1],$values[0]);else $output=array_slice($output,$values[0],$values[1]);}
        return $output;
    }
}
