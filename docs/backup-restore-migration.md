# MySQL → DSQL through logical backup and restore

The migration tool creates a portable **logical backup**, restores it into a
fresh DSQL cluster, and checks every restored table before any WordPress
connection is changed. This is a database migration: the existing WordPress
code, plugins, theme, media, and server configuration must be retained separately.
A MySQL SQL dump is retained separately for rollback;
it is not executed against DSQL.

This tooling does **not** perform production cutover. Its plan and verification
reports explicitly say that cutover has not happened. Application/plugin
compatibility and a final write freeze remain separate release requirements.

## Backup

Run on a host with PDO MySQL that can reach the MySQL source. With a WordPress installation,
only `SHORTINIT` is loaded to read its existing database configuration. Normal
plugins, scheduling, and rendering are not run. Remote MySQL connections require
a CA bundle and certificate verification; credentials are never passed in CLI
arguments or copied into the backup.

```sh
php scripts/migrate.php inspect-source \
  --wordpress-path=/var/www/html \
  --mysql-ca=/private/mysql-ca.pem \
  --error-report=/private/inspection-error.json

php scripts/migrate.php backup \
  --wordpress-path=/var/www/html \
  --mysql-ca=/private/mysql-ca.pem \
  --backup=/private/new-migration-backup \
  --classification=production \
  --error-report=/private/export-error.json
```

For a non-WordPress MySQL fixture, `--mysql-config=/private/source.php` accepts a
PHP file returning `dsn`, `user`, `password`, and optionally `ssl_ca`. The
source DSN must select the database and use `charset=utf8mb4`. The exporter uses
a read-only repeatable-read consistent snapshot, sets the session timezone to
UTC, streams the data, and rejects non-InnoDB tables, views, routines, triggers,
and events instead of silently omitting them. Do not run DDL concurrently.

Every table has:

- its original MySQL schema, column metadata, and index metadata;
- a compressed JSON-lines file with every value base64-encoded or SQL NULL;
- a file SHA-256, row count, and order-independent multiset digest of all rows.

This preserves IDs, password hashes, URLs, PHP serialization, Unicode, binary
values, and duplicate rows without deserialization or search-and-replace.
JSON numbers are never used for database values, so large IDs/decimals do not
lose precision. A completed manifest and checksum are written last. An existing
backup directory is never overwritten.

Backups contain private site/account data and are created with restrictive file
permissions. Keep them in private storage; never commit them or serve their
directory through WordPress. SQL exception details are written only when a
private `--error-report` path is explicitly supplied.

## Plan and restore

```sh
php scripts/migrate.php plan \
  --backup=/private/new-migration-backup \
  --report=/private/restore-plan.json \
  --error-report=/private/plan-error.json

AWS_PROFILE=migration AWS_REGION=eu-central-1 \
  ./scripts/create-migration-target.sh production /private/dsql-target.json
```

The target helper creates a fresh tagged cluster and records its identity even
if waiting for activation is interrupted. Production targets have deletion
protection enabled. It restores no data.

Set the explicit expected endpoint from the created target's configuration:

```sh
php scripts/migrate.php restore \
  --backup=/private/new-migration-backup \
  --target-config=/private/dsql-target.json \
  --expect-target=YOUR-CLUSTER.dsql.eu-central-1.on.aws \
  --allow-production-data=yes \
  --report=/private/restore-verification.json \
  --error-report=/private/restore-error.json
```

The target JSON contains `endpoint`, `region`, `profile` (optional when using a
role/default credential chain), and optional database `user`. It contains no
AWS access keys. The migration identity needs the cluster's control-plane read
permission as well as database access.

The tool checks both the explicit endpoint and the **live AWS cluster purpose
tag**. Production data requires `Purpose=wordpress-dsql-production-migration`;
synthetic data requires `Purpose=synthetic-wordpress-migration`. It refuses a
nonempty destination. Never import a production backup into either Playground
or a synthetic local WordPress/demo cluster.

Before creating any table, the tool validates the entire backup and resolves
all DDL. Unsupported types, generated/on-update columns, unique prefix indexes,
explicit constraints that need translation, or oversized rows/index keys stop
the operation. Prefixes on ordinary non-unique indexes become full-column
indexes, with key-size checks. No table is skipped or truncated.

Restore creates the schema and asynchronous indexes, waits for them, writes
bounded parameterized batches, and reseeds every identity above the greatest
imported ID. It verifies all row counts and multiset hashes. The database keeps
an internal restore phase record: schema, loading, verified, or failed.

A failed restore leaves its isolated target for investigation. There is no
blind merge/resume into partial data; use a new empty target for a new attempt.

## Verification and WordPress schema metadata

```sh
php scripts/migrate.php verify \
  --backup=/private/new-migration-backup \
  --target-config=/private/dsql-target.json \
  --expect-target=YOUR-CLUSTER.dsql.eu-central-1.on.aws \
  --allow-production-data=yes \
  --report=/private/verification.json \
  --error-report=/private/verification-error.json
```

Verify **before application writes are enabled**. Once WordPress starts writing
to the target, differences from the backup are expected.

Restored MySQL column/index metadata is retained in `__wp_dsql_schema`. Version
0.8 writes the shared version-2 logical model while continuing to read legacy
rows without automatic writes. See [catalog migration](logical-schema.md).
The driver uses it for `DESCRIBE` and `SHOW INDEX`, avoiding false `dbDelta`
changes caused by PostgreSQL type representations. A fingerprint detects native
schema drift before that metadata is reported. An unchanged restored WordPress
core schema passed `dbDelta()` with zero proposed changes in the rehearsal.

Automatic ALTER/DROP on these restored tables currently fails closed: a real
schema upgrade needs code that updates both the physical schema and its metadata.
This is still an experimental adapter, not an assurance that every plugin's SQL,
collation behavior, unsigned semantics, or future upgrades work unchanged.

MySQL zero dates use a reserved year-1 value in **temporal columns only**.
Literal text is preserved unchanged. The plan rejects actual source dates using
that reserved sentinel. Binary data is preserved by the backup/restore tool;
arbitrary binary writes through the WordPress SQL translator need separate testing.

## Final cutover and rollback boundary

1. Complete protected application/plugin checks against the intended target.
2. Freeze **all** writers: HTTP mutations, editors, WP-CLI/automation jobs,
   external workers, and scheduled jobs. Drain in-flight work. WordPress's
   maintenance page alone does not stop independent writers.
3. Create the final native MySQL rollback checkpoint and logical backup under
   that freeze. Restore into a fresh target and verify it completely.
4. Preserve the exact old WordPress connection configuration and any existing
   `db.php`. After an explicit cutover decision, switch the driver/configuration,
   invalidate persistent object/page caches, and validate while writes stay frozen.
5. Before reopening writes, rollback is a configuration switch to the unchanged
   MySQL database plus cache invalidation. Resume the original workers only after
   the chosen database is authoritative.
6. **After new writes reach DSQL, switching back to the frozen MySQL database
   would lose those writes.** Freeze again and reconcile/export the DSQL changes
   before rollback. This tool does not implement reverse replication.

## Rehearsal evidence

The 7 September 2026 rehearsal used a private, local MySQL 8.4 Docker fixture and
a separate synthetic DSQL cluster. Fourteen tables and 145 rows verified exactly,
including serialized WordPress content, binary data, a high-precision decimal,
and duplicate rows in a table without a primary key. Eleven restored-WordPress
checks passed, including unchanged login, imported IDs, new identity allocation,
literal zero/year-1 text, and zero `dbDelta` differences. Seven rejection-path
checks covered overwrite, corruption, production/synthetic separation, unsupported
schema, nonempty targets, remote MySQL TLS, and byte-exact source NUL detection. The restored site also rendered in a browser after restoration. No production data was restored.
