<?php
namespace WPDSQL\Schema;

/** Versioned logical MySQL metadata shared by restore, installation, DDL and introspection. */
final class Model {
    public const VERSION=2;
    public static function normalize(array $table): array {
        $version=$table['schema_version']??1;
        if(!in_array($version,[1,self::VERSION],true))throw new \RuntimeException('Unsupported logical schema version');
        $root=null;
        if(!isset($table['table_options'])||array_filter($table['columns'],fn($c)=>!isset($c['HasDefault'],$c['DefaultExpression']))){
            if(!empty($table['mysql_ddl']))$root=Ast::statement(\WPDSQL\MySQL\SqlParser::parse($table['mysql_ddl']));
        }
        if(!isset($table['table_options'])){
            $options=['engine'=>'InnoDB','charset'=>'utf8mb4','collation'=>null,'comment'=>''];
            if(!empty($table['mysql_ddl'])){
                if($root->rule_name==='create_table_stmt')$options=Ddl::tableOptions($root);
            }
            $table['table_options']=$options;
        }
        $defaults=[];
        if($root)foreach(Ast::nodes($root,'column_def') as $def){
            $name=Ast::id(Ast::child($def,'ident'));$defaults[$name]=['has'=>false,'expression'=>null];
            foreach(Ast::nodes($def,'column_attribute') as $attribute)if((Ast::words($attribute)[0]??'')==='DEFAULT'){
                $defaults[$name]['has']=true;$literal=Ast::child($attribute,'now_or_signed_literal');
                if($literal){$raw=Ast::text($literal);$defaults[$name]['expression']=preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]?\))?$/i',$raw)?true:Ast::literal($literal)['expression'];}else $defaults[$name]['expression']=true;
            }
        }
        $table['schema_version']=self::VERSION;
        foreach($table['columns'] as &$column){
            $text=self::textType($column['Type']);
            $column+=['Comment'=>'','Privileges'=>'select,insert,update,references','Key'=>'','Extra'=>''];
            $column['Collation']=$text?($column['Collation']??$table['table_options']['collation']??'utf8mb4_unicode_ci'):null;
            $column['HasDefault']??=$defaults[$column['Field']]['has']??($column['Default']!==null);
            $column['DefaultExpression']??=$defaults[$column['Field']]['expression']??str_contains($column['Extra'],'DEFAULT_GENERATED');
        }unset($column);
        return self::keys($table);
    }
    private static function keys(array $table): array {
        foreach($table['columns'] as &$column){
            $rank=0;foreach($table['indexes']??[] as $index){
                if($index['Column_name']!==$column['Field']||in_array($index['Key_name'],$table['omitted_indexes']??[],true))continue;
                $rank=max($rank,$index['Key_name']==='PRIMARY'?3:((int)$index['Seq_in_index']===1?(!(int)$index['Non_unique']?2:1):0));
            }
            $column['Key']=['','MUL','UNI','PRI'][$rank];if($rank===3)$column['Null']='NO';
        }unset($column);return $table;
    }
    public static function textType(string $type): bool {return (bool)preg_match('/^(?:var)?char\b|^(?:tiny|medium|long)?text\b|^(?:enum|set)\(/i',$type);}
    public static function columns(array $table,bool $full=true): array {
        $table=self::normalize($table);$keys=$full?['Field','Type','Collation','Null','Key','Default','Extra','Privileges','Comment']:['Field','Type','Null','Key','Default','Extra'];
        return array_map(fn($c)=>array_intersect_key($c,array_flip($keys)),$table['columns']);
    }
    public static function mysql(array $table): string {
        $table=self::normalize($table);$q=self::identifier(...);$parts=[];
        foreach($table['columns'] as $c){
            $s=$q($c['Field']).' '.$c['Type'];
            if($c['Collation'])$s.=' COLLATE '.$q($c['Collation']);
            $s.=$c['Null']==='NO'?' NOT NULL':' NULL';
            if($c['HasDefault'])$s.=' DEFAULT '.($c['Default']===null?'NULL':($c['DefaultExpression']?(preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]?\))?$/i',$c['Default'])?$c['Default']:'('.$c['Default'].')'):self::literal((string)$c['Default'])));
            if(str_contains($c['Extra'],'auto_increment'))$s.=' AUTO_INCREMENT';
            if($c['Comment']!=='')$s.=' COMMENT '.self::literal($c['Comment']);
            $parts[]=$s;
        }
        $groups=[];foreach($table['indexes'] as $index)$groups[$index['Key_name']][]=$index;
        foreach($groups as $name=>$group){
            usort($group,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
            $parts[]=($name==='PRIMARY'?'PRIMARY KEY':($group[0]['Index_type']==='FULLTEXT'?'FULLTEXT KEY ':(!(int)$group[0]['Non_unique']?'UNIQUE KEY ':'KEY ')).$q($name)).' ('.implode(',',array_map(fn($i)=>$q($i['Column_name']).($i['Sub_part']!==null?'('.(int)$i['Sub_part'].')':''),$group)).')'.(!empty($group[0]['Index_comment'])?' COMMENT '.self::literal($group[0]['Index_comment']):'');
        }
        $o=$table['table_options'];
        $sql='CREATE TABLE '.$q($table['name'])." (\n".implode(",\n",$parts)."\n) ENGINE=".$o['engine'].' DEFAULT CHARSET='.$o['charset'];
        if($o['collation'])$sql.=' COLLATE='.$q($o['collation']);
        if($o['comment']!=='')$sql.=' COMMENT='.self::literal($o['comment']);
        return $sql;
    }
    public static function identifier(string $value): string {return '`'.str_replace('`','``',$value).'`';}
    public static function literal(string $value): string {return "'".strtr($value,['\\'=>'\\\\',"'"=>"\\'","\0"=>'\\0',"\n"=>'\\n',"\r"=>'\\r'])."'";}
    public static function columnDescriptor(array $table,array $column,array $native,string $database): array {
        preg_match('/^([a-z]+)(?:\((\d+)(?:,(\d+))?\))?/i',$column['Type'],$parts);$type=strtolower($parts[1]);
        $map=['tinyint'=>['TINY',1,4],'smallint'=>['SHORT',2,6],'mediumint'=>['INT24',9,9],'int'=>['LONG',3,11],'integer'=>['LONG',3,11],'bigint'=>['LONGLONG',8,20],'decimal'=>['NEWDECIMAL',246,0],'numeric'=>['NEWDECIMAL',246,0],'varchar'=>['VAR_STRING',253,0],'char'=>['STRING',254,0],'date'=>['DATE',10,10],'datetime'=>['DATETIME',12,19],'timestamp'=>['TIMESTAMP',7,19],'json'=>['BLOB',245,4294967295],'enum'=>['STRING',254,-1],'set'=>['STRING',254,-1],'varbinary'=>['BLOB',253,0]];
        if(preg_match('/^(tiny|medium|long)?(text|blob)$/',$type))$map[$type]=['BLOB',252,0];
        if(!isset($map[$type]))return $native;
        [$nativeType,$mysqliType,$length]=$map[$type];$flags=[];$bits=0;
        if($column['Null']==='NO'){$flags[]='not_null';$bits|=1;}
        if($column['Key']==='PRI'){$flags[]='primary_key';$bits|=2;}elseif($column['Key']==='UNI'){$flags[]='unique_key';$bits|=4;}elseif($column['Key']==='MUL'){$flags[]='multiple_key';$bits|=8;}
        if($nativeType==='BLOB'){$flags[]='blob';$bits|=16;}
        $unsigned=str_contains($column['Type'],'unsigned');if($unsigned)$bits|=32;
        if(str_contains($column['Extra'],'auto_increment'))$bits|=512;
        $numeric=in_array($mysqliType,[1,2,3,8,9,246],true);if($numeric)$bits|=32768;
        if($type==='enum')$bits|=256;if($type==='set')$bits|=2048;
        $precision=0;
        foreach(self::informationColumns($table,$database) as $info)if($info['COLUMN_NAME']===$column['Field']){
            if($info['CHARACTER_MAXIMUM_LENGTH']!==null)$length=(int)$info['CHARACTER_MAXIMUM_LENGTH'];
            if(self::textType($type)&&$type!=='longtext')$length*=4;
            if($mysqliType===246){$precision=(int)($parts[3]??0);$length=(int)$parts[2]+($unsigned?0:1)+($precision>0?1:0);}
            elseif(in_array($type,['datetime','timestamp'],true)){$precision=(int)($parts[2]??0);if($precision)$length+=1+$precision;}
            elseif($numeric&&isset($parts[2]))$length=(int)$parts[2];
            break;
        }
        $collation=$column['Collation'];$charsetId=match($collation){'utf8mb4_unicode_ci'=>224,'utf8mb4_general_ci'=>45,'utf8mb4_bin'=>46,'utf8mb4_unicode_520_ci'=>246,'utf8mb4_0900_ai_ci'=>255,'binary',null=>63,default=>null};
        if($collation===null&&!$numeric&&!in_array($type,['date','datetime','timestamp'],true))$bits|=128;
        return array_replace($native,['native_type'=>$nativeType,'mysql_type'=>$column['Type'],'pdo_type'=>$numeric&&$mysqliType!==246?\PDO::PARAM_INT:\PDO::PARAM_STR,'flags'=>$flags,'len'=>$length,'precision'=>$precision,'mysqli:orgname'=>$column['Field'],'mysqli:orgtable'=>$table['name'],'mysqli:db'=>$database,'mysqli:type'=>$mysqliType,'mysqli:charsetnr'=>$charsetId,'mysqli:flags'=>$bits]);
    }
    public static function informationColumns(array $table,string $schema): array {
        $rows=[];$table=self::normalize($table);
        foreach($table['columns'] as $i=>$c){
            preg_match('/^([a-z]+)(?:\((\d+)(?:,(\d+))?\))?/i',$c['Type'],$type);
            $base=strtolower($type[1]);$length=null;$bytes=null;
            $charset=$c['Collation']?explode('_',$c['Collation'])[0]:null;
            $width=match($charset){'utf8mb4'=>4,'utf8','utf8mb3'=>3,'ucs2','big5','gbk','sjis'=>2,default=>1};
            if(in_array($base,['varchar','char','varbinary'],true)){$length=(string)$type[2];$bytes=(string)((int)$length*($base==='varbinary'?1:$width));}
            if(preg_match('/^(tiny|medium|long)?(text|blob)$/',$base,$m)){$bytes=(string)match($m[1]??''){'tiny'=>255,'medium'=>16777215,'long'=>4294967295,default=>65535};$length=$bytes;}
            $rows[]=['TABLE_CATALOG'=>'def','TABLE_SCHEMA'=>$schema,'TABLE_NAME'=>$table['name'],'COLUMN_NAME'=>$c['Field'],'ORDINAL_POSITION'=>(string)($i+1),'COLUMN_DEFAULT'=>$c['Default'],'IS_NULLABLE'=>$c['Null'],'DATA_TYPE'=>$base,'CHARACTER_MAXIMUM_LENGTH'=>$length,'CHARACTER_OCTET_LENGTH'=>$bytes,'NUMERIC_PRECISION'=>in_array($base,['decimal','numeric'],true)?$type[2]:null,'NUMERIC_SCALE'=>in_array($base,['decimal','numeric'],true)?($type[3]??'0'):null,'DATETIME_PRECISION'=>in_array($base,['datetime','timestamp'],true)?($type[2]??'0'):null,'CHARACTER_SET_NAME'=>$charset,'COLLATION_NAME'=>$c['Collation'],'COLUMN_TYPE'=>$c['Type'],'COLUMN_KEY'=>$c['Key'],'EXTRA'=>$c['Extra'],'PRIVILEGES'=>$c['Privileges'],'COLUMN_COMMENT'=>$c['Comment']];
        }return $rows;
    }
}
