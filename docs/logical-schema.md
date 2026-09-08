# Shared logical MySQL schema

Adapter 0.8 implements the second milestone from the
[SQLite architecture review](sqlite-architecture-review.md). The same logical
model now serves fresh installations, restored metadata, supported schema changes,
introspection and WordPress column/result metadata. The standalone parser and
existing DSQL execution, binding/cache and controlled-recovery paths remain.

## Architecture

```text
MySQL DDL → standalone parser AST → supported schema operations
                                         ↓
MySQL backup metadata ────────────→ versioned logical model
                                         ↓
                    ┌────────────────────┼───────────────────┐
                    ↓                    ↓                   ↓
             DSQL physical plan    SHOW / supported    WordPress column
             and controlled        INFORMATION_SCHEMA  limits, charsets,
             publication           / SHOW CREATE       result metadata
```

`schema/Ddl.php` walks named grammar nodes for table definitions, column
attributes, index parts and ALTER actions. It replaces the separate DDL tokenizer
and hand-written statement parser. Parsing a valid MySQL statement does not grant
permission to execute it: the operation whitelist and physical DSQL planner still
reject unsupported types, constraints, expressions, index semantics and changes.

`schema/Model.php` retains MySQL types such as `longtext`, `tinyint` and unsigned
integer declarations; column order, nullability, defaults and default-expression
flags; comments, charsets/collations; and index definitions/prefixes. It also stores
table options and reconstructs MySQL CREATE TABLE statements. Column key flags
follow primary-key precedence and leading-column index semantics, informed by
WordPress's [information-schema builder](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-sqlite-information-schema-builder.php).

Literal text is distinguished from structure: a comment containing `CHECK (...)`
is not treated as a CHECK constraint. SQL modes apply while parsing source DDL
literals. Canonical stored DDL uses ordinary MySQL escaping.

The logical declaration is not a claim of complete MySQL execution semantics.
DSQL still uses its own physical types and collation. Actual unique indexes and
physical metadata remain authoritative for conflict targets and binding guards.
Optional omitted indexes remain absent from runtime SHOW/INFORMATION_SCHEMA
results; original definitions remain retained for controlled planning.

## Catalog lifecycle and compatibility

The existing `__wp_dsql_schema` table layout and physical-fingerprint algorithm
are unchanged. Version 2 lives in the metadata JSON. A reserved `__catalog__`
control row records the format and whether the catalog is managed. It is excluded
from application table enumeration and upgrade table counts.

- Existing catalogs without a control row remain **managed**. Their DDL protection
  is not relaxed. Legacy table rows normalize in memory without any write.
- New restores write version-2 metadata and a managed control record.
- New installation-only catalogs explicitly record unmanaged status, preserving
  the existing CREATE/DROP installation/test behavior. This does not add automatic
  ALTER support. Restored schemas still require the controlled runner.
- Unknown versions fail explicitly when catalog metadata/control records are read.
- New table definitions are recorded as pending before physical creation. They
  become readable logical metadata only after all physical statements and index
  jobs complete. A failed installation cannot publish inferred metadata instead.
  Pending entries block application statements under the configured table prefix.
- An interrupted new installation stays pending for inspection/reset; this release
  does not guess ownership or automatically adopt a partly created table. An
  explicit DROP in the unmanaged setup path removes its pending record. The
  synthetic reset helper permits its own installation catalog but refuses managed
  catalogs or another installation's tables.

Tables from older native installations that have no saved MySQL definition still
use a marked, inferred PostgreSQL model. Lost distinctions such as original
`longtext` versus `text` cannot be recovered from PostgreSQL alone. Such tables
are not silently enrolled from a requested `CREATE IF NOT EXISTS` definition.
WordPress retains its previous unbounded string-length behavior for inferred
tables, rather than imposing a guessed TEXT limit on a former LONGTEXT column.

## Explicit catalog migration

Use the normal controlled-session setup: correct upgrade role, writer exclusion,
verified backup reference and private session directory. With that session active:

```sh
php scripts/upgrade.php catalog-migrate --session=/private/upgrade-session
php scripts/upgrade.php verify --session=/private/upgrade-session
php scripts/upgrade.php finish --session=/private/upgrade-session
```

The command validates each table's existing physical fingerprint, durably saves
its original JSON/fingerprint to the session's private `catalog-v1-*.json` journal,
and conditionally updates only its metadata. Physical tables, rows and fingerprints
are unchanged. Version-2 rows are skipped, making a repeat idempotent. A changed
legacy row or fingerprint is not overwritten.

If interrupted:

```sh
php scripts/upgrade.php recover --session=/private/upgrade-session
php scripts/upgrade.php verify --session=/private/upgrade-session
php scripts/upgrade.php finish --session=/private/upgrade-session
```

A pending migration prevents verification/finish. `status` exposes
`catalog_migration_pending`. Recovery resumes metadata conversion under the same
guard and journal. Preserve the session journal and normal database recovery point;
there is no automatic down-migration command. Use the matching release's controlled
upgrade tools with the version-2 control record.

## Metadata query support

AST dispatch replaces the previous schema-related regex shortcuts. Supported:

- SHOW [FULL] COLUMNS / FIELDS and DESCRIBE;
- SHOW INDEX / INDEXES / KEYS;
- SHOW [FULL] TABLES and SHOW CREATE TABLE;
- LIKE and bounded WHERE filtering on SHOW results;
- single-table SELECT projections, aliases, COUNT(*), WHERE, ORDER BY source
  metadata columns, and literal LIMIT/OFFSET for INFORMATION_SCHEMA.TABLES,
  COLUMNS, STATISTICS and SCHEMATA.

The INFORMATION_SCHEMA database name is the engine's configured database name
(`postgres` in the usual DSQL configuration); DATABASE() in metadata predicates
resolves to it. The physical application schema remains separately configured as
`public` or `wp_live`. Internal catalog tables are not advertised as application
tables. A table-name equality predicate avoids loading every table's metadata.

Metadata predicates support comparisons, AND/OR/NOT, LIKE, IS NULL and IS NOT
NULL, with SQL null/truth behavior. This is deliberately a bounded metadata
interpreter: joins, grouping, unions, subqueries, arbitrary functions, duplicate
output labels and unsupported clauses fail instead of being ignored. It is not
an implementation of every MySQL collation or coercion rule.

DESCRIBE of a missing table returns an empty result for dbDelta's creation probe,
retaining the adapter's prior behavior without poisoning a controlled session.
Pending catalog entries still fail; they are not reported as missing tables.

## WordPress and result metadata

`get_col_charset()` and `get_col_length()` now reuse WordPress core logic through
logical SHOW FULL COLUMNS results. They distinguish string/byte limits and
non-text columns. WordPress's metadata caches clear after schema operations.

`wpdb::get_col_info()` requests logical descriptors through
`Driver::columnMeta(Result, offset)`: supported MySQL types, lengths, key flags,
and original names for direct column aliases come from the same model. This work
runs only when column metadata is requested. Ordinary result fetching does not
perform these catalog lookups. Buffered native result metadata remains available;
computed/unresolvable projections keep native descriptors. Full MySQL client
protocol emulation remains outside this release.

## Validation

The implementation was checked with:

- 45 PHPUnit tests / 171 assertions, including AST/model round trips, default and
  comment preservation, unsupported grammar branches, version compatibility,
  catalog protection, pending-state checks and logical result descriptors;
- 19 live synthetic schema/introspection checks, including cross-connection
  persistence and failure before installation publication;
- 12 read-only restored WordPress column/result metadata checks;
- five live catalog-migration checks: read-only legacy normalization, interruption,
  durable journal, recovery without fingerprint changes, and idempotent repeat;
- the existing installation, application, translation/cache, write-effect,
  diagnostics and controlled-upgrade recovery suites, including seven HTTP checks
  and the external Node.js writer/read-back checks with no new HTTP-run SQL errors
  or PHP fatal errors.

Run the focused native checks only against the configured synthetic fixtures:

```sh
PGSSLROOTCERT=system php tests/schema/standalone.php
PGSSLROOTCERT=system php tests/schema/wordpress.php
python3 tests/upgrade/catalog.py
```

A new installation completed with zero installation errors and a fresh-process
core dbDelta check reported no pending changes. No production or staging catalog
was migrated during implementation.

Deploy the complete release, including `schema/`, `engine/`, parser, updated
Composer autoload, migration and upgrade code. Standalone backup planning now also
needs the parser facade and pinned WordPress parser files; it still does not need
the AWS SDK when run from the minimal MySQL backup bundle.
