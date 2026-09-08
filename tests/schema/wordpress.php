<?php
/** Read-only logical-column contracts against the synthetic restored WordPress fixture. */
require dirname(__DIR__,2).'/.local/full-target/wp-load.php';
if(wp_get_environment_type()!=='local')throw new RuntimeException('Synthetic WordPress fixture required');
$checks=0;
function metadata_check(bool $ok,string $name):void{global $checks,$wpdb;if(!$ok)throw new RuntimeException($name.': '.$wpdb->last_error);$checks++;}
metadata_check($wpdb->get_col_length($wpdb->posts,'post_content')===['type'=>'byte','length'=>4294967295],'LONGTEXT retains MySQL byte length');
metadata_check($wpdb->get_col_length($wpdb->posts,'post_title')===['type'=>'byte','length'=>65535],'TEXT retains MySQL byte length');
metadata_check($wpdb->get_col_length($wpdb->options,'option_name')===['type'=>'char','length'=>191],'VARCHAR retains character length');
metadata_check($wpdb->get_col_charset($wpdb->posts,'post_content')==='utf8mb4','Text column charset');
metadata_check($wpdb->get_col_charset($wpdb->posts,'ID')===false,'Numeric column has no charset');
metadata_check($wpdb->get_col_length($wpdb->posts,'ID')===false,'Numeric column has no string limit');
$rows=$wpdb->get_results("SHOW FULL COLUMNS FROM {$wpdb->posts} WHERE Field='post_content'",ARRAY_A);
metadata_check(count($rows)===1&&$rows[0]['Type']==='longtext','Filtered logical columns');
$ddl=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->posts}",ARRAY_A);
metadata_check(str_contains($ddl['Create Table'],'longtext'),'MySQL DDL is reconstructed from restored metadata');
$count=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s",$wpdb->posts));
metadata_check((int)$count===count($wpdb->get_results("DESCRIBE {$wpdb->posts}")),'INFORMATION_SCHEMA agrees with DESCRIBE');
$wpdb->query("SELECT ID AS article_id,post_title FROM {$wpdb->posts} LIMIT 1");
metadata_check($wpdb->get_col_info('type')===[8,252],'Result metadata uses logical MySQL types');
metadata_check($wpdb->get_col_info('orgname')===['ID','post_title'],'Aliased result keeps original column names');
metadata_check(($wpdb->get_col_info('flags')[0]&2)===2,'Result key flags come from the logical schema');
echo "PASS $checks restored WordPress logical-column contracts\n";
