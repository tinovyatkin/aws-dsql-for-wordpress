<?php
global $wpdb;
function upgrade_check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
upgrade_check(wp_get_environment_type()==='local'&&$wpdb->get_var('SELECT current_user')==='wp_upgrader','Synthetic upgrade identity');
$wpdb->query("CREATE TABLE wp_upgrade_probe (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, label varchar(80) NOT NULL DEFAULT '', payload longtext NULL, PRIMARY KEY (id), KEY label_idx (label(30))) DEFAULT CHARSET=utf8mb4");
for($i=0;$i<105;$i++)$wpdb->insert('wp_upgrade_probe',['label'=>'item-'.$i,'payload'=>"binary\0🌍-".$i]);
$wpdb->get_var("SELECT setval(pg_get_serial_sequence('wp_live.wp_upgrade_probe','id'),9000,true)");
$before=$wpdb->get_results('SELECT * FROM wp_upgrade_probe ORDER BY id',ARRAY_A);
$wpdb->query("ALTER TABLE wp_upgrade_probe ADD COLUMN added varchar(30) NOT NULL DEFAULT 'hello, world' AFTER label, CHANGE COLUMN label title varchar(160) NOT NULL DEFAULT '', ADD INDEX title_idx (title(32))");
$rows=$wpdb->get_results('SELECT * FROM wp_upgrade_probe ORDER BY id',ARRAY_A);
upgrade_check(count($rows)===105,'Populated rebuild preserves row count across batches');
foreach($rows as $i=>$row)upgrade_check($row['id']===$before[$i]['id']&&$row['payload']===$before[$i]['payload']&&$row['title']===$before[$i]['label']&&$row['added']==='hello, world','Row data and new default preserved '.$i);
$columns=$wpdb->get_col('DESCRIBE wp_upgrade_probe',0);upgrade_check($columns===['id','title','added','payload'],'Column rename/order and metadata agree');
$wpdb->insert('wp_upgrade_probe',['title'=>'after-upgrade']);upgrade_check($wpdb->insert_id>9000,'Identity preserves consumed high-water mark');
$wpdb->query('ALTER TABLE wp_upgrade_probe DROP INDEX label_idx, DROP INDEX title_idx');
$wpdb->query("ALTER TABLE wp_upgrade_probe ALTER COLUMN added SET DEFAULT 'new default'");
$wpdb->query('ALTER TABLE wp_upgrade_probe MODIFY COLUMN title varchar(255) NOT NULL DEFAULT \'\'');
$wpdb->query('RENAME TABLE wp_upgrade_probe TO wp_upgrade_renamed');
upgrade_check($wpdb->get_var("SHOW TABLES LIKE 'wp_upgrade_renamed'")==='wp_upgrade_renamed','Table rename published');
upgrade_check($wpdb->get_var("SHOW TABLES LIKE 'wp_upgrade_probe'")===null,'Old table name removed');
$wpdb->query('ALTER TABLE wp_upgrade_renamed DROP COLUMN added');
upgrade_check(!in_array('added',$wpdb->get_col('DESCRIBE wp_upgrade_renamed',0),true),'Drop column updates schema catalog');
$wpdb->query('DROP TABLE wp_upgrade_renamed');
upgrade_check($wpdb->get_var("SHOW TABLES LIKE 'wp_upgrade_renamed'")===null,'Drop table removes active name and catalog');
$wpdb->query('DROP TABLE IF EXISTS wp_upgrade_renamed');
upgrade_check($wpdb->dsql_error_count===0,'Schema workflow has no SQL errors');
