<?php
namespace WPDSQLUpgrade;
use WPDSQLMigration\Plan;
require_once dirname(__DIR__).'/vendor/autoload.php';

/** Apply supported logical schema operations; physical execution remains controlled. */
final class Schema {
    public function __construct(private string $sql,private string $schema="wp_live",private string $mode="") {}
    public function parse(): array {return (new \WPDSQL\Schema\Ddl($this->sql,$this->schema,$this->mode))->parse();}

    public static function apply(array $ddl,?array $before,array $options): ?array {
        if(!str_starts_with($ddl['table'],$options['table_prefix']))throw new \RuntimeException('Table outside the selected WordPress prefix');
        if($before && ($before['archived']??false))throw new \RuntimeException('Archived plugin tables cannot be upgraded');
        if($ddl['kind']==='drop'){if(!$options['allow_destructive'])throw new \RuntimeException('DROP requires an explicitly destructive upgrade session');return null;}
        if($ddl['kind']==='create') {
            if($before){if($ddl['if_exists'])return $before;throw new \RuntimeException('Table already exists');}
            $after=['name'=>$ddl['table'],'columns'=>$ddl['columns'],'indexes'=>$ddl['indexes'],'target_schema'=>'wp_live','archived'=>false,'value_codec'=>true,'omitted_indexes'=>$options['omit_fulltext_indexes'][$ddl['table']]??[],'mysql_ddl'=>'','table_options'=>$ddl['table_options']??[]];
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
                    if($op==='default'){$c['Default']=$change['value'];$c['HasDefault']=$change['has_default']??($change['value']!==null);$c['DefaultExpression']=$change['default_expression']??false;}
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
                elseif($op==='utf8mb4_upgrade') {
                    $charset=strtolower($after['table_options']['charset']??'');
                    if(!in_array($charset,['utf8','utf8mb3','utf8mb4'],true))throw new \RuntimeException('Only existing UTF-8 data may be widened');
                    $target=$change['collation'];$canonical=static fn($c)=>preg_replace('/^utf8(?:mb3|mb4)?_/','utf8_',strtolower((string)$c));
                    if(!preg_match('/^utf8mb4_[a-z0-9_]+$/D',$target))throw new \RuntimeException('Invalid UTF-8 target collation');
                    $source=$after['table_options']['collation']??null;
                    if($source===null||$canonical($source)!==$canonical($target))throw new \RuntimeException('Changing collation semantics is unsupported');
                    foreach($after['columns'] as &$c){
                        if(!preg_match('/^(?:var)?char|^(?:tiny|medium|long)?text/i',$c['Type']))continue;
                        $collation=$c['Collation']??$source;
                        if($canonical($collation)!==$canonical($target))throw new \RuntimeException('Column collation semantics would change');
                        $c['Collation']=$target;
                    }unset($c);
                    $after['table_options']['charset']='utf8mb4';$after['table_options']['collation']=$target;
                }
                elseif($op==='rename') {$after['name']=$change['name'];if(!str_starts_with($after['name'],$options['table_prefix'])||str_starts_with($after['name'],'__'))throw new \RuntimeException('Invalid table rename destination');}
            }
        }
        if(!$after['columns']||count(array_unique(array_map('strtolower',array_column($after['columns'],'Field'))))!==count($after['columns']))throw new \RuntimeException('Invalid resulting column set');
        if(count(array_filter($after['columns'],static fn($c)=>str_contains($c['Extra'],'auto_increment')))>1)throw new \RuntimeException('MySQL permits only one AUTO_INCREMENT column');
        $sequences=[];foreach($after['indexes'] as &$index){$index['Table']=$after['name'];$index['Seq_in_index']=$sequences[$index['Key_name']]=($sequences[$index['Key_name']]??0)+1;if(!in_array($index['Column_name'],array_column($after['columns'],'Field'),true))throw new \RuntimeException('Index refers to unknown column');if(in_array($index['Key_name'],$after['omitted_indexes']??[],true)&&($index['Index_type']!=='FULLTEXT'||!(int)$index['Non_unique']))throw new \RuntimeException('Only optional nonunique FULLTEXT indexes may be omitted');}unset($index);
        foreach($after['columns'] as $c)Plan::type($c);
        $after=\WPDSQL\Schema\Model::normalize($after);Plan::indexes($after);$after['mysql_ddl']=self::mysql($after);$after['mapping']=$mapping;return $after;
    }
    public static function mysql(array $table): string {return \WPDSQL\Schema\Model::mysql($table);}
}
