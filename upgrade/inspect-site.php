<?php
// SHORTINIT reads the real connection without executing plugin upgrade hooks.
ini_set('zend.exception_ignore_args','1');
define('SHORTINIT',true);
require rtrim($argv[1],'/').'/wp-load.php';
echo json_encode(['endpoint'=>DB_HOST,'prefix'=>$table_prefix,'driver'=>get_class($wpdb),'multisite'=>is_multisite()],JSON_THROW_ON_ERROR);
