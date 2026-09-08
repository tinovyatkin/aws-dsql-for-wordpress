<?php
namespace WPDSQL\MySQL\Translation;

/** Bind current values and resolve schema-dependent instructions at execution time. */
final class Renderer {
    private array $columns=[];
    private array $indexes=[];
    private array $params=[];
    private array $aliases=[];
    private array $unionTextColumns=[];
    public function __construct(private \PDO $pdo,private string $schema,private bool $codec) {}
    public static function qi(string $name):string {return '"'.str_replace('"','""',$name).'"';}
    public function render(array|string $node,Shape $shape):array {
        $this->params=[];$sql=$this->emit($node,$shape);return ['sql'=>$sql,'params'=>$this->params];
    }
    public function statements(array|string $node,Shape $shape):array {
        if(!is_array($node)||$node['op']!=='replace')return [$this->render($node,$shape)];
        if(count($node['rows'])!==1)throw new \RuntimeException('REPLACE currently requires one VALUES row');
        $table=$node['table'];$node['columns']=$this->targetColumns($table,$node['columns']);if(count($node['columns'])!==count($node['rows'][0]))throw new \RuntimeException('REPLACE column/value count mismatch');$row=array_combine($node['columns'],$node['rows'][0]);$this->params=[];$conditions=[];
        foreach($this->keys($table) as $key)if($key['unique']) {
            $parts=[];$skip=false;$paramStart=count($this->params);
            foreach($key['columns'] as $name) {
                $meta=$this->metadata($table)[strtolower($name)];
                if(!array_key_exists($name,$row)) {
                    if($meta['is_identity']==='YES'||($meta['is_nullable']==='YES'&&$meta['column_default']===null)){$skip=true;break;}
                    throw new \RuntimeException('REPLACE requires values for non-identity unique columns');
                }
                $v=$row[$name];if($this->generatedIdentity($v,$meta,$shape)){$skip=true;break;}if(is_string($v)&&strtoupper($v)==='NULL'){$skip=true;break;}
                if(!is_array($v)||$v['op']!=='slot')throw new \RuntimeException('REPLACE unique-key values must be direct literals');
                $field=['op'=>'column','name'=>$name,'qualifier'=>null,'tables'=>[$table]];
                $parts[]=self::qi($name).' = '.$this->value($v,$shape,$field,true);
            }
            if(!$skip)$conditions[]='('.implode(' AND ',$parts).')';else $this->params=array_slice($this->params,0,$paramStart);
        }
        $delete=['sql'=>'DELETE FROM '.self::qi($table).' WHERE '.($conditions?implode(' OR ',$conditions):'FALSE'),'params'=>$this->params,'atomic'=>true];
        $node['op']='insert';$insert=$this->render($node,$shape);$insert['atomic']=true;
        return [$delete,$insert];
    }
    private function bind(mixed $value):string {$this->params[]=$value;return '?';}
    private function metadata(string $table):array {
        if(!isset($this->columns[$table])) {
            $s=$this->pdo->prepare('SELECT column_name,data_type,is_nullable,column_default,is_identity FROM information_schema.columns WHERE table_schema=? AND table_name=? ORDER BY ordinal_position');
            $s->execute([$this->schema,$table]);$this->columns[$table]=[];
            foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $r)$this->columns[$table][strtolower($r['column_name'])]=$r;
        }return $this->columns[$table];
    }
    private function keys(string $table):array {
        if(!isset($this->indexes[$table])) {
            $s=$this->pdo->prepare('SELECT i.indexrelid,i.indisprimary,i.indisunique,a.attname,k.ordinality FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(attnum,ordinality) JOIN pg_attribute a ON a.attrelid=t.oid AND a.attnum=k.attnum WHERE t.relname=? AND n.nspname=? AND i.indisvalid AND k.ordinality<=i.indnkeyatts ORDER BY i.indisprimary DESC,i.indexrelid,k.ordinality');
            $s->execute([$table,$this->schema]);$keys=[];foreach($s->fetchAll(\PDO::FETCH_ASSOC) as $r){$k=(string)$r['indexrelid'];$keys[$k]['primary']=in_array($r['indisprimary'],[true,1,'1','t','true'],true);$keys[$k]['unique']=in_array($r['indisunique'],[true,1,'1','t','true'],true);$keys[$k]['columns'][]=$r['attname'];}$this->indexes[$table]=array_values($keys);
        }return $this->indexes[$table];
    }
    private function info(array $column):?array {
        $matches=[];foreach($column['tables'] as $table){$meta=$this->metadata($table);if(isset($meta[strtolower($column['name'])]))$matches[]=array_merge($meta[strtolower($column['name'])],['table'=>$table]);}
        return count($matches)===1?$matches[0]:null;
    }
    private function column(array $column):string {
        $info=$this->info($column);$name=$info['column_name']??$column['name'];$alias=$column['qualifier'];
        if($alias!==null)$alias=$this->aliases[$alias]??$alias;
        return ($alias!==null?self::qi($alias).'.':'').self::qi($name);
    }
    private function temporal(?array $info):bool {return $info&&in_array($info['data_type'],['timestamp without time zone','timestamp with time zone','date'],true);}
    private function numberType(?array $info):bool {return $info&&in_array($info['data_type'],['bigint','integer','smallint','numeric','real','double precision'],true);}
    private function value(array $slot,Shape $shape,?array $column=null,bool $write=false):string {
        $data=$shape->slots[$slot['index']]??throw new \RuntimeException('Translation cache binding mismatch');
        if($data['kind']!==$slot['kind'])throw new \RuntimeException('Translation cache slot type mismatch');
        if($data['kind']==='radix')throw new \RuntimeException('Hexadecimal/binary SQL literals require explicit binary handling');
        $info=$column?$this->info($column):null;$value=$data['value'];
        if($data['kind']==='number'&&$column===null) {
            if(!preg_match('/^(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D',$value))throw new \RuntimeException('Invalid numeric binding');
            return $value; // Structural numbers (e.g. ORDER BY 1) retain their SQL role.
        }
        if($this->temporal($info))$value=preg_replace('/^0000-00-00(?= |$)/','0001-01-01',$value);
        elseif($data['kind']==='string') {
            if(str_contains($value,"\0")) {
                if(!$this->codec||!$write||!$info||$info['data_type']!=='text')throw new \RuntimeException('NUL encoding requires direct assignment to unindexed TEXT');
                foreach($this->keys($info['table']) as $key)if(in_array($info['column_name'],$key['columns'],true))throw new \RuntimeException('NUL encoding is unsupported on indexed columns');
            }
            if($this->codec)$value=\DSQL_Value_Codec::encode($value);
        }
        $param=$this->bind($value);
        if($column===null&&$data['kind']==='string')return 'CAST('.$param.' AS text)';
        return $param;
    }
    private function numeric(array|string $node,Shape $shape):string {
        if(is_array($node)&&($node['op']??null)==='column') {
            $sql=$this->column($node);$info=$this->info($node);
            if($info&&!$this->numberType($info)) {
                $pattern="'^[[:space:]]*([+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?)'";
                return '(CASE WHEN '.$sql.' IS NULL THEN NULL WHEN '.$sql.' ~ '.$pattern.' THEN CAST(substring('.$sql.' FROM '.$pattern.') AS numeric) ELSE 0 END)';
            }return $sql;
        }
        if(is_array($node)&&($node['op']??null)==='slot'&&$node['kind']==='string') {
            $v=$shape->slots[$node['index']]['value'];preg_match('/^[\s]*[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/',$v,$m);
            return 'CAST('.$this->bind(trim($m[0]??'0')).' AS numeric)';
        }
        if(is_array($node)&&$node['op']==='function'&&$node['name']==='DATE_FORMAT'){
            $pattern="'^[[:space:]]*([+-]?([0-9]+([.][0-9]*)?|[.][0-9]+)([eE][+-]?[0-9]+)?)'";
            return '(SELECT CASE WHEN v IS NULL THEN NULL WHEN v ~ '.$pattern.' THEN CAST(substring(v FROM '.$pattern.') AS numeric) ELSE 0 END FROM (SELECT '.$this->emit($node,$shape).' AS v) AS _wpd_numeric)';
        }
        if(is_array($node)&&in_array($node['op']??'',['compare','regex','logical','like','in','between','boolean_sql','is_truth'],true))return '(CASE WHEN '.$this->emit($node,$shape).' THEN 1 ELSE 0 END)';
        return $this->emit($node,$shape);
    }
    private function truth(array|string $node,Shape $shape):string {
        if(is_array($node)&&in_array($node['op']??'',['compare','regex','logical','like','in','between','boolean_sql','is_truth'],true))return $this->emit($node,$shape);
        if(is_string($node)&&in_array(strtoupper($node),['TRUE','FALSE','NULL'],true))return $node;
        if(is_array($node)&&$node['op']==='sql') { // Parenthesized boolean expressions preserve their type.
            $parts=$node['parts'];if(count($parts)===3&&$parts[0]==='('&&$parts[2]===')')return '('.$this->truth($parts[1],$shape).')';
        }
        return '('.$this->numeric($node,$shape).' <> 0)';
    }
    private function compare(array $n,Shape $shape):string {
        $a=$n['left'];$b=$n['right'];$op=match($n['operator']){'<=>'=>'IS NOT DISTINCT FROM','!='=>'<>',default=>$n['operator']};
        if($this->binary($a)||$this->binary($b))return '('.$this->binaryValue($a,$shape).' '.$op.' '.$this->binaryValue($b,$shape).')';
        $formatted=static fn($n)=>is_array($n)&&$n['op']==='function'&&$n['name']==='DATE_FORMAT';
        $number=static fn($n)=>is_array($n)&&$n['op']==='slot'&&$n['kind']==='number';
        if(($formatted($a)&&$number($b))||($formatted($b)&&$number($a)))return '('.$this->numeric($a,$shape).' '.$op.' '.$this->numeric($b,$shape).')';
        $col=is_array($a)&&$a['op']==='column'?$a:null;$other=is_array($b)&&$b['op']==='slot'?$b:null;
        if($col&&$other) {
            $info=$this->info($col);
            if($other['kind']==='number'&&!$this->numberType($info))return '('.$this->numeric($a,$shape).' '.$op.' '.$this->numeric($b,$shape).')';
            $left=$this->column($col);$right=$this->value($other,$shape,$col);
            if($info&&str_ends_with($info['table'],'_users')&&in_array($info['column_name'],['user_login','user_email'],true)&&$op==='=')return '(LOWER('.$left.') = LOWER('.$right.'))';
            return '('.$left.' '.$op.' '.$right.')';
        }
        if(is_array($b)&&$b['op']==='column'&&is_array($a)&&$a['op']==='slot') {
            $inverse=['<'=>'>','>'=>'<','<='=>'>=','>='=>'<='];return $this->compare(['left'=>$b,'right'=>$a,'operator'=>$inverse[$op]??$op],$shape);
        }
        return '('.$this->emit($a,$shape).' '.$op.' '.$this->emit($b,$shape).')';
    }
    private function binary(array|string $n): bool {return is_array($n)&&$n['op']==='cast'&&$n['type']==='binary';}
    private function binaryValue(array|string $n,Shape $s): string {return "CONVERT_TO(CAST(".$this->emit($this->binary($n)?$n['value']:$n,$s)." AS text),'UTF8')";}
    private function regexValue(array|string $n,Shape $s): string {return $this->emit($this->binary($n)?$n['value']:$n,$s);}
    private function assignmentValue(array|string $n,array $field,Shape $shape):string {
        return is_array($n)&&$n['op']==='slot'?$this->value($n,$shape,$field,true):$this->emit($n,$shape);
    }
    private function expressionType(array|string $n):?string {
        if(!is_array($n))return null;
        if($n['op']==='slot')return $n['kind']==='string'?'text':'number';
        if($n['op']==='column'){$info=$this->info($n);return $info?($this->numberType($info)?'number':($this->temporal($info)?'date':'text')):null;}
        if($n['op']==='cast')return $n['type']==='text'?'text':null;
        return null;
    }
    private function emit(array|string $n,Shape $s):string {
        if(is_string($n))return $n;
        switch($n['op']) {
            case 'term_distinct':
                $unique=false;foreach($this->keys($n['table']) as $key)if($key['unique']&&$key['columns']===['term_id']&&($this->metadata($n['table'])['term_id']['is_nullable']??'YES')==='NO')$unique=true;
                if(!$unique)throw new \RuntimeException('Distinct term ordering requires a non-null unique term_id');
                return $this->emit($n['body'],$s);
            case 'query':
                $saved=$this->unionTextColumns;$this->unionTextColumns=[];$types=[];
                foreach($n['union_types'] as $branch)foreach($branch as $i=>$value){$type=$this->expressionType($value);if($type)$types[$i][$type]=true;}
                foreach($types as $i=>$set)if(isset($set['text'],$set['number']))$this->unionTextColumns[$i]=true;
                $body=$n['body'];foreach($n['discarded_order'] as $column)if($this->info($column)===null){$body=$n['ordered_body'];break;}
                if($n['calendar_column']!==null&&!$this->temporal($this->info($n['calendar_column'])))$body=$n['ordered_body'];
                try{return $this->emit($body,$s);}finally{$this->unionTextColumns=$saved;}
            case 'select_item':
                $value=$this->emit($n['value'],$s);
                if(isset($this->unionTextColumns[$n['position']])&&$this->expressionType($n['value'])==='number')$value='CAST('.$value.' AS text)';
                return $value.' '.$this->emit($n['alias'],$s);
            case 'sql':return implode(' ',array_values(array_filter(array_map(fn($p)=>$this->emit($p,$s),$n['parts']),fn($p)=>$p!=='')));
            case 'identifier':return self::qi($n['name']);
            case 'column':return $this->column($n);
            case 'slot':return $this->value($n,$s);
            case 'alias':
                $v=$n['value'];if(!is_array($v)||$v['op']!=='slot'||$v['kind']!=='string')throw new \RuntimeException('Unsupported alias expression');return self::qi($s->slots[$v['index']]['value']);
            case 'boolean_sql':return $this->emit($n['value'],$s);
            case 'truth':return $this->truth($n['value'],$s);
            case 'is_truth':return '('.$this->truth($n['value'],$s).' '.str_replace(['ISNOT','ISTRUE','ISFALSE','ISUNKNOWN'],['IS NOT ','IS TRUE','IS FALSE','IS UNKNOWN'],$n['suffix']).')';
            case 'compare':return $this->compare($n,$s);
            case 'logical':return '('.$this->truth($n['left'],$s).' '.($n['operator']==='XOR'?'<>':$n['operator']).' '.$this->truth($n['right'],$s).')';
            case 'in':
                if($this->binary($n['left'])){if($n['values']===null)throw new \RuntimeException('Binary IN subquery requires explicit support');return '('.$this->binaryValue($n['left'],$s).($n['not']?' NOT IN (':' IN (').implode(',',array_map(fn($v)=>$this->binaryValue($v,$s),$n['values'])).'))';}
                $left=$this->emit($n['left'],$s);$column=is_array($n['left'])&&$n['left']['op']==='column'?$n['left']:null;
                if($n['values']!==null){$values=[];foreach($n['values'] as $value)$values[]=is_array($value)&&$value['op']==='slot'&&$column?$this->value($value,$s,$column):$this->emit($value,$s);$rhs='('.implode(',',$values).')';}else $rhs=$this->emit($n['subquery'],$s);
                return '('.$left.($n['not']?' NOT IN ':' IN ').$rhs.')';
            case 'between':
                if($this->binary($n['left']))return '('.$this->binaryValue($n['left'],$s).($n['not']?' NOT BETWEEN ':' BETWEEN ').$this->binaryValue($n['low'],$s).' AND '.$this->binaryValue($n['high'],$s).')';
                $column=is_array($n['left'])&&$n['left']['op']==='column'?$n['left']:null;
                $bound=fn($v)=>$column&&is_array($v)&&$v['op']==='slot'?$this->value($v,$s,$column):$this->emit($v,$s);
                return '('.$this->emit($n['left'],$s).($n['not']?' NOT BETWEEN ':' BETWEEN ').$bound($n['low']).' AND '.$bound($n['high']).')';
            case 'regex':return '('.$this->regexValue($n['left'],$s).($n['not']?' !~ ':' ~ ').$this->regexValue($n['right'],$s).')';
            case 'delete_self_join':
                $key=null;foreach($this->keys($n['table']) as $k)if($k['primary']){$key=$k['columns'];break;}if(!$key)throw new \RuntimeException('Self-join DELETE requires a primary key');
                $columns=implode(',',array_map(self::qi(...),$key));$target=count($key)>1?'('.$columns.')':$columns;
                $selected=implode(',',array_map(fn($c)=>self::qi($n['target']).'.'.self::qi($c),$key));
                return 'DELETE FROM '.self::qi($n['table']).' WHERE '.$target.' IN (SELECT '.$selected.' FROM '.$this->emit($n['from'],$s).' '.$this->emit($n['where'],$s).')';
            case 'like':if($this->binary($n['left'])||$this->binary($n['right'])){if($n['escape'])throw new \RuntimeException('Binary LIKE ESCAPE requires explicit support');return '('.$this->binaryValue($n['left'],$s).($n['not']?' NOT LIKE ':' LIKE ').$this->binaryValue($n['right'],$s).')';}return '('.$this->emit($n['left'],$s).($n['not']?' NOT ILIKE ':' ILIKE ').$this->emit($n['right'],$s).($n['escape']?' ESCAPE '.$this->emit($n['escape'],$s):'').')';
            case 'arithmetic':
                $a=$this->numeric($n['left'],$s);$b=$this->numeric($n['right'],$s);$op=match($n['operator']){'DIV'=>'/','MOD'=>'%','^'=>'#',default=>$n['operator']};
                return $n['operator']==='DIV'?'TRUNC('.$a.' / NULLIF('.$b.',0))':'('.$a.' '.$op.' '.$b.')';
            case 'unary':return '('.$n['operator'].$this->numeric($n['value'],$s).')';
            case 'cast':if($n['type']==='binary')return $this->binaryValue($n,$s);return 'CAST('.($n['type']==='bigint'||$n['type']==='numeric(20)'?$this->numeric($n['value'],$s):$this->emit($n['value'],$s)).' AS '.$this->emit($n['type'],$s).')';
            case 'function':return $this->function($n,$s);
            case 'aggregate':
                $name=match($n['name']){'STD'=>'STDDEV_POP','VARIANCE'=>'VAR_POP',default=>$n['name']};
                $args=array_map(fn($a)=>in_array($name,['SUM','AVG'],true)?$this->numeric($a,$s):$this->emit($a,$s),$n['args']);
                if($name==='GROUP_CONCAT') {
                    if(count($args)!==1)throw new \RuntimeException('GROUP_CONCAT requires one expression');
                    $sep=$n['separator']?$this->emit($n['separator'],$s):"','";
                    return 'STRING_AGG('.($n['distinct']?'DISTINCT ':'').'CAST('.$args[0].' AS text), '.$sep.($n['order']?' '.$this->emit($n['order'],$s):'').')';
                }
                return $name.'('.($n['distinct']?'DISTINCT ':'').implode(',',$args).')';
            case 'date_math':
                if(!in_array($n['unit'],['microsecond','second','minute','hour','day','week','month','quarter','year'],true))throw new \RuntimeException('Unsupported interval unit');
                $unit=$n['unit']==='quarter'?'3 month':'1 '.$n['unit'];
                return '(CAST('.$this->emit($n['date'],$s).' AS timestamp)'.($n['subtract']?' - ':' + ').'('.$this->numeric($n['amount'],$s)." * INTERVAL '".$unit."'))";
            case 'insert':return $this->insert($n,$s);
            case 'replace':throw new \RuntimeException('REPLACE requires atomic execution');
            case 'update':case 'delete':return $this->write($n,$s);
            case 'delete_join':
                [$a,$b]=$n['aliases'];$target=$n['targets'][0];$other=$target===$a?$b:$a;
                $where=$this->truth($n['where'],$s);
                if(count($n['targets'])===2){$this->aliases=[$a=>$b,$b=>$a];try{$where='('.$where.') OR ('.$this->truth($n['where'],$s).')';}finally{$this->aliases=[];}}
                return 'DELETE FROM '.self::qi($n['table']).' AS '.self::qi($target).' USING '.self::qi($n['table']).' AS '.self::qi($other).' WHERE '.$where;
            default:throw new \RuntimeException('Unknown translation instruction');
        }
    }
    private function function(array $n,Shape $s):string {
        $name=$n['name'];$a=$n['args'];$emit=fn($i)=>$this->emit($a[$i]??throw new \RuntimeException('Missing function argument'),$s);
        if($name==='VERSION'){
            if($a)throw new \RuntimeException('VERSION takes no arguments');
            return 'CAST('.$this->bind(\WPDSQL\Engine\Driver::MYSQL_VERSION.'-Aurora-DSQL-compat').' AS text)';
        }
        if(in_array($name,['CURRENT_USER','USER','SESSION_USER'],true))return $name==='SESSION_USER'?'SESSION_USER':'CURRENT_USER';
        if(in_array($name,['UTC_TIMESTAMP','UTC_DATE','UTC_TIME'],true))return match($name){'UTC_TIMESTAMP'=>"(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')",'UTC_DATE'=>"CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS date)",default=>"CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS time)"};
        if(in_array($name,['NOW','CURRENT_TIMESTAMP','SYSDATE','CURDATE','CURTIME'],true))return match($name){'CURDATE'=>'CURRENT_DATE','CURTIME'=>'CURRENT_TIME',default=>'CURRENT_TIMESTAMP'};
        if($name==='CONCAT') {
            if(!$a)throw new \RuntimeException('CONCAT requires arguments');
            return '('.implode(' || ',array_map(fn($arg)=>'CAST('.$this->emit($arg,$s).' AS text)',$a)).')';
        }
        if($name==='RAND'){if($a)throw new \RuntimeException('Seeded RAND requires explicit support');return 'RANDOM()';}
        if($name==='IF'){if(count($a)!==3)throw new \RuntimeException('IF requires three arguments');return '(CASE WHEN '.$this->truth($a[0],$s).' THEN '.$emit(1).' ELSE '.$emit(2).' END)';}
        if($name==='NULLIF'&&count($a)===2&&is_string($a[1])&&in_array(strtoupper($a[1]),['TRUE','FALSE'],true))return 'NULLIF('.$this->truth($a[0],$s).','.$a[1].')';
        if($name==='FIELD') {
            if(count($a)<2)throw new \RuntimeException('FIELD requires comparands');$sql='CASE';foreach(array_slice($a,1) as $i=>$b)$sql.=' WHEN '.$this->compare(['left'=>$a[0],'right'=>$b,'operator'=>'='],$s).' THEN '.($i+1);return '('.$sql.' ELSE 0 END)';
        }
        if(in_array($name,['WEEK','DAYOFYEAR','DAYOFWEEK','WEEKDAY'],true)){
            if(count($a)<1||count($a)>($name==='WEEK'?2:1))throw new \RuntimeException('Invalid calendar function arity');
            $mode=0;if(isset($a[1])){if(!is_array($a[1])||$a[1]['op']!=='slot'||$a[1]['kind']!=='number')throw new \RuntimeException('Literal WEEK mode required');$raw=$s->slots[$a[1]['index']]['value'];if(!preg_match('/^[0-7]$/D',$raw))throw new \RuntimeException('WEEK mode must be between 0 and 7');$mode=(int)$raw;}
            return Calendar::sql($name,$emit(0),$mode);
        }
        if(in_array($name,['YEAR','MONTH','DAY','DAYOFMONTH','HOUR','MINUTE','SECOND'],true))return 'EXTRACT('.($name==='DAYOFMONTH'?'DAY':$name).' FROM '.$emit(0).')';
        if($name==='UNIX_TIMESTAMP')return 'EXTRACT(EPOCH FROM '.($a?$emit(0):'CURRENT_TIMESTAMP').')';
        if($name==='DATE_FORMAT') {
            if(count($a)!==2||!is_array($a[1])||$a[1]['op']!=='slot')throw new \RuntimeException('DATE_FORMAT requires a literal format');
            $format=$s->slots[$a[1]['index']]['value'];$map=['%Y'=>'YYYY','%y'=>'YY','%m'=>'MM','%d'=>'DD','%H'=>'HH24','%i'=>'MI','%s'=>'SS','%M'=>'Month','%b'=>'Mon','%%'=>'%'];
            $out='';for($i=0;$i<strlen($format);$i++){if($format[$i]==='%'){$part=substr($format,$i,2);if(!isset($map[$part]))throw new \RuntimeException('Unsupported DATE_FORMAT directive');$out.=$map[$part];$i++;}else{$out.='"'.str_replace('"','\\"',$format[$i]).'"';}}
            return 'TO_CHAR(CAST('.$emit(0).' AS timestamp),'.$this->bind($out).')';
        }
        $name=match($name){'IFNULL'=>'COALESCE','LENGTH'=>'OCTET_LENGTH','CHAR_LENGTH','CHARACTER_LENGTH'=>'CHAR_LENGTH','SUBSTR'=>'SUBSTRING',default=>$name};
        if(in_array($name,['COALESCE','CONCAT','CONCAT_WS'],true)&&!$a)throw new \RuntimeException('Function requires arguments');
        return $name.'('.implode(',',array_map(fn($arg)=>$this->emit($arg,$s),$a)).')';
    }
    private function target(array $n):string {return self::qi($n['table']).($n['alias']?' AS '.self::qi($n['alias']):'');}
    private function write(array $n,Shape $s):string {
        $sql=$n['op']==='update'?'UPDATE '.$this->target($n).' SET ':'DELETE FROM '.$this->target($n);
        if($n['op']==='update'){$assign=[];foreach($n['assignments'] as $a)$assign[]=$this->column($a['field']).' = '.$this->assignmentValue($a['value'],$a['field'],$s);$sql.=implode(',',$assign);}
        if($n['limit']!=='') {
            $pk=null;foreach($this->keys($n['table']) as $key)if($key['primary']){$pk=$key['columns'];break;}if(!$pk)throw new \RuntimeException('Limited writes require a primary key');
            $cols=implode(',',array_map(self::qi(...),$pk));$expr=count($pk)>1?'('.$cols.')':$cols;
            return $sql.' WHERE '.$expr.' IN (SELECT '.$cols.' FROM '.$this->target($n).' '.$this->emit($n['where'],$s).' '.$this->emit($n['order'],$s).' '.$this->emit($n['limit'],$s).')';
        }
        return $sql.' '.$this->emit($n['where'],$s);
    }
    private function targetColumns(string $table,?array $columns):array {
        $metadata=$this->metadata($table);
        if($columns===null)return array_column($metadata,'column_name');
        $out=[];foreach($columns as $name){if(!isset($metadata[strtolower($name)]))throw new \RuntimeException('Unknown INSERT column');$out[]=$metadata[strtolower($name)]['column_name'];}
        if(count(array_unique($out))!==count($out))throw new \RuntimeException('Duplicate INSERT column');
        return $out;
    }
    private function generatedIdentity(array|string $value,array $meta,Shape $shape):bool {
        if($meta['is_identity']!=='YES')return false;
        if(is_string($value))return strtoupper($value)==='NULL';
        return $value['op']==='slot'&&preg_match('/^0+(?:[.]0*)?$/D',$shape->slots[$value['index']]['value'])===1;
    }
    private function insert(array $n,Shape $s):string {
        $table=$n['table'];$columns=$this->targetColumns($table,$n['columns']);$rows=[];
        foreach($n['rows'] as $row){if(count($row)!==count($columns))throw new \RuntimeException('INSERT column/value count mismatch');$values=[];foreach($row as $i=>$v){$meta=$this->metadata($table)[strtolower($columns[$i])]??null;$values[]=$meta&&$this->generatedIdentity($v,$meta,$s)?'DEFAULT':$this->assignmentValue($v,['op'=>'column','name'=>$columns[$i],'qualifier'=>null,'tables'=>[$table]],$s);}$rows[]='('.implode(',',$values).')';}
        $sql='INSERT INTO '.self::qi($table).' ('.implode(',',array_map(self::qi(...),$columns)).') VALUES '.implode(',',$rows);
        if($n['updates']) {
            $candidates=[];foreach($this->keys($table) as $key)if($key['unique']&&!array_diff($key['columns'],$columns))$candidates[json_encode($key['columns'],JSON_THROW_ON_ERROR)]=$key['columns'];
            if(count($candidates)>1)throw new \RuntimeException('Upsert with multiple possible unique conflict targets requires explicit support');
            $target=$candidates?reset($candidates):null;
            if(!$target)throw new \RuntimeException('Upsert has no supported unique key');
            $updates=[];foreach($n['updates'] as $a)$updates[]=$this->column($a['field']).' = '.$this->assignmentValue($a['value'],$a['field'],$s);
            $sql.=' ON CONFLICT ('.implode(',',array_map(self::qi(...),$target)).') DO UPDATE SET '.implode(',',$updates);
        }elseif($n['ignore'])$sql.=' ON CONFLICT DO NOTHING';
        return $sql.' RETURNING *';
    }
}
