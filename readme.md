# AWS DSQL for WordPress

An **experimental, working WordPress-on-Aurora-DSQL proof of concept**, forked from
[PostgreSQL for WordPress (PG4WP)](https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress).

WordPress uses its official `wp-content/db.php` extension point. The DSQL driver
extends the installed core `wpdb` class and connects through AWS's
[`awslabs/aurora-dsql-pdo-pgsql`](https://github.com/awslabs/aurora-dsql-php-pdo-pgsql)
package. It does not rewrite or patch WordPress core. The original PG4WP driver
is retained and can be selected with `DB_DRIVER=pgsql`.

**Verified on 7 September 2026:** a fresh WordPress 7.1 installation on real
Aurora DSQL in Frankfurt, PHP 8.5.10, and the stock Twenty Twenty-Five theme.
Installation, login, publishing, native comments, REST CRUD, and a separate
Node.js process writing a comment into the same database all work. See the
[verification report](docs/verification-2026-09-07.md) for the exact scope and
remaining gaps. This is not a production-ready MySQL replacement.

## Architecture

```text
WordPress core and plugins
        │ usual wpdb / MySQL queries
        ▼
wp-content/db.php → DSQL_WPDB → PG4WP-derived SQL translation
        │
        ▼
AWS PHP PDO connector → Aurora DSQL ← AWS Node.js connector ← independent worker
```

The DSQL implementation:

- uses IAM authentication and verified TLS through the AWS PHP connector;
- subclasses `wpdb` directly, without the original driver's runtime core-source rewriting;
- protects SQL string literals before applying the inherited rewrite rules, so
  article text and PHP-serialized data do not get rewritten as SQL;
- translates auto-increment columns into DSQL identities with explicit `CACHE 1`;
- submits indexes using `CREATE INDEX ASYNC` and waits for their completion;
- splits translated DDL into individual statements;
- adapts upserts using actual unique indexes and preserves update expressions;
- implements pagination counts and the term-query ordering used by WordPress;
- retries known aborted single-statement concurrency conflicts, never ambiguous
  connection failures or an individual statement inside a caller-owned transaction;
- reconnects between operations before DSQL's one-hour connection limit.

During installation, index jobs can be submitted as a batch. The driver waits
before the first write relying on those indexes. Core's explicit default-category
ID is reseeded during that single-writer installation phase.

## Run the isolated experiment

Requires AWS CLI with an authorized profile, PHP 8.2+ with `pdo_pgsql`, WP-CLI,
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

# The inherited PG4WP rewrite suite needs ext-pgsql as well.
php tests/tools/phpunit.phar tests/
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

## Install the drop-in in another test WordPress

Keep this repository at `wp-content/plugins/aws-dsql-for-wordpress`, run
`composer install --no-dev` there, and copy `pg4wp/db.php` to `wp-content/db.php`
**before installing WordPress**. Add configuration before loading `wp-settings.php`:

```php
define('DB_DRIVER', 'dsql');
define('DB_HOST', 'your-cluster.dsql.eu-central-1.on.aws');
define('DB_NAME', 'postgres');
define('DB_USER', 'admin'); // Disposable bootstrap only; scope production roles separately.
define('DB_PASSWORD', ''); // IAM tokens are generated by the AWS connector.
define('DB_CHARSET', 'utf8mb4');
define('DSQL_REGION', 'eu-central-1');
// Optional for local shared profiles; omit for an IAM role's default credential chain.
define('DSQL_PROFILE', 'your-test-profile');
```

Do not activate this as an ordinary plugin or point it at an existing MySQL
site expecting data to migrate automatically. A DSQL cluster contains the
built-in `postgres` database; this prototype uses its `public` schema.

## Known limits

- **Schema upgrades are incomplete.** `SHOW INDEX`/column introspection work, but
  `dbDelta()` still sees differences between MySQL types and their PostgreSQL
  representation. Do not run production upgrades or migrations with this prototype.
- The reused SQL rewrite rules are not a complete MySQL grammar or complete
  MySQL behavior emulation. Arbitrary plugin SQL, multisite, MySQL collations,
  unsigned integer semantics, complex `REPLACE` operations, and unusual schema
  changes need separate work and testing.
- MySQL zero timestamps currently use a year-1 sentinel. That approximation
  reserves the exact sentinel value and needs refinement for full fidelity.
- NUL bytes in PostgreSQL text values are rejected explicitly.
- DSQL limits on transactions, rows, index keys, and connection lifetime still apply.
  Manual multi-statement transactions need whole-transaction retry at their owner.
- The tests do not establish compatibility with a production site's full plugin
  set, persistent Redis cache, heavy concurrency, failover behavior, or load.

## Upstream and license

Original PG4WP work is credited to Hawk__, kevinoid, mattbucci, and the
[PG4WP contributors](https://github.com/PostgreSQL-For-Wordpress/postgresql-for-wordpress).
Their original driver and unit tests are retained. Source is GPL-2.0-or-later;
see [license.md](license.md). AWS connector dependencies retain their own
Apache-2.0 notices and are installed with Composer/npm, not vendored into Git.
