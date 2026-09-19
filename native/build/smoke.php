<?php
if (!class_exists('DsqlNativeEngine', false) || DsqlNativeEngine::apiVersion() !== 1) exit(1);
// No credentials or database connection are needed for ABI and logging validation.
$db=new DsqlNativeEngine('test.dsql.eu-central-1.on.aws','eu-central-1','default','admin','public','wp_','abi-smoke');
$event=$db->logError("SELECT 'redaction-probe'",'cli','unclassified','reported',0);
if($event['event']!=='wordpress_dsql_error'||strlen($event['fingerprint'])!==64||$db->isConnected())exit(1);
$db->close();
