<?php
namespace WPDSQL\Engine;

use Aws\AuroraDsql\PdoPgsql\AuroraDsql;
use Aws\AuroraDsql\PdoPgsql\DsqlConfig;

/** Native AWS authentication and PDO transport, independent of WordPress. */
final class AwsConnection {
    public static function connect(Config $config): \PDO {
        $provider = null;
        if ($config->credentialsFile !== null) {
            $provider = \Aws\Credentials\CredentialProvider::ini($config->profile ?: 'default', $config->credentialsFile);
        } elseif ($config->profile) {
            // Connector 0.1.1's default-provider path ignores the shared profile.
            $client = new \Aws\DSQL\DSQLClient([
                'version' => 'latest', 'region' => $config->region ?: 'us-east-1',
                'profile' => $config->profile,
            ]);
            $provider = static fn() => $client->getCredentials();
        }
        return AuroraDsql::connect(new DsqlConfig(
            host: $config->host, user: $config->user, database: $config->database,
            region: $config->region, credentialsProvider: $provider, occMaxRetries: 3,
        ), [
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_STRINGIFY_FETCHES => true,
            // Unnamed native bindings avoid libpq's SQL DEALLOCATE cleanup.
            (defined('Pdo\\Pgsql::ATTR_DISABLE_PREPARES') ? constant('Pdo\\Pgsql::ATTR_DISABLE_PREPARES') : constant('PDO::PGSQL_ATTR_DISABLE_PREPARES')) => true,
        ]);
    }
}
