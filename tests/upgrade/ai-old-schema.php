<?php
global $wpdb;
if(wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic fixture only');
$wpdb->query('ALTER TABLE wp_wpai_request_logs DROP COLUMN request_preview, DROP COLUMN response_preview, DROP INDEX idx_provider, DROP INDEX idx_operation, DROP INDEX idx_timestamp_type_status, DROP INDEX idx_timestamp_provider');
update_option('wpai_request_logs_schema_version','0');
echo "Prepared the AI plugin's older schema.\n";
