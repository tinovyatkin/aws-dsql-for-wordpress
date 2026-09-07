# Controlled WordPress schema upgrades

The upgrade runner permits explicit, version-pinned WP-CLI updates while ordinary
WordPress requests remain blocked. Production automatic updates should remain off.
This milestone supports a conventional single-site WordPress installation, its
core `dbDelta()` migrations and the tested active-plugin migration paths. It is
not a universal MySQL DDL implementation or an automatic rollback of arbitrary PHP.

## How schema changes work

The runner parses complete MySQL statements, including quoted defaults, commas,
column positioning and multi-action ALTERs. It handles CREATE TABLE, ADD/CHANGE/
MODIFY/DROP COLUMN, SET/DROP DEFAULT, add/drop indexes and primary keys, table
renames, and DROP TABLE. CREATE INDEX / DROP INDEX with MySQL's ON clause are
normalized to the same planner. Unsupported syntax stops the command.

Metadata-only changes update the catalog; table renames use native DSQL rename.
Other structural changes use an offline replacement-table migration:

1. Create a private replacement with the desired columns and constraints.
2. Build its indexes while empty. DSQL's synchronous-empty-index support is used
   when available; the asynchronous fallback records and waits for every job.
3. Copy in bounded batches, rejecting unsafe narrowing, incompatible NULLs,
   encoded binary text in indexes, and invalid conversions. Compare complete
   row counts and multiset SHA-256 hashes of the intended projection.
4. Preserve identity allocation beyond the original sequence's consumed range.
   Reserving that range can deliberately leave an ID gap; IDs are not reused.
5. Recheck the source and replacement, retain the original in
   `wp_upgrade_archive`, publish the replacement, restore runtime grants, and
   replace the saved MySQL metadata/fingerprint.

DROP TABLE retains the old physical table privately and removes its application
name/catalog entry. Ordinary runtime users have no access to retained copies.
These copies are not automatically deleted; review their operation journals and
backup coverage before an administrator cleans them up.

DDL and catalog DML cannot share a DSQL transaction. A private journal records
the operation before changes, including object IDs and verified hashes. Writes
and renames are flushed to disk. Recovery inspects actual database objects, so
a crash between a successful DDL statement and its journal update is recoverable.
Incomplete copying restarts only the disposable replacement; it never merges
unverified partial data into the original.

## Administrative setup

Deploy the complete release, including `pg4wp/`, `upgrade/`, `migration/`,
`scripts/`, Composer files and `vendor/`. Older bundles containing only `pg4wp/`
are insufficient. Keep the runtime and upgrader identities separate.

Using an administrator connection for the exact cluster:

```sh
php scripts/configure-upgrade-role.php \
  --target-config=/private/admin-target.json \
  --iam-principal=arn:aws:iam::ACCOUNT:user/DEDICATED-UPGRADE-USER \
  --error-report=/private/role-setup-error.txt
```

This creates `wp_upgrader`, gives it ownership of active application tables and
the schema catalog, and creates the database upgrade reservation table. Runtime
CRUD remains available on application tables, while writes to schema/restore
metadata are revoked. Legacy `wp_archive` access is SELECT-only. Admin receives
membership in the narrow owner role because PostgreSQL requires SET ROLE rights
for ownership transfer; the upgrader never receives admin membership.

The dedicated IAM identity needs DbConnect and GetCluster on the exact cluster,
and DescribeRecoveryPoint for the backup vault/recovery points used by this site.
Store its credentials outside the document root, readable only by the CLI
operator. The web process must not be able to read that credential file.

Example upgrade target (no access keys in this JSON):

```json
{
  "endpoint": "YOUR-CLUSTER.dsql.eu-central-1.on.aws",
  "region": "eu-central-1",
  "classification": "production",
  "user": "wp_upgrader",
  "credentials_file": "/private/upgrade-credentials",
  "backup_vault": "blonde-travel-dsql"
}
```

A named AWS profile can be used instead of `credentials_file`. The WordPress
connection, prefix and single-site status are inspected with SHORTINIT and must
match. The live AWS cluster purpose must match the data classification.

Set the connector's TLS trust bundle for standalone controller commands. On the
Debian origin use `PGSSLROOTCERT=/etc/ssl/certs/ca-certificates.crt` (including
through `sudo env`). On a libpq 17+ development host, `PGSSLROOTCERT=system` is
also supported. Certificate verification stays enabled.

## Run an upgrade

First pause and drain **all writers**, including editors/HTTP writes, cron,
WP-CLI jobs and external compute. Take a native backup after that freeze.
`--writers-frozen=yes` attests to this external operational step; a WordPress
guard cannot stop independent direct SQL clients. The runner validates a completed
backup for the exact cluster from the last 24 hours. Synthetic fixtures alone
can use `--backup-reference=synthetic-fixture`.

Use an existing private parent directory outside WordPress. On the origin the
CLI operator is root, allowing access to the separate root-only upgrade key.

```sh
php scripts/upgrade.php begin \
  --session=/private/upgrades/run-001 \
  --wordpress=/var/www/html \
  --target-config=/private/upgrade-target.json \
  --backup-reference=arn:aws:backup:REGION:ACCOUNT:recovery-point:ID \
  --writers-frozen=yes --allow-production-data=yes \
  --policy=/private/migration-policy.json
```

Add `--allow-destructive=yes` only for reviewed migrations that drop columns or
tables. A new session creates a persistent maintenance guard outside WordPress
and reserves the database. It proves that an ordinary WordPress CLI request is
blocked before continuing. The guard does not expire after ten minutes.

For an explicit SQL change, preview one complete statement without executing it:

```sh
php scripts/upgrade.php plan --session=/private/upgrades/run-001 \
  --sql-file=/private/change.sql
```

The full plan is saved privately as `preview.json`. This is a structural preview;
data-dependent constraints are also checked during execution before publication.
Arbitrary plugin PHP migrations cannot be accurately dry-run as SQL alone.

Run specific commands through the same session:

```sh
php scripts/upgrade.php exec --session=/private/upgrades/run-001 -- \
  plugin update ai --version=1.3.0
php scripts/upgrade.php exec --session=/private/upgrades/run-001 -- \
  core update --version=7.1
php scripts/upgrade.php exec --session=/private/upgrades/run-001 -- core update-db
php scripts/upgrade.php exec --session=/private/upgrades/run-001 -- \
  eval-file /private/reviewed-migration.php
php scripts/upgrade.php verify --session=/private/upgrades/run-001
php scripts/upgrade.php finish --session=/private/upgrades/run-001
```

The example versions illustrate pinning, not an instruction to downgrade.
Plugin updates are limited to one plugin from the session's active inventory.
The installed version must equal the requested version. Before package changes,
the current public core/plugin code is archived privately with its version and
checksum. These temporary archives are removed on successful finish and retained
on failure. Site-owned themes/MU plugins continue using their own Git release path.

Use `--wp-cli=/path/to/wp-cli.phar` when WP-CLI is not on PATH. The runner controls
the WordPress path/bootstrap and rejects targeting overrides. Provider HTTP and
mail are paused in the upgrade process; HTTPS WordPress.org package/API access is
allowed. Nothing enables unattended updates.

Verification checks every saved schema fingerprint and requires a second core
`dbDelta()` inspection to propose no changes. Loading WordPress also executes
the installed plugins' normal upgrade hooks inside the controlled context.
Perform the plugin-specific checks and site smoke tests before `finish`.

## Failure and recovery

Any database/schema failure is sticky: even if a plugin catches the exception,
later queries cannot advance version markers in that process. The command exits
nonzero and the site stays guarded. The session's JSON state is authoritative;
private error files preserve historical failure detail.

```sh
php scripts/upgrade.php status --session=/private/upgrades/run-001
php scripts/upgrade.php recover --session=/private/upgrades/run-001
# Re-run the interrupted idempotent migration command, then verify and finish.
```

Recovery completes pending schema work; it does not blindly replay arbitrary
application DML. Run recovery on the original host/path, after the prior process
has stopped. Filesystem locks reject concurrent controllers/engines; the database
reservation rejects a different session. An incompatible data change can be
cancelled while the original table is still authoritative:

```sh
php scripts/upgrade.php cancel --session=/private/upgrades/run-001
```

Cancel removes only a known disposable replacement. Once publication has begun,
use recovery or the verified database backup. Some plugins write version markers
before doing any DDL or perform non-idempotent data migrations; the runner cannot
undo those arbitrary writes. Keep the site guarded, inspect the journal, and use
the native backup plus saved code if continuing is unsafe. Restoring a backup
to another cluster requires explicit connection/IAM changes and fresh validation.

## Tested boundaries

- Real populated DSQL rebuilds, defaults, positioning, column/table renames,
  index changes, drops, binary-text values and identity high-water preservation.
- Interruptions after CREATE, during copying, after verification, after each
  publication rename/move, and after catalog publication.
- Duplicate unique keys, varchar truncation, indexed encoded NULs, malformed SQL,
  unique prefixes and protected schemas fail without publishing corrupt data.
- WordPress's actual `core update-db`/`dbDelta()` and AI's actual schema upgrade
  recover from interrupted older synthetic schemas and converge.
- Pinned AI 1.2.0 → 1.3.0 package update and WordPress 7.1 package/update-db path.

Unsupported cases remain explicit failures: schema DDL inside an application
transaction, populated rebuilds without a primary key, CHECK/foreign-key or
generated-column upgrades, collation conversion, and arbitrary/lossy type
conversions. The initial migration's broader MySQL collation/unsigned limitations
are not removed by this milestone. New NOT NULL columns in populated tables
need an explicit default or a reviewed backfill. Automatic updates remain paused.
