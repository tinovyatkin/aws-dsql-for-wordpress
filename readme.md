# AWS DSQL for WordPress

A WordPress database adapter for **Amazon Aurora DSQL**, with a standalone
MySQL compatibility engine, cached SQL translation, migration tooling, and
controlled schema upgrades.

WordPress uses its official `wp-content/db.php` extension point. The DSQL driver
extends the installed core `wpdb` class and connects through AWS's
[`awslabs/aurora-dsql-pdo-pgsql`](https://github.com/awslabs/aurora-dsql-php-pdo-pgsql)
package. It does not rewrite or patch WordPress core. Aurora DSQL is the only
supported database backend.

The repository includes synthetic integration tests for installation, login,
publishing, native comments, REST CRUD, and an independent Node.js comment
writer. Migration and controlled-upgrade tests cover data verification and
recovery from interrupted schema changes. Compatibility must be evaluated for
each site's plugins, schema, and workload; this is not a drop-in MySQL replacement.

## WordPress SQLite foundation

This project builds on the work of the
[WordPress SQLite Database Integration contributors](https://github.com/WordPress/sqlite-database-integration).
It includes their standalone PHP MySQL parser, pinned to a specific upstream
revision and generated from MySQL's Bison grammar. Their separation of WordPress
integration from database execution, logical MySQL schema model, and compatibility
contracts also informed the engine and schema architecture here.

The DSQL SQL emitter, cached translation plans, AWS connection handling, and
migration and upgrade runners are implemented for Aurora DSQL. This is an
independent adapter, not an official WordPress or AWS product. See
[parser provenance](docs/mysql-parser.md) and the
[architecture review](docs/sqlite-architecture-review.md) for the specific sources
and adaptations.

## Architecture

```text
WordPress core and plugins
        │ usual wpdb / MySQL queries
        ▼
wp-content/db.php → DSQL_WPDB → standalone MySQL-on-DSQL engine
                                      │ cached translation plans
        │
        ▼
AWS PHP PDO connector → Aurora DSQL ← AWS Node.js connector ← independent worker
```

One logical MySQL schema model serves installation, restored
metadata, AST-derived DDL and introspection. The standalone engine owns connection
renewal, SQL execution, session state, retries and buffered results. See
[the engine API](docs/standalone-engine.md) and
[the logical schema and catalog migration](docs/logical-schema.md).

The DSQL implementation:

- uses IAM authentication and verified TLS through the AWS PHP connector;
- subclasses `wpdb` directly and preserves its public string-based API;
- parses value-free MySQL templates with WordPress's standalone MySQL parser, then emits DSQL
  SQL with current PDO bindings;
- caches compiled instructions across requests without caching values or results;
- translates auto-increment columns into DSQL identities with explicit `CACHE 1`;
- submits indexes using `CREATE INDEX ASYNC` and waits for their completion;
- splits translated DDL into individual statements;
- resolves unambiguous upserts against actual unique indexes and executes supported
  REPLACE operations atomically;
- implements pagination counts and the term-query ordering used by WordPress;
- retries known aborted single-statement concurrency conflicts, never ambiguous
  connection failures or an individual statement inside a caller-owned transaction;
- reconnects between operations before DSQL's one-hour connection limit.

During installation, index jobs can be submitted as a batch. The driver waits
before the first write relying on those indexes. Core's explicit default-category
ID is reseeded during that single-writer installation phase.

## Quick start

Requires AWS CLI with an authorized profile, PHP 8.2+ with `pdo_pgsql` and
`mbstring`, WP-CLI,
`curl`, and a PostgreSQL client library with a trusted CA configuration. The
provided scripts default `PGSSLROOTCERT=system` (libpq 17+); for older clients,
set `PGSSLROOTCERT` to your trusted CA bundle. TLS verification stays enabled.
Composer is used if installed; otherwise the bootstrap downloads and verifies a
local Composer PHAR. Node.js is only required for the external-writer test.

```sh
git clone https://github.com/tinovyatkin/aws-dsql-for-wordpress.git
cd aws-dsql-for-wordpress
export AWS_PROFILE=your-test-profile
export AWS_REGION=eu-central-1
export PGSSLROOTCERT=system

./scripts/bootstrap-local.sh
./scripts/serve-local.sh
```

Open **http://127.0.0.1:9417**. The local server listens only on loopback.
The synthetic administrator is `dsqltest`; its generated password is in
`.local/admin-password`. Outgoing WordPress mail and automatic WP-Cron spawning
are disabled for this fixture.

The bootstrap creates a separate DSQL cluster tagged
`Purpose=synthetic-wordpress-compatibility`, then downloads stock WordPress into
an ignored local directory. It never reads a production WordPress installation,
copies a production database, or copies AWS credentials into the checkout.
WordPress uses the selected AWS profile by name. AWS charges/free-tier rules
apply to the test cluster. The scripts leave it available for further testing.

This is a **native-PHP, Playground-style fixture**, not the browser/WASM
Playground runtime: it needs native PostgreSQL networking and `pdo_pgsql`.

## Verification

With the local server running:

```sh
export PGSSLROOTCERT=system
php tests/dsql/smoke.php
npm ci
node tests/dsql/external-writer.mjs
php tests/dsql/read-external.php
python3 tests/dsql/http-smoke.py

# Run compiler and cache checks (no database required).
php tests/tools/phpunit.phar tests/
php tests/translation/cache.php
wp core verify-checksums --version=7.1 --path=.local/wordpress
```

The PHP smoke test creates only synthetic data. The Node.js test inserts a
native WordPress comment and updates its denormalized post comment count in a
transaction. The follow-up PHP test uses a **fresh WordPress process** to read
it, and the HTTP test confirms it appears on the public article.

Direct writers to WordPress-owned tables must also maintain any relevant
counters and invalidate persistent WordPress/page caches. This fixture has no
persistent object cache; moving the database does not make cache invalidation
automatic.

To reset only the synthetic tables and rerun the installer:

```sh
php scripts/reset-local.php --reset-synthetic-fixture
./scripts/bootstrap-local.sh
```

To remove the AWS test cluster when finished, stop the local server, then run:

```sh
./scripts/delete-test-cluster.sh --delete-synthetic-cluster
```

The deletion helper checks the live cluster tag before deletion. It retains the
local record; use a new `.local` directory to create a new experiment afterward.

## Installation

For an existing test WordPress installation, follow the
[drop-in installation and configuration guide](docs/installation.md).
Install the database drop-in before WordPress creates its tables. Existing
MySQL data requires a separate migration; activating a plugin does not migrate it.

## Backup and restore migration

The migration tooling includes a logical MySQL backup, DSQL preflight, empty-target restore,
and full-table verification CLI. Restored tables retain their original MySQL
schema metadata; the unchanged core schema passes dbDelta without false changes.
An explicit migration policy controls table archival and supported index
omissions. An opt-in reversible codec preserves NUL bytes in eligible text
columns, and application access can use a non-admin database role.
See [the migration workflow and rollback boundary](docs/backup-restore-migration.md).
The CLI never changes the live WordPress connection.

## Controlled schema upgrades

The CLI-only upgrade runner supports schema planning, verified table
rebuilds, synchronized MySQL metadata, retained originals, separate upgrade
credentials, and recovery from interrupted DDL. Core/plugin versions must be
pinned, and all writers must be paused before an upgrade. See
[controlled upgrades](docs/controlled-upgrades.md) for setup, commands, tested
scope, and remaining limitations.

## MySQL translation and caching

SQL translation uses WordPress's standalone PHP MySQL parser, generated from
MySQL's Bison grammar. Repeated query shapes reuse bounded, value-free plans; persistent
hits avoid loading the parser. See [configuration, supported forms, and verification](docs/sql-translation.md)
and [parser sources, maintenance, and the Rust evaluation](docs/mysql-parser.md).

## Core SQL coverage

The [WordPress 7.1 source audit](docs/wordpress-core-sql-audit.md) inventories
core database calls, checks a maintenance-branch snapshot, and records concrete
translation gaps with reproducible probes. Version 0.9 implements the confirmed
[core compatibility additions](docs/core-compatibility.md): Site Health metadata,
calendar filters, explicit binary comparisons, and bounded legacy maintenance
and upgrade behavior. Static source coverage is separate
from live execution and MySQL result-equivalence testing.

## Known limits

- **Unattended schema upgrades are not supported.** Migrated tables use a verified
  MySQL metadata catalog, so unchanged schemas compare correctly. Schema changes
  on those tables require the controlled CLI runner; ordinary requests cannot
  perform them. Keep automatic core/plugin updates disabled.
  New installations retain logical MySQL metadata. Older tables without a saved
  catalog use explicitly inferred PostgreSQL metadata until a verified source
  definition is provided.
- The DSQL emitter supports a tested subset of the MySQL grammar and fails on
  unimplemented constructs. Arbitrary plugin SQL, multisite, MySQL collations,
  unsigned integer semantics, and unusual schema changes need separate work
  and testing. Upsert affected-row counts and Unicode case mapping retain
  documented DSQL behavior.
- MySQL zero dates use a year-1 sentinel in temporal columns only. Literal text
  is preserved. Migration preflight rejects real source dates using that sentinel.
- NULs can use the opt-in reversible text codec on unindexed TEXT columns.
  Other NUL uses and SQL pattern searches inside encoded payloads remain unsupported.
- DSQL limits on transactions, rows, index keys, and connection lifetime still apply.
  Manual multi-statement transactions need whole-transaction retry at their owner.
- Synthetic tests do not establish compatibility with every plugin, theme, or
  persistent-cache configuration, or validate heavy concurrency, failover, or
  production load.

## License

Licensed under GPL-2.0-or-later; see [license.md](license.md). Included WordPress
parser sources retain their [upstream license](parser/mysql/WordPress/LICENSE)
and copyright notices.
