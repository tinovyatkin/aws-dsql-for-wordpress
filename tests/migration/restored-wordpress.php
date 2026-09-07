<?php
require dirname(__DIR__,2).'/.local/restored-wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
$checks=[];
function migration_check(bool $ok,string $name):void {global $checks;$checks[]=['check'=>$name,'passed'=>$ok];if(!$ok)throw new RuntimeException($name);echo "PASS $name\n";}
try {
    migration_check(is_blog_installed(),'Restored WordPress is installed');
    $user=wp_authenticate('migrationtest',trim(file_get_contents(dirname(__DIR__,2).'/.local/migration/source-admin-password')));
    migration_check($user instanceof WP_User,'Restored password hash authenticates unchanged');
    $id=(int)get_option('migration_post_id');
    migration_check($id===10001 && get_post($id)->post_status==='publish','Original post ID and status preserved');
    $payload=get_post_meta($id,'_migration_payload',true);
    migration_check(is_array($payload)&&$payload['number']===42&&str_contains($payload['text'],'Русский'),'Serialized data and Unicode preserved');
    migration_check(get_option('migration_literal_year_one')==='0001-01-01 00:00:00','Year-1 literal text preserved');
    migration_check(get_option('migration_literal_zero')==='0000-00-00 00:00:00','Zero-date literal text preserved');
    update_option('migration_literal_zero','changed',false);update_option('migration_literal_zero','0000-00-00 00:00:00',false);
    wp_cache_delete('migration_literal_zero','options');
    migration_check(get_option('migration_literal_zero')==='0000-00-00 00:00:00','Writing zero-date literal text remains lossless');
    $changes=dbDelta(wp_get_db_schema(),false);
    migration_check(count($changes)===0,'dbDelta sees no false schema changes');
    $new=wp_insert_post(['post_title'=>'After restore','post_status'=>'draft'],true);
    migration_check(is_int($new)&&$new>10001,'Identity allocation continues above imported IDs');
    $term=wp_insert_term('After restore category','category');
    migration_check(!is_wp_error($term)&&$term['term_id']>1,'Term identity reseeded');
    migration_check(count($wpdb->dsql_errors)===0,'No database errors in restored application');
} catch(Throwable $e) {$checks[]=['failure'=>$e->getMessage()];}
file_put_contents(dirname(__DIR__,2).'/.local/migration/wordpress-report.json',json_encode(['checks'=>$checks,'errors'=>$wpdb->dsql_errors,'schema_changes'=>$changes??[]],JSON_PRETTY_PRINT));
exit(isset($checks[array_key_last($checks)]['failure'])?1:0);
