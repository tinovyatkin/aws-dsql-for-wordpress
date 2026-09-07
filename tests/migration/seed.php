<?php
define('WP_INSTALLING',true);
require dirname(__DIR__,2).'/.local/mysql-wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) wp_install('Migration rehearsal','migrationtest','migration@example.invalid',false,'',trim(file_get_contents(dirname(__DIR__,2).'/.local/migration/source-admin-password')));
$text="A backup must preserve ID, KEY, O'Reilly, \\ paths, 🌍 and Русский текст.\n";
$id=wp_insert_post(['import_id'=>10001,'post_title'=>'Backup restore rehearsal','post_content'=>wp_slash($text),'post_status'=>'publish'],true);
if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
update_post_meta($id,'_migration_payload',wp_slash(['text'=>$text,'number'=>42,'url'=>'https://example.invalid/?a=1&b=2']));
update_option('migration_post_id',$id,false);
update_option('migration_literal_year_one','0001-01-01 00:00:00',false);
update_option('migration_literal_zero','0000-00-00 00:00:00',false);
$wpdb->query("CREATE TABLE wp_migration_payload (id bigint unsigned NOT NULL AUTO_INCREMENT, value longblob, amount decimal(20,4) NOT NULL DEFAULT 0.0000, state enum('new','done') NOT NULL DEFAULT 'new', PRIMARY KEY(id)) ENGINE=InnoDB");
$wpdb->query("INSERT INTO wp_migration_payload (id,value,amount,state) VALUES (40001,0x000102FF27805C,'1234567890123456.1234','done')");
$wpdb->query("CREATE TABLE wp_migration_duplicates (value varchar(40) NOT NULL) ENGINE=InnoDB");
$wpdb->query("INSERT INTO wp_migration_duplicates(value) VALUES ('same'),('same'),('different')");
if ($wpdb->last_error) throw new RuntimeException($wpdb->last_error);
echo json_encode(['synthetic_source_ready'=>true,'post_id'=>$id]),"\n";
