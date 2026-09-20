# Automatic schema upgrades

Version 0.12 can execute common WordPress schema changes during ordinary requests,
including plugin activation and background updates. This opt-in companion to the
native engine reuses the PHP schema parser/planner for infrequent DDL. Ordinary
queries, connections and SQL-error logging remain Rust.

## Policy

Each statement is parsed and planned before mutation. Supported operations are:

- New catalogued tables and identical repeated CREATE/ADD definitions.
- Added nullable columns and columns with supported constant/timestamp defaults.
  Required columns on populated tables need defaults; backfills need a primary key.
- Default changes, column/table renames, added indexes, dropped nonunique indexes.
- Safe logical widening preserving the physical type, supported UTF-8 metadata
  changes, and relaxed nullability.

Destructive table/column drops, primary-key replacement, removal of uniqueness,
physical type conversions/narrowing, column repositioning, added identities, and
requiredness changes on existing columns need the controlled runner. Other
unsupported SQL fails individually. New required columns use validated
adapter-owned CHECKs; DSQL does not support adding NOT NULL to existing columns.
Backfill batches update only NULLs, preserving concurrent values.

After a schema error, further writes in that request stop, preventing immediate
schema-version bookkeeping. Reads and ROLLBACK remain available. Earlier successful
statements and installed PHP files may remain; this is not a transaction around
arbitrary plugin code. WordPress retains its normal plugin-file rollback behavior.

## Setup

Use a verified logical catalog and the `wp_upgrader` role from
[controlled upgrades](controlled-upgrades.md), owning application tables,
`__wp_dsql_schema` and `__wp_dsql_upgrade_lock`. Ordinary queries continue as
`wp_runtime`. Map the runtime IAM principal to the owner role for the separate
schema connection:

```sh
php scripts/configure-automatic-schema.php \
  --target-config=/private/admin-target.json \
  --iam-principal=arn:aws:iam::123456789012:role/wordpress-runtime
```

This explicitly grants schema-owner access to that IAM principal; WordPress does
not get an admin connection. Reverse it with `--revoke=yes`. An admin auth token
can instead be piped to `--token-stdin=yes`; never put tokens in arguments/files.
The target contains endpoint, region, profile and schema, not key material.

Create a writable private state directory **outside the document root**, shared
by PHP-FPM and authorized WP-CLI processes. Use a setgid group directory (2770)
and pre-create `access.lock` with mode 0660. Preserve it across deployments.
Every process for one site must use the same directory. Independent multiple-host
installations without shared flock semantics are not supported by this mode.

```php
define('DSQL_ENGINE', 'native');
define('DSQL_AUTOMATIC_SCHEMA', true);
define('DSQL_SCHEMA_USER', 'wp_upgrader');
define('DSQL_SCHEMA_STATE_DIRECTORY', '/var/lib/wordpress/automatic-schema');
```

Install the matching 0.12 extension and Composer dependencies. Remove blanket
`DISALLOW_FILE_MODS` and `AUTOMATIC_UPDATER_DISABLED` restrictions. Use
`WP_AUTO_UPDATE_CORE='minor'` for minor core updates and retain normal per-plugin
choices. `DISALLOW_FILE_EDIT=true` can disable inline editors without blocking
updates. Filesystem permissions and other plugins can independently restrict them.

## Coordination and recovery

Requests take shared local schema leases. DDL drains them, takes an exclusive
lease, and reserves the database upgrade-lock row. A 20-second lock timeout is
retryable. WordPress HTTP transports suspend shared leases unless an application
transaction is active, allowing updater loopbacks to migrate new plugin code.
The next database operation reacquires the lease with fresh schema caches.

Fsynced journals precede mutation. Column backfills and asynchronous index/check
validation resume after interruption. Known failures reverse reversible steps
where safe; uncertain transport outcomes preserve the journal. Populated new
tables or changed new-column values prevent destructive rollback. The next
request attempts recovery before normal database work.

Recovery without WordPress bootstrap:

```sh
php scripts/automatic-schema.php status --target-config=/private/schema-target.json
php scripts/automatic-schema.php recover --target-config=/private/schema-target.json
php scripts/automatic-schema.php rollback --target-config=/private/schema-target.json
```

That nonsecret config contains `endpoint`, `region`, `schema`, `user` (runtime),
`schema_user`, `state_directory`, `table_prefix`, and a provider `profile` and/or
`credentials_file` reference. Do not manually delete pending journals or lock rows.
Recent errors appear to administrators and use existing SQL-error logging.
Journals contain schema/default metadata and must remain private.

For a controlled rebuild, finish automatic recovery first, pause writers and use
the existing runner. It recognizes validated adapter-owned not-null CHECKs and
replaces them with physical NOT NULL on rebuilt tables.

## Verification

`tests/automaticSchemaTest.php` covers policy. `tests/automatic/` covers live DSQL
operations, seven crash windows, rollback, duplicate-index preflight, transaction
ownership, lazy connections, cross-process leases, and WordPress installation and
background plugin updates with dbDelta and preserved rows. Live tests require the
separately tagged synthetic fixture, never an arbitrary production endpoint.
