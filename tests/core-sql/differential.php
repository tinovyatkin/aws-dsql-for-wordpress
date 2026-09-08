<?php
/** Execute source-derived core query cases on existing isolated WordPress fixtures. */
$root=dirname(__DIR__,2);$backend=$argv[1]??'';
if(!in_array($backend,['mysql','dsql'],true))throw new RuntimeException('mysql or dsql required');
if($backend==='dsql'){
    $c=json_decode(file_get_contents($root.'/.local/cluster.json'),true);
    $s=json_decode(file_get_contents($root.'/.local/settings.json'),true);
    if(($c['tags']['Purpose']??'')!=='synthetic-wordpress-compatibility'||$s['endpoint']!==$c['identifier'].'.dsql.'.$s['region'].'.on.aws')throw new RuntimeException('Synthetic DSQL fixture required');
}
require $root.($backend==='mysql'?'/.local/mysql-wordpress/wp-load.php':'/.local/wordpress/wp-load.php');
if(wp_get_environment_type()!=='local'||($backend==='mysql'&&!str_starts_with(DB_HOST,'127.0.0.1:'))||($backend==='dsql'&&DB_HOST!==$s['endpoint']))throw new RuntimeException('Isolated fixture required');
$table=$wpdb->prefix.'core_gap_dates';$options=$wpdb->prefix.'core_gap_options';$report=[];
function core_gap_query(string $sql):int|bool{global $wpdb;$n=$wpdb->query($sql);if($n===false)throw new RuntimeException($wpdb->last_error);return $n;}
function core_gap_rows(string $sql):array{global $wpdb;$rows=$wpdb->get_results($sql,ARRAY_A);if($wpdb->last_error)throw new RuntimeException($wpdb->last_error);return $rows;}
try {
    core_gap_query("DROP TABLE IF EXISTS $table");core_gap_query("DROP TABLE IF EXISTS $options");
    core_gap_query("CREATE TABLE $table (id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,post_id bigint,stamp datetime NULL,meta_key varchar(80) NOT NULL,meta_value longtext NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    core_gap_query("CREATE TABLE $options (option_id bigint NOT NULL AUTO_INCREMENT PRIMARY KEY,option_name varchar(80) NOT NULL) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $dates=[];
    for($year=2010;$year<=2030;$year++){
        $first=new DateTimeImmutable("$year-01-01");
        for($offset=-8;$offset<=8;$offset++)$dates[]=$first->modify("$offset days")->format('Y-m-d').' 10:30:45';
        $dates[]="$year-02-28 10:30:45";$dates[]="$year-03-01 10:30:45";
    }
    $dates[]='2024-02-29 10:30:45';$dates[]=null;$dates[]='0000-00-00 00:00:00';
    $values=[];foreach($dates as $i=>$date)$values[]=$wpdb->prepare('(%d,%s,%s,%s)',$i+1,$date??'2024-01-01 10:30:45',$i%2?'Hotel':'hotel',$i%2?'Hotel':'hotel');
    foreach(array_chunk($values,100) as $chunk)core_gap_query("INSERT INTO $table (id,stamp,meta_key,meta_value) VALUES ".implode(',',$chunk));
    core_gap_query("UPDATE $table SET post_id=id");
    core_gap_query("UPDATE $table SET stamp=NULL WHERE id=".(count($dates)-1));
    core_gap_query("UPDATE $table SET meta_value='Café' WHERE id=1");
    core_gap_query("UPDATE $table SET meta_value='Hotel ' WHERE id=2");
    core_gap_query("UPDATE $table SET meta_value=NULL WHERE id=3");
    for($mode=0;$mode<=7;$mode++)$report["week_$mode"]=core_gap_rows("SELECT id,WEEK(stamp,$mode) AS w FROM $table ORDER BY id");
    $report['days']=core_gap_rows("SELECT id,DAYOFYEAR(stamp) AS y,DAYOFWEEK(stamp) AS w,WEEKDAY(stamp) AS i FROM $table ORDER BY id");
    $report['formatted_time']=core_gap_rows("SELECT id FROM $table WHERE DATE_FORMAT(stamp,'%H.%i%s') = 10.3045 ORDER BY id");
    foreach(['=','!=','LIKE','IN','BETWEEN'] as $op){
        $rhs=match($op){'LIKE'=>"'Hot%'",'IN'=>"('Hotel','other')",'BETWEEN'=>"'Hotel' AND 'Hotel'",default=>"'Hotel'"};
        $report['binary_'.$op]=core_gap_rows("SELECT id FROM $table WHERE CAST(meta_value AS BINARY) $op $rhs ORDER BY id");
    }
    $report['binary_unicode']=core_gap_rows("SELECT id FROM $table WHERE CAST(meta_value AS BINARY) = 'Café' ORDER BY id");
    $metaQuery=new WP_Meta_Query([['value'=>'Hotel','type'=>'BINARY']]);
    $parts=$metaQuery->get_sql('post','p','id');
    $generated=str_replace($wpdb->postmeta,$table,'SELECT p.id FROM '.$table.' p '.$parts['join'].' WHERE 1=1 '.$parts['where'].' ORDER BY p.id');
    $report['wp_meta_binary']=core_gap_rows($generated);
    // MySQL 8 rejects binary-string REGEXP operands. The WordPress intent is a
    // case-sensitive regex; compare with MySQL's supported match_type='c' form.
    $report['binary_regex']=core_gap_rows($backend==='dsql'?"SELECT id FROM $table WHERE CAST(meta_key AS BINARY) REGEXP BINARY '^Hotel$' ORDER BY id":"SELECT id FROM $table WHERE REGEXP_LIKE(meta_key,'^Hotel$','c') ORDER BY id");
    foreach([['week'=>1],['dayofweek'=>1],['dayofweek_iso'=>7],['dayofyear'=>60],['hour'=>10,'minute'=>30,'second'=>45]] as $i=>$args){
        add_filter('date_query_valid_columns',static fn($columns)=>array_merge($columns,[$table.'.stamp']));
        $dateQuery=new WP_Date_Query([$args],$table.'.stamp');
        $report['wp_date_'.$i]=core_gap_rows("SELECT id FROM $table WHERE 1=1".$dateQuery->get_sql().' ORDER BY id');
    }
    core_gap_query("INSERT INTO $options (option_id,option_name) VALUES (1,'a'),(2,'a'),(3,'a'),(4,'b'),(5,'b'),(6,'c')");
    $report['self_join_deleted']=core_gap_query("DELETE o1 FROM $options AS o1 JOIN $options AS o2 USING (option_name) WHERE o2.option_id > o1.option_id");
    $report['self_join_remaining']=core_gap_rows("SELECT option_id,option_name FROM $options ORDER BY option_id");
    if($backend==='dsql'){
        $metadata=core_gap_rows("SELECT TABLE_NAME AS 'table',TABLE_ROWS AS 'rows',SUM(data_length+index_length) AS 'bytes' FROM information_schema.TABLES WHERE TABLE_SCHEMA='postgres' AND TABLE_NAME IN ('$table','$options') GROUP BY TABLE_NAME");
        $byName=array_column($metadata,null,'table');
        if($byName[$table]['rows']!=(string)count($dates)||$byName[$options]['rows']!=='3'||$byName[$table]['bytes']!==null)throw new RuntimeException('Incorrect Site Health statistics');
        if(core_gap_rows("SHOW VARIABLES LIKE 'max_connections'")!==[])throw new RuntimeException('Invented MySQL variable');
        $status=core_gap_rows("SHOW TABLE STATUS LIKE '$table'");
        if(count($status)!==1||$status[0]['Data_length']!==null||$status[0]['Rows']!=(string)count($dates))throw new RuntimeException('Incorrect table status');
        if(core_gap_rows("CHECK TABLE $table")[0]['Msg_text']!=='OK')throw new RuntimeException('Readability check failed');
        foreach(['REPAIR','ANALYZE','OPTIMIZE'] as $op)if(core_gap_rows("$op TABLE $table")[0]['Msg_type']!=='note')throw new RuntimeException('Maintenance falsely reported success');
        if(core_gap_rows("CHECK TABLE {$table}_missing")[0]['Msg_type']!=='error')throw new RuntimeException('Missing table reported healthy');
        $report['_dsql_metadata']='passed';
    }
    echo "PASS $backend core query execution\n";
} catch(Throwable $e){$report['failure']=$e->getMessage();echo "FAIL $backend: ".$e->getMessage()."\n";}
finally{core_gap_query("DROP TABLE IF EXISTS $table");core_gap_query("DROP TABLE IF EXISTS $options");}
file_put_contents($root.'/.local/core-sql-'.$backend.'-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
exit(isset($report['failure'])?1:0);
