<?php
require __DIR__.'/bootstrap.php';require $root.'/pg4wp/dsql/class-dsql-diagnostics.php';
$log=$root.'/.local/native-error-event.log';file_put_contents($log,'');chmod($log,0600);ini_set('error_log',$log);
$n=native_client();$query="SELECT 'private-native-probe' FROM `".fixture()['prefix']."missing` WHERE title='secret-token'";
try{$n->query($query);throw new RuntimeException('Expected missing-table failure');}catch(Exception $e){if($e instanceof RuntimeException)throw $e;}
$event=$n->logError($query,'cli','wp-content/plugins/native-probe/plugin.php:12','metadata',12.345);
$text=file_get_contents($log);
if(substr_count($text,'wordpress_dsql_error')!==1)throw new RuntimeException('Native log emission count');
foreach(['private-native-probe','secret-token',$query] as $secret)if(str_contains($text,$secret))throw new RuntimeException('Diagnostic exposed a literal');
if($event['fingerprint']!==hash('sha256',DSQL_Diagnostics::normalize($query)))throw new RuntimeException('Fingerprint compatibility');
if($event['kind']!=='database'||$event['sqlstate']!=='42P01'||$event['context']!=='cli'||$event['source']!=='wp-content/plugins/native-probe/plugin.php:12')throw new RuntimeException('Native diagnostic fields');
foreach(["SELECT 'unterminated private","SELECT \$tag\$private payload\$tag\$","SELECT 123.50 /* secret */","SELECT `table` WHERE x='a\\'b'","SELECT ".chr(255)] as $sql){$e=$n->logError($sql,'cli','unclassified','reported',0);if($e['fingerprint']!==hash('sha256',DSQL_Diagnostics::normalize($sql)))throw new RuntimeException('Malformed-query fingerprint parity');}
$n->close();echo "PASS native log emission, redaction, grouping and SQLSTATE contracts\n";
