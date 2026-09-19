# Native MySQL → DSQL prototype

This is an experimental PHP extension implementing a complete query operation in
Rust. It is not loaded by `db.php` and does not replace the production engine.

```text
PHP query(MySQL string)
  → Rust tokenizer and value extraction
  → native bounded translation-plan cache
  → sqlparser-rs MySQL AST and conservative DSQL compiler on a miss
  → native schema lookup, parameter binding and temporal conversion
  → AWS DSQL Rust connector + SQLx + reused PostgreSQL connection
  → native row decoding
  → final PHP arrays, affected-row count, insert ID and timing counters
```

No PHP lexer, parser, translator, Composer autoloader, PDO call, JSON round-trip,
subprocess or PHP callback participates in a native query. The original query
crosses the PHP boundary once; final results are converted directly to Zend
values. JSON in the benchmark endpoint is only the final HTTP/FastCGI response.

The parser is Apache `sqlparser-rs` 0.63.0, selected for its typed AST. The official
AWS Rust SQLx connector is 0.2.2; `ext-php-rs` 0.15.15 provides the PHP boundary.
Exact dependencies are recorded in `Cargo.lock`.

## Build and use

Verified with Rust 1.98.1 and PHP 8.5.10 NTS on macOS arm64. PHP headers,
`php-config`, a C compiler and libclang are required. Linux builds use the same
source but have not yet been validated. Run Cargo **from this directory** so it
reads the macOS dynamic-linker configuration:

```sh
cd native
cargo build --release
cargo test --no-default-features
cargo clippy --all-targets -- -D warnings
```

Load only into an explicitly selected test PHP process:

```sh
php -d extension="$PWD/target/release/libwp_dsql_native.dylib" example.php
# Linux artifact: target/release/libwp_dsql_native.so
```

```php
$db = new DsqlNativePrototype(
    'your-cluster.dsql.eu-central-1.on.aws',
    'eu-central-1',
    'your-test-profile',
    'your_database_role',
    'public',
    'your_test_prefix_',
    'immutable-fixture-revision-1'
);
$result = $db->query("SELECT ID, post_title FROM your_test_prefix_posts WHERE ID=1");
$result['rows'];          // Associative string/null values.
$result['columns'];       // Projection order, when the result has rows.
$result['affected_rows'];
$result['insert_id'];     // String, empty if no generated identity.
$result['timing'];        // Milliseconds plus cache/reuse indicators; no SQL values.
$db->close();
```

Provide a real CA bundle through `PGSSLROOTCERT` when sharing a process with the
PHP comparison adapter. libpq's special `system` setting is not a SQLx file path.
On macOS, also set `SSL_CERT_FILE` to that bundle to avoid loading the Keychain
certificate provider for the first time inside a forked FPM child. The benchmark
harness sets both, without disabling certificate validation or fork safety.

## Lifetime and cache behavior

The runtime starts lazily after FPM forks. Each worker retains one connection per
configuration, up to eight configurations. Each configuration has a 256-entry
native LRU; combined key/emitted-SQL size is capped at 32 KiB per retained plan.
Literal values and comments are not retained in cache keys or plans. Current
values are bound again on every call. SQLx retains up to 32 server-prepared
statements, including tested eviction through PostgreSQL protocol messages.

The pool closes idle connections after 60 seconds and retires them after
55 minutes. The AWS connector refreshes authentication tokens. Checkout does not
issue a separate ping: connection failures are reported without replaying writes.
`BEGIN` pins the connection until `COMMIT` or `ROLLBACK`. Closing/destroying a
client with an open or failed transaction closes that physical connection so
state cannot leak into another request. PHP module shutdown closes idle pools and
stops the runtime. ZTS, pcntl forks after first use, failover and long-duration
credential/connection renewal have not been validated.

A worker supports one active database transaction per configuration. A second
client attempting to use that configuration while a transaction owns its only
connection will reach the bounded acquisition timeout; this is not a multiplexed
transaction service.

Plans retain physical-schema decisions. **The explicit revision must change after
any schema change**, or the worker must be restarted. This prototype intentionally
has no schema writes or automatic schema invalidation. It also lacks the released
adapter's managed-catalog readiness checks. Keep it on disposable, immutable test
schemas until those contracts are implemented.

`dsql_native_reset_worker()` is a benchmark helper that clears pools and plans. It
refuses to operate while a live native client owns a backend. Normal PHP request
shutdown preserves idle pools and plans.

## Implemented scope

- SELECT with explicit column resolution, aliases, inner/left joins, WHERE,
  IN/BETWEEN/LIKE, grouping, basic aggregates, ordering and MySQL LIMIT syntax.
- IFNULL/COALESCE, CONCAT, lower/upper case, byte and character length.
- INSERT VALUES (including multiple rows) with explicit non-identity columns and
  generated identity results; single-assignment UPDATE; simple DELETE.
- Explicit transactions, string/null results, common numeric and temporal types,
  temporal zero-date sentinels, bound values and changing-value cache reuse.

Unsupported syntax is rejected; there is no fallback to PHP or raw SQL execution.
This is **not complete WordPress compatibility**. Missing work includes SHOW and
INFORMATION_SCHEMA emulation, controlled DDL/upgrades, the logical schema catalog,
SQL modes, NUL codec, REPLACE/upserts, FOUND_ROWS, subqueries/unions, richer function
and coercion behavior, full result metadata (including empty-result columns),
wpdb integration and OCC retries. Numeric/text coercions and ordinal ORDER/GROUP
BY are deliberately rejected. Existing DSQL collation differences remain.

## Reproduce the tests and benchmark

The integration harness uses the repository's existing synthetic lab configuration
in ignored `.local/settings.json` and `.local/cluster.json`. Fixture creation and
cleanup additionally verify the cluster's current synthetic-purpose tag via AWS
CLI. It creates two uniquely named tables and 80 synthetic rows; it never reads a
WordPress content database.

From the repository root, after building the extension:

```sh
python3 native/tests/run-fpm.py
```

The harness starts a disposable, loopback-only, single-worker PHP-FPM process with
OPcache, compares the PHP engine and native extension serially, validates identical
results, and stops the process afterward. It removes a fixture that it created;
an existing explicitly created fixture remains available until
`php native/tests/fixture.php cleanup`. The raw timing records stay under ignored
`.local/native-fpm-{warm,fresh}.json`.

There are seven compiler test groups and 74 live DSQL integration checks, covering
read parity, Unicode/quotes, injection-shaped literal values, nulls, generated
IDs, writes, commit/rollback, failed and abandoned transactions, cache reuse,
prepared-statement eviction, reset guards and unsupported SQL rejection.

An optional syntax-only corpus probe accepts a JSON array of SQL strings:

```sh
cd native
cargo run --no-default-features --example corpus -- /path/to/synthetic-queries.json
```

All 266 statements in the available synthetic WordPress capture parsed. Parsing
success does not mean the native compiler implements those statements. See
[the measured results](benchmark-results.md) for complete-request comparisons and
limits on what they demonstrate.
