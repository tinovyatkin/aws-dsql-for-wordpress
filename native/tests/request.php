<?php
/** Loopback FastCGI benchmark endpoint; synthetic lab only, never deploy publicly. */
$start=hrtime(true);
require __DIR__.'/bootstrap.php';
try {
    $mode=$_GET['mode']??'rust';$case=$_GET['case']??'one';
    if(!in_array($mode,['rust','rust_fresh','php'],true)||!in_array($case,['one','mix'],true))throw new RuntimeException('Unknown benchmark mode');
    if($mode==='rust_fresh')dsql_native_reset_worker();
    $isNative=$mode!=='php';
    $driver=$isNative?native_client():php_client();
    $queries=fixture_queries();if($case==='one')$queries=[$queries[0]];
    $rows=[];$timing=[];$calls=[];
    foreach($queries as $query){
        $call=hrtime(true);$result=$driver->query($query);$callMs=(hrtime(true)-$call)/1e6;
        if($isNative){$resultRows=$result['rows'];$timing[]=$result['timing'];$calls[]=['php_call_ms'=>$callMs,'result_conversion_and_boundary_ms'=>max(0,$callMs-($result['timing']['native_total_ms']??$callMs))];}
        else {$resultRows=$result->fetchAll();}
        foreach($resultRows as &$row)ksort($row);unset($row);$rows[]=$resultRows;
    }
    $driver->close();
    $response=['mode'=>$mode,'case'=>$case,'server_ms'=>(hrtime(true)-$start)/1e6,'rows'=>array_sum(array_map('count',$rows)),'checksum'=>hash('sha256',json_encode($rows)),'peak_php_mib'=>memory_get_peak_usage(true)/1048576,'php_parser_loaded'=>class_exists('WPDSQL\\MySQL\\WordPress\\WP_Parser',false),'timing'=>$timing,'calls'=>$calls];
    header('Content-Type: application/json');echo json_encode($response,JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>$e->getMessage()]);}
