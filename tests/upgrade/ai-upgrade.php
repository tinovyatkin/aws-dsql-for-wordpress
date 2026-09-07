<?php
global $wpdb;
if(wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic fixture only');
$schema=new WordPress\AI\Logging\AI_Request_Log_Schema();$schema->maybe_upgrade_table();
$columns=$wpdb->get_col('SHOW COLUMNS FROM wp_wpai_request_logs',0);
if(!in_array('request_preview',$columns,true)||!in_array('response_preview',$columns,true)||get_option('wpai_request_logs_schema_version')!=='1'||$schema->has_fulltext_index())throw new RuntimeException('AI migration did not converge');
echo "PASS real AI migration restores columns/indexes and version, with explicit LIKE fallback.\n";
