# Standalone MySQL-on-DSQL engine

Adapter 0.7 implements the first milestone from the
[SQLite architecture review](sqlite-architecture-review.md): a standalone engine
and explicit WordPress compatibility contracts. The existing parser, compiler,
renderer, value codec, cache and controlled-upgrade algorithm remain in use.

```text
WordPress core and plugins
  → DSQL_WPDB: configuration, query filters, prepare(), wpdb state, diagnostics
  → WPDSQL\Engine\Driver: logical session and execution
      → cached MySQL translation → SQL and current PDO bindings
      → existing metadata emulation / schema catalog
      → controlled upgrade engine when explicitly attached
      → AWS PDO connection and DSQL retries
  → buffered Result → WordPress results or a standalone PHP caller
```

## Standalone API

Only Composer is required to load the engine; it does not load WordPress or read
WordPress globals/constants. Supply configuration explicitly:

```php
require 'vendor/autoload.php';

use WPDSQL\Engine\Config;
use WPDSQL\Engine\Driver;

$driver = new Driver(new Config(
    host: 'your-cluster.dsql.eu-central-1.on.aws',
    user: 'your_database_role',
    region: 'eu-central-1',
    schema: 'wp_live',
    tablePrefix: 'wp_',
    // Optional local shared profile; omit for the default AWS credential chain.
    // profile: 'your-test-profile',
));

$result = $driver->query('SELECT 1 AS example');
$row = $result->fetch(PDO::FETCH_ASSOC);
$stats = $driver->cacheStats();
$driver->close();
```

`Config` also accepts `database` (default `postgres`), an optional credentials-file
path, `sqlMode`, `valueCodec`, `cacheEnabled` and `cacheDirectory`. Schema choices
remain `public` and `wp_live`. The default table prefix is `wp_`; the configured
prefix continues to bound unmanaged table creation. Restored schema changes
still require the controlled upgrade runner.

`query(string $sql, bool $deferIndexWait = false)` accepts a complete MySQL
statement. Values are extracted and bound by the existing translation pipeline.
It is not a PDO subclass and does not expose a public prepared-statement API or
pretend to support every PDO method. Native PDO parameter binding remains below
this API. The index-wait flag is intended for the existing installation batch.

The returned `Result` is buffered and independent of later queries. It supports:

- `fetch()` / `fetchAll()` with `PDO::FETCH_ASSOC`, `FETCH_NUM`, or `FETCH_OBJ`;
- `fetchColumn()`, with null distinct from exhausted results;
- `rowCount()`, `columnCount()` and `getColumnMeta()`;
- `operation`, `command` and `insertId` properties for the WordPress shim.

Values retain the adapter's string/null contract, temporal sentinel conversion
and optional text-codec decoding. Metadata retains the existing native-column
information; complete MySQL/mysqli type and flag reconstruction is not claimed.
Unsupported fetch modes fail explicitly.

## Ownership and state

`AwsConnection` owns IAM credential resolution and native PDO options. `Driver`
owns the PDO handle, translation state, metadata cache, pending asynchronous
index jobs and optional controlled-upgrade session. A factory and clock can be
injected for deterministic transport tests.

Connection renewal occurs between operations after 55 minutes, preserving SQL
modes, prepared captures, translation plans and the existing FOUND_ROWS plan.
Renderer metadata is refreshed. A caller-owned transaction prevents renewal until
it ends; the driver never silently moves an open transaction to another
connection. Expiry or network errors inside that transaction are surfaced to its
owner, not retried on a replacement connection.

Known aborted standalone statements can retry. An adapter-owned atomic REPLACE
retries its entire batch; a caller-owned transaction remains the caller's retry
responsibility. Ambiguous connection failures are not replayed. Explicit close
rolls back any open transaction and releases the engine's connection references.

DDL still clears cached catalog misses even if part of a DDL batch failed.
Attaching an upgrade session retains role checks, maintenance ownership, journal
failures, verified schema publication and recovery. Identity reseeding is an
explicit engine operation invoked by the WordPress shim only for the existing
single-writer default-category installation case.

`QueryException` has a safe top-level message with stage, operation and retry
information. `nativeFailure()` provides explicit access to the original error;
there is no chained native exception in the default message. The WordPress shim
uses that native failure to preserve its existing structured, value-free logging
and `last_error` contract. `queryCount()` retains the adapter's execution counter;
it is not a count of every catalog or upgrade-internal network round trip.

## WordPress integration

Existing WordPress configuration constants are read by `DSQL_WPDB` and passed to
`Config`. `$wpdb->get_driver()` returns the standalone engine. Internally,
`$wpdb->dbh` now holds that engine rather than a raw PDO connection; code that
bypasses the supported `wpdb` API must account for this change.

`wpdb::prepare()` still returns the ordinary WordPress string. Query filters run
in the shim before execution, and the final string determines cache reuse.
WordPress result objects, query counts, errors, insert IDs, placeholder escaping
and optional SAVEQUERIES logging remain in that layer. A filtered-out query clears
`insert_id`; capabilities accept case-insensitive names; closed adapters expose
empty server-version information. The engine also supplies `client_info` for
WordPress's database-health inspection.

## Verification

Local validation for this milestone:

- 28 PHPUnit tests / 103 assertions, including 11 engine tests for session
  renewal, bindings, result cursors, metadata, close and retry ownership;
- 15 standalone synthetic DSQL checks with no WordPress bootstrap;
- 28 contracts through the real WordPress adapter, informed by upstream's
  SQLite `wpdb` and PDO API tests;
- existing 24 WordPress API and 33 plugin-fixture checks;
- 21 write/mode guards and 18 MySQL/DSQL comparisons, retaining the two documented
  backend differences;
- 27 interrupted-upgrade recovery checks, three rejection checks and the
  cached-schema/index guard;
- fresh installation with zero installation errors;
- seven HTTP checks and the external Node.js writer/read-back checks, with no
  new database errors or PHP fatal errors during the HTTP run;
- existing diagnostics, parser and cache checks.

The isolated translation benchmark remained comparable: a 7.43 ms cold first
translation, about 0.04 ms warm median, and a 1.30 ms first persistent-cache hit
without loading the parser. These figures are not network or full-request timings.
See [engine test instructions](../tests/engine/README.md).

Deploy this release as a complete artifact including `engine/`, updated Composer
autoload files, `pg4wp/`, parser, migration and upgrade files. Do not copy only the
WordPress shim. The implementation work does not deploy staging or production.

## Next milestone

The schema model remains unchanged in this release. Next: replace the separate
DDL tokenizer with schema operations derived from the standalone AST, unify
installation/restoration metadata, and adopt the broader `SHOW` / supported
`INFORMATION_SCHEMA` / column contracts from the SQLite project. Evolve the
catalog through a versioned migration while retaining DSQL's controlled physical
schema publication. Those changes are not prerequisites for using this engine.
