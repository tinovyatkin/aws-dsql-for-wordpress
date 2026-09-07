<?php
namespace WPDSQLUpgrade;
use WPDSQLMigration\Plan;

/** Parse the bounded MySQL DDL vocabulary emitted by WordPress/dbDelta. */
final class Schema {
    private array $tokens;
    private int $pos = 0;
    public function __construct(string $sql) {
        $this->tokens=[];
        $pattern=<<<'RX'
~\G(?:\s+|--[^\r\n]*|\#[^\r\n]*|/\*.*?\*/|(`(?:``|[^`])*`|'(?:\\.|''|[^'\\])*'|"(?:\\.|""|[^"\\])*"|[a-zA-Z_][a-zA-Z_0-9$]*|[+-]?\d+(?:\.\d+)?|[(),;.=]))~s
RX;
        $offset=0;
        while($offset<strlen($sql)) {
            if(!preg_match($pattern,$sql,$m,0,$offset))throw new \RuntimeException('Unsupported or malformed schema SQL');
            $offset+=strlen($m[0]);if(isset($m[1])&&$m[1]!=='')$this->tokens[]=$m[1];
        }
        if(end($this->tokens)===';')array_pop($this->tokens);
    }
    private function peek(): string {return $this->tokens[$this->pos]??'';}
    private function take(): string {if(!isset($this->tokens[$this->pos]))throw new \RuntimeException('Incomplete schema SQL');return $this->tokens[$this->pos++];}
    private function is(string $word): bool {return strcasecmp($this->peek(),$word)===0;}
    private function eat(string $word): bool {if(!$this->is($word))return false;$this->pos++;return true;}
    private function need(string $word): void {if(!$this->eat($word))throw new \RuntimeException('Expected schema token '.$word);}
    private function id(): string {
        $name=$this->take();if($name[0]==='`'||$name[0]==='"')$name=substr($name,1,-1);
        if(!preg_match('/^[a-zA-Z_][a-zA-Z_0-9]{0,62}$/D',$name))throw new \RuntimeException('Unsupported schema identifier');
        return $name;
    }
    private function table(): string {
        $name=$this->id();if($this->eat('.')){if($name!=='wp_live')throw new \RuntimeException('Only the active application schema may be upgraded');$name=$this->id();}
        if(str_starts_with($name,'__'))throw new \RuntimeException('System schema objects are protected');return $name;
    }
    private function value(): ?string {
        $v=$this->take();if(strcasecmp($v,'NULL')===0)return null;
        if($v[0]==="'"||$v[0]==='"') {
            $q=$v[0];$v=str_replace($q.$q,$q,substr($v,1,-1));
            return preg_replace_callback('/\\\\(.)/s',static fn($m)=>['0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'Z'=>"\x1a"][$m[1]]??$m[1],$v);
        }
        if(preg_match('/^[+-]?\d+(?:\.\d+)?$/D',$v))return $v;
        if(strcasecmp($v,'CURRENT_TIMESTAMP')===0){if($this->eat('('))$this->need(')');return 'CURRENT_TIMESTAMP';}
        throw new \RuntimeException('Only literal or CURRENT_TIMESTAMP defaults are supported');
    }
    private function column(): array {
        $name=$this->id();$type=strtolower($this->id());
        if($this->eat('(')) {
            $args=[];do {$args[]=$this->take();}while($this->eat(','));$this->need(')');$type.='('.implode(',',$args).')';
        }
        if($this->eat('UNSIGNED'))$type.=' unsigned';
        if(!preg_match('/^(?:(?:tinyint|smallint|mediumint|int|integer|bigint)(?:\(\d+\))?(?: unsigned)?|(?:decimal|numeric)\(\d+,\d+\)(?: unsigned)?|(?:var)?char\(\d+\)|(?:tiny|medium|long)?(?:text|blob)|varbinary\(\d+\)|(?:datetime|timestamp)(?:\([0-6]\))?|date|json)$/D',$type))throw new \RuntimeException('Unsupported column type syntax');
        $c=['Field'=>$name,'Type'=>$type,'Collation'=>null,'Null'=>'YES','Key'=>'','Default'=>null,'Extra'=>'','Privileges'=>'select,insert,update,references','Comment'=>''];
        $position=null;$inline=[];
        while($this->peek()!==''&&!$this->is(',')&&!$this->is(')')) {
            if($this->eat('NOT')){$this->need('NULL');$c['Null']='NO';}
            elseif($this->eat('NULL'))$c['Null']='YES';
            elseif($this->eat('DEFAULT'))$c['Default']=$this->value();
            elseif($this->eat('AUTO_INCREMENT')){$c['Extra']='auto_increment';$c['Null']='NO';}
            elseif($this->eat('PRIMARY')){$this->need('KEY');$inline[]=['name'=>'PRIMARY','unique'=>true];$c['Null']='NO';}
            elseif($this->eat('UNIQUE')){$this->eat('KEY');$inline[]=['name'=>$name,'unique'=>true];}
            elseif($this->eat('COMMENT'))$c['Comment']=$this->value()??'';
            elseif($this->eat('COLLATE'))$c['Collation']=$this->id();
            elseif($this->eat('CHARACTER')){$this->need('SET');if(strtolower($this->id())!=='utf8mb4')throw new \RuntimeException('Character-set conversion needs an explicit migration');}
            elseif($this->eat('FIRST'))$position=['first'=>true];
            elseif($this->eat('AFTER'))$position=['after'=>$this->id()];
            else throw new \RuntimeException('Unsupported column attribute');
        }
        Plan::type($c);
        if(Plan::type($c)==='bytea'&&$c['Default']!==null)throw new \RuntimeException('Binary defaults require an explicit migration');
        if(str_starts_with($type,'enum'))throw new \RuntimeException('Enum/constraint upgrades require a dedicated migration');
        return [$c,$position,$inline];
    }
    private function index(string $table): array {
        $unique=false;$type='BTREE';
        if($this->eat('PRIMARY')){$this->need('KEY');$name='PRIMARY';$unique=true;}
        else {
            $unique=$this->eat('UNIQUE');if($this->eat('FULLTEXT'))$type='FULLTEXT';
            if(!$this->eat('KEY'))$this->eat('INDEX');
            $name=$this->is('(')?null:$this->id();
        }
        $this->need('(');$parts=[];
        do {
            $field=$this->id();$prefix=null;
            if($this->eat('(')){$prefix=$this->take();if(!ctype_digit($prefix))throw new \RuntimeException('Invalid index prefix');$prefix=(int)$prefix;$this->need(')');}
            if($this->eat('DESC'))throw new \RuntimeException('Descending index upgrades need explicit support');$this->eat('ASC');
            $parts[]=[$field,$prefix];
        }while($this->eat(','));$this->need(')');$name??=$parts[0][0];
        return array_map(static fn($part,$i)=>['Table'=>$table,'Non_unique'=>$unique?0:1,'Key_name'=>$name,'Seq_in_index'=>$i+1,'Column_name'=>$part[0],'Collation'=>'A','Cardinality'=>null,'Sub_part'=>$part[1],'Packed'=>null,'Null'=>'','Index_type'=>$type,'Comment'=>'','Index_comment'=>'','Visible'=>'YES','Expression'=>null],$parts,array_keys($parts));
    }
    public function parse(): array {
        $kind=strtoupper($this->take());$changes=[];$ifExists=false;
        if($kind==='DROP'&&$this->eat('INDEX')) {
            $name=$this->id();$this->need('ON');$table=$this->table();
            if($this->peek()!=='')throw new \RuntimeException('Trailing DROP INDEX SQL');
            return (new self('ALTER TABLE `'.$table.'` DROP INDEX `'.$name.'`'))->parse();
        }
        if($kind==='CREATE'&&!$this->is('TABLE')) {
            $unique=$this->eat('UNIQUE');$this->need('INDEX');$name=$this->id();$this->need('ON');$table=$this->table();
            $rest=implode(' ',array_slice($this->tokens,$this->pos));return (new self('ALTER TABLE `'.$table.'` ADD '.($unique?'UNIQUE ':'').'INDEX `'.$name.'` '.$rest))->parse();
        }
        if($kind==='RENAME'){$this->need('TABLE');$table=$this->table();$this->need('TO');$changes[]=['op'=>'rename','name'=>$this->table()];$kind='ALTER';}
        else {
            $this->need('TABLE');
            if($this->eat('IF')) {if($kind==='CREATE'){$this->need('NOT');}$this->need('EXISTS');$ifExists=true;}
            $table=$this->table();
            if($kind==='CREATE') {
                $this->need('(');$columns=[];$indexes=[];
                do {
                    if(in_array(strtoupper($this->peek()),['PRIMARY','UNIQUE','KEY','INDEX','FULLTEXT'],true))$indexes=array_merge($indexes,$this->index($table));
                    else {[$c,$pos,$inline]=$this->column();if($pos)throw new \RuntimeException('Column positioning is only valid in ALTER');$columns[]=$c;foreach($inline as $key)$indexes=array_merge($indexes,(new self('ALTER TABLE '.$table.' ADD '.($key['name']==='PRIMARY'?'PRIMARY KEY':'UNIQUE KEY `'.$key['name'].'`').' (`'.$c['Field'].'`)'))->parse()['changes'][0]['indexes']);}
                }while($this->eat(','));$this->need(')');
                while($this->peek()!=='') {
                    $this->eat('DEFAULT');
                    if($this->eat('ENGINE')){$this->eat('=');if(strcasecmp($this->id(),'InnoDB')!==0)throw new \RuntimeException('Only InnoDB source semantics supported');}
                    elseif($this->eat('CHARSET')||$this->eat('CHARACTER')) {if($this->is('SET'))$this->take();$this->eat('=');if(strtolower($this->id())!=='utf8mb4')throw new \RuntimeException('Unsupported table charset');}
                    elseif($this->eat('COLLATE')){$this->eat('=');$collation=$this->id();}
                    else throw new \RuntimeException('Unsupported CREATE TABLE option');
                }
                return ['kind'=>'create','table'=>$table,'if_exists'=>$ifExists,'columns'=>$columns,'indexes'=>$indexes,'collation'=>$collation??null];
            }
            elseif($kind==='DROP') { /* One table per statement; no CASCADE. */ }
            elseif($kind==='ALTER') {
                do {
                    if($this->eat('ADD')) {
                        if(in_array(strtoupper($this->peek()),['PRIMARY','UNIQUE','KEY','INDEX','FULLTEXT'],true))$changes[]=['op'=>'add_index','indexes'=>$this->index($table)];
                        else {$this->eat('COLUMN');[$c,$pos,$inline]=$this->column();$changes[]=['op'=>'add','column'=>$c,'position'=>$pos];foreach($inline as $key)$changes[]=['op'=>'add_index','indexes'=>(new self('ALTER TABLE '.$table.' ADD '.($key['name']==='PRIMARY'?'PRIMARY KEY':'UNIQUE KEY `'.$key['name'].'`').' (`'.$c['Field'].'`)'))->parse()['changes'][0]['indexes']];}
                    }
                    elseif($this->eat('CHANGE')||$this->eat('MODIFY')) {
                        $modify=strcasecmp($this->tokens[$this->pos-1],'MODIFY')===0;$this->eat('COLUMN');$old=$modify?null:$this->id();[$c,$pos,$inline]=$this->column();if($inline)throw new \RuntimeException('Use explicit ADD KEY when modifying columns');$changes[]=['op'=>'change','old'=>$old??$c['Field'],'column'=>$c,'position'=>$pos];
                    }
                    elseif($this->eat('DROP')) {
                        if($this->eat('PRIMARY')){$this->need('KEY');$changes[]=['op'=>'drop_index','name'=>'PRIMARY'];}
                        elseif($this->eat('INDEX')||$this->eat('KEY'))$changes[]=['op'=>'drop_index','name'=>$this->id()];
                        else {$this->eat('COLUMN');$changes[]=['op'=>'drop','name'=>$this->id()];}
                    }
                    elseif($this->eat('ALTER')) {$this->eat('COLUMN');$name=$this->id();if($this->eat('SET')){$this->need('DEFAULT');$value=$this->value();}else{$this->need('DROP');$this->need('DEFAULT');$value=null;}$changes[]=['op'=>'default','name'=>$name,'value'=>$value];}
                    elseif($this->eat('RENAME')) {
                        if($this->eat('COLUMN')){$old=$this->id();$this->need('TO');$changes[]=['op'=>'rename_column','old'=>$old,'name'=>$this->id()];}
                        else {$this->eat('TO');$changes[]=['op'=>'rename','name'=>$this->table()];}
                    }
                    else throw new \RuntimeException('Unsupported ALTER TABLE operation');
                }while($this->eat(','));
            } else throw new \RuntimeException('Unsupported schema statement');
        }
        if($this->peek()!=='')throw new \RuntimeException('Trailing schema SQL is not supported');
        return ['kind'=>strtolower($kind),'table'=>$table,'if_exists'=>$ifExists,'changes'=>$changes];
    }

    public static function apply(array $ddl,?array $before,array $options): ?array {
        if(!str_starts_with($ddl['table'],$options['table_prefix']))throw new \RuntimeException('Table outside the selected WordPress prefix');
        if($before && ($before['archived']??false))throw new \RuntimeException('Archived plugin tables cannot be upgraded');
        if($ddl['kind']==='drop'){if(!$options['allow_destructive'])throw new \RuntimeException('DROP requires an explicitly destructive upgrade session');return null;}
        if($ddl['kind']==='create') {
            if($before){if($ddl['if_exists'])return $before;throw new \RuntimeException('Table already exists');}
            $after=['name'=>$ddl['table'],'columns'=>$ddl['columns'],'indexes'=>$ddl['indexes'],'target_schema'=>'wp_live','archived'=>false,'value_codec'=>true,'omitted_indexes'=>$options['omit_fulltext_indexes'][$ddl['table']]??[],'mysql_ddl'=>''];
            $mapping=[];
        } else {
            if(!$before)throw new \RuntimeException('ALTER requires a catalogued table');$after=$before;$mapping=array_combine(array_column($before['columns'],'Field'),array_column($before['columns'],'Field'));
            foreach($ddl['changes'] as $change) {
                $fields=array_column($after['columns'],'Field');$op=$change['op'];
                if(in_array($op,['add','change','rename_column','drop','default'],true)) {
                    $old=$change['old']??$change['name']??$change['column']['Field'];$i=array_search($old,$fields,true);
                    if($op==='add'){if($i!==false)throw new \RuntimeException('Column already exists');$c=$change['column'];$mapping[$c['Field']]=null;$i=count($fields);}
                    else {if($i===false)throw new \RuntimeException('Unknown source column');$c=$after['columns'][$i];}
                    if($op==='drop') {
                        if(!$options['allow_destructive'])throw new \RuntimeException('Dropping columns requires an explicitly destructive session');
                        array_splice($after['columns'],$i,1);unset($mapping[$old]);$after['indexes']=array_values(array_filter($after['indexes'],static fn($x)=>$x['Column_name']!==$old));continue;
                    }
                    if($op==='default')$c['Default']=$change['value'];
                    if($op==='change') {
                        if($change['column']['Collation']!==null && $change['column']['Collation']!==$c['Collation'])throw new \RuntimeException('Collation changes require a dedicated migration');
                        $c=array_replace($c,$change['column'],['Collation'=>$change['column']['Collation']??$c['Collation']]);
                    }
                    if($op==='rename_column')$c['Field']=$change['name'];
                    if($c['Field']!==$old){if(in_array($c['Field'],$fields,true))throw new \RuntimeException('Rename destination column exists');$mapping[$c['Field']]=$mapping[$old];unset($mapping[$old]);foreach($after['indexes'] as &$idx)if($idx['Column_name']===$old)$idx['Column_name']=$c['Field'];unset($idx);}
                    if($op!=='add')array_splice($after['columns'],$i,1);
                    $position=$change['position']??null;
                    if(isset($position['first']))$i=0;
                    if(isset($position['after'])){$j=array_search($position['after'],array_column($after['columns'],'Field'),true);if($j===false)throw new \RuntimeException('Unknown AFTER column');$i=$j+1;}
                    array_splice($after['columns'],$i,0,[$c]);
                }
                elseif($op==='add_index') {
                    $name=$change['indexes'][0]['Key_name'];$existing=array_filter($after['indexes'],static fn($x)=>$x['Key_name']===$name);
                    if($existing) {
                        if(!in_array($name,$after['omitted_indexes']??[],true))throw new \RuntimeException('Index already exists');
                        $after['indexes']=array_values(array_filter($after['indexes'],static fn($x)=>$x['Key_name']!==$name));
                    }
                    $after['indexes']=array_merge($after['indexes'],$change['indexes']);
                }
                elseif($op==='drop_index') {$name=$change['name'];if(!in_array($name,array_column($after['indexes'],'Key_name'),true))throw new \RuntimeException('Unknown index');$after['indexes']=array_values(array_filter($after['indexes'],static fn($x)=>$x['Key_name']!==$name));}
                elseif($op==='rename') {$after['name']=$change['name'];if(!str_starts_with($after['name'],$options['table_prefix'])||str_starts_with($after['name'],'__'))throw new \RuntimeException('Invalid table rename destination');}
            }
        }
        if(!$after['columns']||count(array_unique(array_column($after['columns'],'Field')))!==count($after['columns']))throw new \RuntimeException('Invalid resulting column set');
        if(count(array_filter($after['columns'],static fn($c)=>str_contains($c['Extra'],'auto_increment')))>1)throw new \RuntimeException('MySQL permits only one AUTO_INCREMENT column');
        $sequences=[];foreach($after['indexes'] as &$index){$index['Table']=$after['name'];$index['Seq_in_index']=$sequences[$index['Key_name']]=($sequences[$index['Key_name']]??0)+1;if(!in_array($index['Column_name'],array_column($after['columns'],'Field'),true))throw new \RuntimeException('Index refers to unknown column');}unset($index);
        foreach($after['columns'] as &$c){$c['Key']='';Plan::type($c);foreach($after['indexes'] as $index)if($index['Column_name']===$c['Field']){$c['Key']=$index['Key_name']==='PRIMARY'?'PRI':($index['Non_unique']?'MUL':'UNI');if($c['Key']==='PRI'){$c['Null']='NO';break;}}}unset($c);
        Plan::indexes($after);$after['mysql_ddl']=self::mysql($after);$after['mapping']=$mapping;return $after;
    }
    public static function mysql(array $table): string {
        $q=static fn($s)=>'`'.str_replace('`','``',$s).'`';$parts=[];
        foreach($table['columns'] as $c){$s=$q($c['Field']).' '.$c['Type'].($c['Null']==='NO'?' NOT NULL':' NULL');if($c['Default']!==null)$s.=' DEFAULT '.($c['Default']==='CURRENT_TIMESTAMP'&&Plan::temporal($c)?'CURRENT_TIMESTAMP':"'".strtr((string)$c['Default'],['\\'=>'\\\\',"'"=>"\\'","\0"=>'\\0'])."'");if($c['Extra']==='auto_increment')$s.=' AUTO_INCREMENT';$parts[]=$s;}
        $groups=[];foreach($table['indexes'] as $i)$groups[$i['Key_name']][]=$i;
        foreach($groups as $name=>$group)$parts[]=($name==='PRIMARY'?'PRIMARY KEY':($group[0]['Index_type']==='FULLTEXT'?'FULLTEXT KEY ':(!$group[0]['Non_unique']?'UNIQUE KEY ':'KEY ')).$q($name)).' ('.implode(',',array_map(static fn($i)=>$q($i['Column_name']).($i['Sub_part']!==null?'('.$i['Sub_part'].')':''),$group)).')';
        return 'CREATE TABLE '.$q($table['name'])." (\n".implode(",\n",$parts)."\n)";
    }
}
