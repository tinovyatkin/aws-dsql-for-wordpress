# Engine compatibility contracts

`tests/engineTest.php` uses a scripted PDO transport to exercise binding/cache,
result cursor and metadata, SQL modes, connection renewal, transaction ownership,
OCC retry boundaries, error wrapping and closed-driver behavior without WordPress
or AWS. It is part of the normal PHPUnit suite.

`standalone.php` uses only Composer and the synthetic DSQL cluster created by
`bootstrap-local.sh`. It verifies DDL, metadata, values, results and transactions
without loading WordPress or defining WordPress configuration constants.

`wordpress-contract.php` runs through real local WordPress. Its behavioral cases
were informed by WordPress's
[WP_SQLite_DB_Tests](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/tests/WP_SQLite_DB_Tests.php)
and
[PDO API tests](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/tests/WP_MySQL_On_SQLite_PDO_API_Tests.php):
driver access, versions, MySQL escaping, results, metadata and lifecycle. These
are focused DSQL tests, not a claim that the complete upstream suite passes.

```sh
php tests/tools/phpunit.phar tests/
PGSSLROOTCERT=system php tests/engine/standalone.php
PGSSLROOTCERT=system php tests/engine/wordpress-contract.php
```

Full logical MySQL schema reconstruction, comprehensive mysqli column metadata
and a complete PDO API remain separate work. The engine's result object supports
ASSOC/NUM/OBJ fetches, fetchAll, fetchColumn, rowCount, columnCount and getColumnMeta;
unsupported fetch modes fail explicitly.
