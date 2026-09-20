<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$dir=sys_get_temp_dir().'/wp-dsql-lazy-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
 $driver=new WPDSQL\Engine\NativeDriver(new WPDSQL\Engine\Config(host:'not-real.dsql.eu-central-1.on.aws',region:'eu-central-1',profile:'deliberately-nonexistent',automaticSchema:true,schemaUser:'wp_upgrader',schemaStateDirectory:$dir));
 if($driver->isConnected()||$driver->escape("a'b")!=="a\\'b"||class_exists(WPDSQL\MySQL\WordPress\WP_Parser::class,false))throw new RuntimeException('Automatic configuration opened a connection or loaded the parser');
 $driver->close();echo "PASS automatic schema mode remains lazy without credentials or a database\n";
}finally{unlink($dir.'/access.lock');rmdir($dir);}
