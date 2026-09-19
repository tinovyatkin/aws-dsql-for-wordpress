# Native MySQL → Aurora DSQL engine

The PHP extension executes an entire database operation in Rust. The WordPress
`wpdb` bridge supplies the original MySQL string and receives final rows,
metadata, affected-row count and generated identity. It does not serialize a
parser AST through PHP, invoke a PHP translator, or use PDO on the native path.

```text
wpdb → DsqlNativeEngine
         → sqlparser-rs MySQL AST
         → literal extraction and bounded translation-plan cache
         → DSQL compiler, logical catalog and parameter binding
         → AWS Rust DSQL connector / SQLx / reused TLS connection
         → value decoding → final Zend arrays
```

`sqlparser-rs` provides the typed query AST. A strict token grammar handles the
MySQL metadata commands it does not represent completely. All input must be
consumed. The compiler supports the PHP engine's WordPress query contracts;
unsupported constructs fail explicitly. This is not general MySQL emulation.

## Build and enable

The tested deployment target is **Debian 12, x86_64, PHP 8.5.10 NTS**. The
[Dockerfile](build/Dockerfile) pins Rust and PHP image digests, runs Rust tests,
builds the extension, checks PHP loading and native logging, and exports a binary
with SHA-256, source commit, PHP ABI and dynamic-library metadata. The
[GitHub workflow](../.github/workflows/native-linux.yml) runs this build off-host.
Build an artifact for the actual PHP ABI and operating system; do not copy a
macOS library to Linux or load an NTS build into ZTS PHP.

For local macOS arm64 development, install matching PHP development headers,
`php-config`, a C compiler and libclang. Build **from this directory** so Cargo
loads the macOS dynamic-linker configuration:

```sh
cargo test --locked --no-default-features
cargo build --locked --release
php -d extension="$PWD/target/release/libwp_dsql_native.dylib" example.php
```

On Linux load `wp_dsql_native.so` through the relevant PHP CLI/FPM configuration.
Restart or gracefully reload FPM after changing the library. Enable the engine
before WordPress bootstrap:

```php
define('DSQL_ENGINE', 'native');
```

The normal drop-in configuration supplies endpoint, region, profile, schema,
role, table prefix, SQL mode and codec policy. The extension must expose the
expected API version; a missing or mismatched extension fails explicitly.
Omitting `DSQL_ENGINE` retains the PHP engine as a deployment rollback option.
There is no automatic PHP fallback after a native operation fails.

Use a trusted CA **file path**, for example
`PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt`, rather than libpq's `system`
sentinel. TLS verifies both the certificate chain and hostname. On macOS set
`SSL_CERT_FILE` too, before starting FPM, to avoid initializing Keychain access
for the first time after a fork.

## Connections, errors and cache lifetime

The Tokio runtime starts lazily in each FPM worker, after the master forks. Each
worker supports up to eight distinct configurations and two physical connections
per configuration. A logical client holds its own connection; transactions are
never multiplexed between clients. Idle connections expire after 60 seconds and
connections retire after 55 minutes, between operations. The AWS connector
refreshes signed authentication tokens; an explicit credential file is reread
when credentials are requested, including after atomic rotation.

Native 256-entry LRU caches retain compiled plans, without literal values or
query results. SQLx retains up to 32 prepared statements per connection. The
native parser still runs for each query; a plan-cache hit skips SQL compilation.
The WordPress `prepare()` capture records bounded hashes, not SQL strings.
Logical catalog generations invalidate compiled, metadata and prepared-statement
caches. Restart workers after controlled schema maintenance to establish a clean
boundary for already-running requests.

Only known aborted concurrency conflicts are retried outside caller-owned
transactions. A multi-statement `REPLACE` retries its entire owned transaction.
A failed read may reconnect once; an ambiguous write failure is never replayed.
A lost caller-owned transaction must be rolled back before the client can reconnect.
Closing a client with an open transaction discards the physical connection.

Metadata transport failures discard the broken connection without replaying the
failed operation. A subsequent request operation can reconnect; a caller-owned
transaction still requires rollback first.

Rust classifies failures, fingerprints redacted SQL shapes and emits the
`wordpress_dsql_error` JSON event through PHP's native logging API. The WordPress
bridge supplies request context and code location. SQL values, query text,
credentials and database exception details are excluded from the event. Existing
log collectors can continue using the version-1 event contract.

## JSON and binary values

Version 0.11.1 binds JSON/JSONB values without applying the TEXT envelope codec.
Results preserve PostgreSQL's textual representation, including exact numeric
spelling and, for JSON, whitespace and duplicate keys. JSON `null` remains distinct
from SQL NULL. Equality, range and membership predicates on JSON columns are
explicitly rejected before execution; PostgreSQL JSONB comparisons remain available.
Binary column parameters are hex-encoded after literal parsing and
decoded by PostgreSQL, preserving NULs and backslashes without interpreting them
as PostgreSQL BYTEA escapes. SQL input retains the existing UTF-8 requirement.

## Installation and maintenance

The native engine implements ordinary installation CREATE/DROP, index-job waits,
identity handling, logical schema metadata, SHOW and supported INFORMATION_SCHEMA
queries. Restored tables retain the managed-schema guard.

Explicit controlled-upgrade sessions use the existing PHP maintenance engine,
which owns recovery journals, rebuilds and rollback verification. This deliberate
maintenance boundary does not move normal queries or diagnostics back into PHP.
Keep Composer dependencies and maintenance tools installed for upgrades and for
an explicit rollback to the PHP runtime.

## Validation

`cargo test --no-default-features` checks compilation, metadata grammar, DDL,
codec framing, redaction and credential-file rotation. PHP scripts under
`tests/` exercise the full boundary against the separately tagged synthetic DSQL
fixture. Existing repository WordPress, core differential and plugin fixtures
also run with `DSQL_ENGINE=native`; standalone suites accept
`DSQL_TEST_ENGINE=native`.

`tests/json-binary-integration.php` checks JSON/JSONB and binary insert, update,
read and comparison behavior against the PDO wire reference, with the TEXT codec
enabled. The ignored `metadata_transport_failure` Rust test injects connection
resets into catalog and column/index metadata results and checks reconnect and
caller-owned transaction behavior. Run live fault tests with `--test-threads=1`.

`tests/schema-cache.php` verifies native cache invalidation across a controlled
rebuild that retains the original physical table for rollback. It uses the
separate synthetic upgrade fixture and its guarded runner.

Connection fault tests are ignored by default because they require that fixture:

```sh
WPDSQL_NATIVE_TESTS=synthetic cargo test --no-default-features --lib \
  connection_lifetime -- --ignored --test-threads=1
```

They reject a missing or differently tagged local fixture. Never point these
write tests at production. See [the release gate](production-readiness.md) for
validation and rollout requirements. `dsql_native_reset_worker()` is a test
isolation helper; it refuses to reset pools owned by live clients.
