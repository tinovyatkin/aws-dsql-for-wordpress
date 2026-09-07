<?php
namespace WPDSQLMigration;
require_once dirname(__DIR__).'/pg4wp/dsql/class-dsql-value-codec.php';
/** Explicit, reviewable exceptions. No tables or rows are deleted from the backup. */
final class Policy {
    public static function load(?string $file): array {
        if(!$file) return ['archive_tables'=>[],'omit_fulltext_indexes'=>[],'value_codec'=>false,'active_schema'=>'public'];
        $p=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if(($p['format']??'')!=='wordpress-dsql-policy-v1') throw new \RuntimeException('Unsupported migration policy');
        foreach(array_keys($p) as $key) if(!in_array($key,['format','archive_tables','omit_fulltext_indexes','value_codec','active_schema'],true)) throw new \RuntimeException('Unknown policy key');
        if(!is_bool($p['value_codec']??null)||!is_array($p['archive_tables']??null)||!is_array($p['omit_fulltext_indexes']??null)) throw new \RuntimeException('Invalid migration policy fields');
        $p['active_schema']=$p['active_schema']??'public';
        if(!in_array($p['active_schema'],['public','wp_live'],true)) throw new \RuntimeException('Unsupported application schema');
        return $p;
    }
    public static function table(array $t,array $p): array {
        $t['target_schema']=in_array($t['name'],$p['archive_tables'],true)?'wp_archive':($p['active_schema']??'public');
        $t['archived']=$t['target_schema']==='wp_archive';
        $t['value_codec']=$p['value_codec'];
        $t['omitted_indexes']=$p['omit_fulltext_indexes'][$t['name']]??[];
        foreach($t['omitted_indexes'] as $name) {
            $matches=array_filter($t['indexes'],static fn($i)=>$i['Key_name']===$name);
            if(!$matches) throw new \RuntimeException('Policy index not found: '.$t['name'].'.'.$name);
            foreach($matches as $i) if($i['Index_type']!=='FULLTEXT'||!(int)$i['Non_unique']) throw new \RuntimeException('Only explicitly reviewed non-unique FULLTEXT indexes may be omitted');
        }
        return $t;
    }
    public static function apply(array $m,array $p): array {
        $names=array_column($m['tables'],'name');
        foreach(array_merge($p['archive_tables'],array_keys($p['omit_fulltext_indexes'])) as $name) if(!in_array($name,$names,true)) throw new \RuntimeException('Policy table not found: '.$name);
        $m['tables']=array_map(static fn($t)=>self::table($t,$p),$m['tables']);
        $m['policy']=$p;$m['policy_sha256']=hash('sha256',Backup::json($p));return $m;
    }
}
