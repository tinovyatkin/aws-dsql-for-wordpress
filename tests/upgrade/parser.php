<?php
require dirname(__DIR__,2).'/upgrade/Engine.php';
use WPDSQLUpgrade\Schema;
$options=['table_prefix'=>'wp_','allow_destructive'=>true,'omit_fulltext_indexes'=>[]];
$base=Schema::apply((new Schema("CREATE TABLE wp_check (id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, a varchar(50) NOT NULL DEFAULT 'a,b', payload longtext NULL, UNIQUE KEY a_key(a)) DEFAULT CHARSET=utf8mb4"))->parse(),null,$options);
if(count($base['columns'])!==3||count($base['indexes'])!==2)throw new RuntimeException('CREATE parse failed');
$next=Schema::apply((new Schema("ALTER TABLE wp_check ADD COLUMN extra varchar(10) DEFAULT 'a\\'b' AFTER a, CHANGE COLUMN a title varchar(80) NOT NULL DEFAULT '', DROP INDEX a_key, ADD KEY title_key (title(30))"))->parse(),$base,$options);
if(array_column($next['columns'],'Field')!==['id','title','extra','payload']||$next['columns'][2]['Default']!=="a'b"||$next['mapping']['title']!=='a')throw new RuntimeException('ALTER plan failed');
$again=Schema::apply((new Schema(Schema::mysql($next)))->parse(),null,$options);
if(array_column($again['columns'],'Field')!==array_column($next['columns'],'Field'))throw new RuntimeException('DDL round-trip failed');
$rejected=[
 "ALTER TABLE wp_check ADD KEY x ((lower(a)))",
 "ALTER TABLE wp_check ADD COLUMN secret int(garbage)",
 "ALTER TABLE wp_check ADD COLUMN x int; DROP TABLE wp_check",
 "ALTER TABLE wp_archive.wp_check DROP COLUMN a",
 "ALTER TABLE wp_check CONVERT TO CHARACTER SET latin1",
 "CREATE TABLE wp_check2 (id bigint, CONSTRAINT fk FOREIGN KEY (id) REFERENCES wp_check(id))",
 "ALTER TABLE wp_check ADD COLUMN x varchar(20) DEFAULT 'unclosed",
];
foreach($rejected as $sql){try{(new Schema($sql))->parse();throw new LogicException('Unsupported SQL accepted');}catch(RuntimeException $expected){}}
try{Schema::apply((new Schema('ALTER TABLE wp_check ADD UNIQUE KEY prefix_key(a(10))'))->parse(),$base,$options);throw new LogicException('Unique prefix accepted');}catch(RuntimeException $expected){}
try{Schema::apply((new Schema('CREATE TABLE wp_bad (id bigint, UNIQUE KEY critical (id))'))->parse(),null,array_replace($options,['omit_fulltext_indexes'=>['wp_bad'=>['critical']]]));throw new LogicException('Unique index omission accepted');}catch(RuntimeException $expected){}
echo "PASS parser: complete statements, quoting, positioning, metadata and rejection boundaries\n";
