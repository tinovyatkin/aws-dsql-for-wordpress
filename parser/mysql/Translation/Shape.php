<?php
namespace WPDSQL\MySQL\Translation;

/** Lightweight lexical cache key. It recognizes boundaries, never rewrites SQL syntax. */
final class Shape {
    public array $slots=[];
    public array $offsets=[];
    public string $key;
    public string $template;
    public string $operation='';
    public function __construct(public readonly string $sql, public readonly string $sqlMode='') {
        if(!mb_check_encoding($sql,'UTF-8'))throw new \RuntimeException('SQL input must be UTF-8');
        $modes=array_map('trim',explode(',',strtoupper($sqlMode)));
        $ansi=(bool)array_intersect($modes,['ANSI_QUOTES','ANSI','DB2','MAXDB','MSSQL','ORACLE','POSTGRESQL']);
        $noEscape=in_array('NO_BACKSLASH_ESCAPES',$modes,true);
        $parts=[];$compile=[];$charOffset=0;$n=strlen($sql);
        for($i=0;$i<$n;){
            $start=$i;$c=$sql[$i];$replacement=null;
            if(ctype_space($c)) {while($i<$n&&ctype_space($sql[$i]))$i++;$parts[]='ws:'.substr($sql,$start,$i-$start);}
            elseif(substr($sql,$i,2)==='/*') {
                if(in_array($sql[$i+2]??'',['!','+'],true))throw new \RuntimeException('Executable SQL comments and optimizer hints require explicit translation');
                $end=strpos($sql,'*/',$i+2);if($end===false)throw new \RuntimeException('Unterminated SQL comment');$i=$end+2;$parts[]='comment:'.substr($sql,$start,$i-$start);
            } elseif($c==='#'||(substr($sql,$i,2)==='--'&&($i+2===$n||ctype_space($sql[$i+2])))) {
                while($i<$n&&!str_contains("\r\n",$sql[$i]))$i++;$parts[]='comment:'.substr($sql,$start,$i-$start);
            } elseif($c==="'"||$c==='"'||$c==='`') {
                $identifier=$c==='`'||($ansi&&$c==='"');$value='';$closed=false;$i++;
                for(;$i<$n;$i++) {
                    $ch=$sql[$i];
                    if($ch===$c) {if($i+1<$n&&$sql[$i+1]===$c){$value.=$c;$i++;}else{$closed=true;$i++;break;}}
                    elseif($ch==='\\'&&!$noEscape&&!$identifier&&$i+1<$n){$ch=$sql[++$i];$value.=match($ch){'0'=>"\0",'n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'Z'=>"\x1a",'%','_'=>'\\'.$ch,default=>$ch};}
                    else $value.=$ch;
                }
                if(!$closed)throw new \RuntimeException('Unterminated SQL quoted token');
                if($identifier)$parts[]='i:'.substr($sql,$start,$i-$start);
                else {$slot=count($this->slots);$this->offsets[$charOffset]=$slot;$this->slots[]=['kind'=>'string','value'=>$value,'raw'=>substr($sql,$start,$i-$start)];$parts[]='s';$replacement="'__wpdsql_value__'";}
            } elseif(preg_match('/\G(?:0[xX][0-9a-fA-F]+|0[bB][01]+|(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/A',$sql,$m,0,$i)) {
                $i+=strlen($m[0]);$kind=preg_match('/^0[xb]/i',$m[0])?'radix':'number';
                $slot=count($this->slots);$this->offsets[$charOffset]=$slot;$this->slots[]=['kind'=>$kind,'value'=>$m[0],'raw'=>$m[0]];$numericClass=strpbrk($m[0],'eE')!==false?'float':(str_contains($m[0],'.')?'decimal':'integer');
                $adjacent=$i<$n&&preg_match('/[a-zA-Z_$\x80-\xff]/',$sql[$i]);
                if($numericClass==='integer'&&$kind==='number') {
                    $digits=ltrim($m[0],'0')?:'0';$dummy='18446744073709551616';
                    foreach(['2147483647'=>'1','9223372036854775807'=>'2147483648','18446744073709551615'=>'9223372036854775808'] as $max=>$sample)if(strlen($digits)<strlen((string)$max)||(strlen($digits)===strlen((string)$max)&&strcmp($digits,(string)$max)<=0)){$dummy=$sample;break;}
                    $numericClass.=':'.$dummy;
                }else $dummy=$kind==='radix'?$m[0]:($numericClass==='float'?'1e0':'1.0');
                $parts[]=$kind.':'.$numericClass.($adjacent?':'.$m[0]:'');
                if(!$adjacent)$replacement=$dummy;
            } elseif(preg_match('/\G[a-zA-Z_$\x80-\xff][a-zA-Z_0-9$\x80-\xff]*/A',$sql,$m,0,$i)) {
                $i+=strlen($m[0]);if($this->operation==='')$this->operation=strtoupper($m[0]);$parts[]='w:'.$m[0];
            } else {
                $op=null;foreach(['->>','<=>','!=','<=','>=','<>',':=','||','&&','<<','>>','->'] as $candidate)if(substr($sql,$i,strlen($candidate))===$candidate){$op=$candidate;break;}
                $op??=$c;$parts[]='o:'.$op;$i+=strlen($op);
            }
            $compile[]=$replacement??substr($sql,$start,$i-$start);
            $charOffset=$i; // WordPress lexer offsets are bytes.
        }
        $this->template=implode('',$compile);
        $this->key=hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR));
    }
}
