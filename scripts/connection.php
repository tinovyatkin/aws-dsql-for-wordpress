<?php
/** Shared connection for synthetic fixture tools only. No credential files are copied. */
require_once dirname(__DIR__) . '/vendor/autoload.php';
function dsql_test_connection(): PDO {
    $root = dirname(__DIR__);
    $cluster = json_decode(file_get_contents($root . '/.local/cluster.json'), true, 512, JSON_THROW_ON_ERROR);
    $settings = json_decode(file_get_contents($root . '/.local/settings.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($cluster['tags']['Purpose'] ?? '') !== 'synthetic-wordpress-compatibility') { throw new RuntimeException('Synthetic cluster tag required'); }
    $sdk = new Aws\DSQL\DSQLClient(['version'=>'latest','region'=>$settings['region'],'profile'=>$settings['profile']]);
    return Aws\AuroraDsql\PdoPgsql\AuroraDsql::connect(new Aws\AuroraDsql\PdoPgsql\DsqlConfig(
        host: $settings['endpoint'],
        credentialsProvider: static fn() => $sdk->getCredentials(),
        occMaxRetries: 3,
    ));
}
