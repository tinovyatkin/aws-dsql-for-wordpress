<?php
global $wpdb;
if(wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic fixture only');
$wpdb->query('ALTER TABLE wp_posts DROP COLUMN menu_order');
global $wp_db_version;
update_option('db_version',(string)($wp_db_version-1));
echo "Prepared an older core schema/version marker.\n";
