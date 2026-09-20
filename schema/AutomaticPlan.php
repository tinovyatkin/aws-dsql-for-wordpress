<?php
namespace WPDSQL\Schema;

use WPDSQLMigration\Backup;
use WPDSQLMigration\Plan;
use WPDSQLUpgrade\Schema;

/** Deterministic fast paths for ordinary WordPress DDL; no replacement-table copies. */
final class AutomaticPlan {
    public static function qi(string $name): string { return Backup::qi($name); }
    public static function column(array $table,string $name): array {
        foreach($table['columns'] as $column)if($column['Field']===$name)return $column;
        throw new \RuntimeException('Schema column is missing');
    }
    private static function signature(array $column): array {
        $column['Type']=preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/i','$1',$column['Type']);
        $out=[];foreach(['Field','Type','Null','Default','Extra','Collation','HasDefault','DefaultExpression'] as $key)$out[$key]=$column[$key]??null;return $out;
    }
    private static function widening(array $old,array $new): bool {
        if(Plan::type($old)!==Plan::type($new))return false;
        $a=strtolower($old['Type']);$b=strtolower($new['Type']);
        if($a===$b)return true;
        if(str_contains($a,'unsigned')!==str_contains($b,'unsigned'))return false;
        $a=preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/','$1',$a);
        $b=preg_replace('/^(tinyint|smallint|mediumint|int|integer|bigint)\([0-9]+\)/','$1',$b);
        if($a===$b)return true;
        $sizes=['tinyint'=>1,'smallint'=>2,'mediumint'=>3,'int'=>4,'integer'=>4,'bigint'=>8,'tinytext'=>255,'text'=>65535,'mediumtext'=>16777215,'longtext'=>4294967295];
        $a=str_replace(' unsigned','',$a);$b=str_replace(' unsigned','',$b);
        return isset($sizes[$a],$sizes[$b]) && (($sizes[$a]<=8)===($sizes[$b]<=8)) && $sizes[$b]>=$sizes[$a];
    }
    public static function build(string $sql,?array $before,string $prefix,string $schema,bool $codec,string $mode=''): array {
        $ddl=(new Schema($sql,$schema,$mode))->parse();
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D',$ddl['table'])||!str_starts_with($ddl['table'],$prefix)||str_starts_with($ddl['table'],'__'))throw new \RuntimeException('Automatic DDL is limited to application tables');
        if($before && ($before['archived']??false))throw new \RuntimeException('Archived tables require a controlled migration');
        if($ddl['kind']==='drop')throw new \RuntimeException('Dropping data requires a controlled migration');
        $options=['table_prefix'=>$prefix,'allow_destructive'=>false,'omit_fulltext_indexes'=>[]];
        if($before)$before=Model::normalize(array_replace($before,['indexes'=>$before['source_indexes']??$before['indexes']]));
        $result=['operation'=>strtoupper($ddl['kind']),'table'=>$ddl['table'],'before'=>$before,'after'=>$before,'steps'=>[]];
        if($ddl['kind']==='create') {
            if($before && $ddl['if_exists'])return $result;
            $after=Schema::apply($ddl,null,$options);$after['target_schema']=$schema;$after['value_codec']=$codec;
            if($before) {
                if(array_map([self::class,'signature'],$before['columns'])===array_map([self::class,'signature'],$after['columns']) && Plan::indexes($before)===Plan::indexes($after))return $result;
                throw new \RuntimeException('Existing table differs from the requested CREATE definition');
            }
            $result['after']=$after;$result['steps'][]=['kind'=>'create','table'=>$after];return $result;
        }
        if(!$before)throw new \RuntimeException('Automatic ALTER requires a catalogued table');
        $current=$before;
        foreach($ddl['changes'] as $change) {
            $op=$change['op'];
            if(!in_array($op,['add','change','rename_column','default','add_index','drop_index','rename','utf8mb4_upgrade'],true))throw new \RuntimeException('This schema change requires a controlled migration');
            if($op==='rename' && count($ddl['changes'])!==1)throw new \RuntimeException('Mixed table rename requires a controlled migration');
            // Recognize harmless repeated ADDs after a plugin was interrupted before its version marker.
            if($op==='add' && in_array($change['column']['Field'],array_column($current['columns'],'Field'),true)) {
                $candidate=Model::normalize(['name'=>$current['name'],'table_options'=>$current['table_options'],'columns'=>[$change['column']],'indexes'=>[]]);
                if(self::signature(self::column($current,$change['column']['Field']))===self::signature($candidate['columns'][0]))continue;
                throw new \RuntimeException('Existing column differs from the requested ADD definition');
            }
            if($op==='add_index') {
                $key=$change['indexes'][0]['Key_name'];$old=Plan::indexes($current)[$key]??null;
                if($old) {
                    $signature=static fn($rows)=>array_map(static fn($r)=>array_intersect_key($r,array_flip(['Column_name','Seq_in_index','Non_unique','Sub_part','Index_type'])),$rows);
                    if($signature($old)==$signature($change['indexes']))continue;
                    throw new \RuntimeException('Existing index differs from the requested definition');
                }
            }
            $after=Schema::apply(['kind'=>'alter','table'=>$current['name'],'changes'=>[$change]],$current,$options);
            $table=$current['name'];$steps=[];
            if($op==='add') {
                $c=self::column($after,$change['column']['Field']);
                if(array_key_last($after['columns'])!==array_search($c['Field'],array_column($after['columns'],'Field'),true))throw new \RuntimeException('Column repositioning requires a controlled migration');
                if(str_contains($c['Extra'],'auto_increment'))throw new \RuntimeException('Adding an identity requires a controlled migration');
                if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D',$c['Field']))throw new \RuntimeException('Unsupported automatic column identifier');
                if($c['Null']==='NO') {
                    $c['dsql_not_null_constraint']='__wpd_nn_'.substr(hash('sha256',$table.'|'.$c['Field']),0,24);
                    foreach($after['columns'] as &$column)if($column['Field']===$c['Field'])$column=$c;unset($column);
                }
                $steps[]=['kind'=>'add_column','table'=>$table,'column'=>$c];
            } elseif(in_array($op,['change','rename_column','default'],true)) {
                $oldName=$change['old']??$change['name'];$old=self::column($current,$oldName);
                $newName=$op==='rename_column'?$change['name']:($change['column']['Field']??$oldName);$new=self::column($after,$newName);
                if(!self::widening($old,$new)||$old['Extra']!==$new['Extra']||$old['Collation']!==$new['Collation'])throw new \RuntimeException('Type conversion, narrowing or collation change requires a controlled migration');
                if(array_search($oldName,array_column($current['columns'],'Field'),true)!==array_search($newName,array_column($after['columns'],'Field'),true))throw new \RuntimeException('Column repositioning requires a controlled migration');
                if($old['Null']==='YES' && $new['Null']==='NO')throw new \RuntimeException('Tightening an existing nullable column requires a controlled migration');
                if($oldName!==$newName)$steps[]=['kind'=>'rename_column','table'=>$table,'old'=>$oldName,'new'=>$newName];
                if($old['Default']!==$new['Default']||$old['HasDefault']!==$new['HasDefault']||$old['DefaultExpression']!==$new['DefaultExpression'])$steps[]=['kind'=>'default','table'=>$table,'column'=>$new,'before'=>$old];
                if($old['Null']!==$new['Null']) {
                    $steps[]=['kind'=>'nullable','table'=>$table,'column'=>$new,'before'=>$old];
                    foreach($after['columns'] as &$column)if($column['Field']===$newName)unset($column['dsql_not_null_constraint']);unset($column);
                }
            } elseif($op==='add_index') {
                $key=$change['indexes'][0]['Key_name'];if($key==='PRIMARY')throw new \RuntimeException('Primary-key changes require a controlled migration');
                $steps[]=['kind'=>'add_index','table'=>$table,'key'=>$key,'definition'=>$after];
            } elseif($op==='drop_index') {
                $key=$change['name'];$group=Plan::indexes($current)[$key]??null;
                if(!$group||$key==='PRIMARY'||!$group[0]['Non_unique'])throw new \RuntimeException('Removing a uniqueness constraint requires a controlled migration');
                $steps[]=['kind'=>'drop_index','table'=>$table,'key'=>$key,'definition'=>$current];
            } elseif($op==='rename')$steps[]=['kind'=>'rename','old'=>$table,'new'=>$after['name']];
            // utf8mb4/display-width/TEXT widening can be catalog-only after Schema::apply validates semantics.
            $result['steps']=array_merge($result['steps'],$steps);$current=Model::normalize($after);
        }
        unset($current['mapping']);$current['mysql_ddl']=Model::mysql($current);$result['after']=$current;
        return $result;
    }
}
