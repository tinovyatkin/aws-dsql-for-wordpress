# Full-schema migration rehearsal

The 7 September 2026 rehearsal uses the site's complete 61-table MySQL schema,
the exact ten active third-party plugin versions, its repository-owned must-use
plugins, the Olga theme, and an isolated persistent Redis cache. All row data,
accounts, passwords, photographs, and service settings in the demo are synthetic.
No production database connection was switched and no production rows were
copied into the local demo.

## Explicit migration policy

`--policy=/private/policy.json` is supported by `plan`, `restore`, `verify`, and
`inspect-source`. The policy is recorded by SHA-256 in the restore state and
verification report. No exceptions are enabled by default.

```json
{
  "format": "wordpress-dsql-policy-v1",
  "active_schema": "wp_live",
  "archive_tables": ["wp_example_legacy_table"],
  "omit_fulltext_indexes": {"wp_wpai_request_logs": ["ft_search"]},
  "value_codec": true
}
```

For this site, 13 active tables go to `wp_live` and 48 legacy tables go to
`wp_archive`. All source rows are retained and verified. Archived tables retain
their original schema/index definitions as metadata, but their old indexes are
not recreated or offered as active plugin functionality. The application role
gets SELECT-only access to that schema. Reactivating an archived plugin requires
an explicit new migration plan; archived tables are not transparently writable.

The optional AI log FULLTEXT index is deliberately omitted. Its owning plugin
already implements a LIKE search fallback; the adapter reports that full-text
search is unavailable. Log insertion, search, date summaries, mixed-type UNION
filter options, and the admin screen were exercised successfully.

## Binary serialized text

With `DSQL_VALUE_CODEC=frame-v1`, NUL-containing UTF-8 text is stored in a tagged,
checksummed base64 envelope. Strings beginning with the envelope prefix are
escaped too, so literal and nested envelope-looking values round-trip exactly
once. PHP private/protected properties and PHP serialization lengths survive
without deserializing or altering the source backup.

Migration and WordPress writes restrict NUL encoding to unindexed TEXT columns.
Actual index keys are distinguished from DSQL's included index columns. Invalid
UTF-8, NUL-containing indexed values, and unsupported expression-based binary
writes fail instead of being silently changed. Binary BLOB columns use their
separate bytea path.

Independent Node.js writers can use `scripts/value-codec.mjs`. Nonbinary text
remains plain SQL text. The envelope is for opaque serialized values retrieved
by key; SQL LIKE/aggregate functions over the contents of an encoded payload do
not reproduce MySQL binary-string semantics and are not claimed as supported.

## Runtime role

DSQL's `public` schema is administrative. The rehearsal uses `wp_live` for the
application so it can connect through a non-admin database role.

After a verified restore:

```sh
PGSSLROOTCERT=system php scripts/configure-runtime-role.php \
  --target-config=/private/target.json \
  --policy=/private/policy.json \
  --output=/private/runtime.json \
  --role=wp_runtime \
  --iam-principal=arn:aws:iam::ACCOUNT:role/YOUR_RUNTIME_ROLE
```

Use the resulting role/schema configuration in WordPress:

```php
define('DB_DRIVER', 'dsql');
define('DB_NAME', 'postgres');
define('DB_USER', 'wp_runtime');
define('DSQL_SCHEMA', 'wp_live');
define('DSQL_VALUE_CODEC', 'frame-v1');
```

The standard endpoint/AWS credential-provider settings still apply. A production
IAM runtime identity should have scoped DbConnect access, not the administrator
permissions used by the local maintenance profile. The SQL role's archive write
denial was verified against the real synthetic DSQL cluster.

## Rehearsal checks

- All **61 tables and 8,369 synthetic rows** restored with matching per-table
  counts and multiset SHA-256 hashes, including an 8,000-row archive table.
- The **33 application checks** cover restored login and case-insensitive
  username/email lookup, exact plugin versions, Olga, Redis, NUL/object/prefix
  round trips, unchanged core dbDelta, ID allocation, ACF, publishing, REST,
  AI log search/summary/filter behavior, and no unexpected database errors.
- The restricted role can read all 8,000 archived rows and receives a database
  permission error on an attempted archive update.
- An independent Node.js process writes a native comment and binary metadata;
  WordPress reads them after scoped Redis invalidation.
- HTTP checks cover login, dashboard navigation, posts/editor/media/comments,
  plugins/settings, AI logs, Site Health, homepage and article rendering.
- The inherited PG4WP suite passes 33 tests / 545 assertions; migration rejection
  tests and PHP/Node codec checks pass.

The demo uses the original MySQL AUTO_INCREMENT high-water mark as well as
imported IDs, avoiding reuse of previously deleted high IDs.

## Local fixture and limits

The current private fixture is under `.local/full-demo`, with WordPress files in
`.local/full-source` and `.local/full-target`. Tests are in `tests/full-demo`.
The site-management repository supplies schema-only capture and synthetic seed
helpers plus its reviewed table policy. Exact production schema captures and
plugin archives stay ignored; the public fork contains no production rows or
credentials.

External account integrations are unconfigured and provider HTTP calls are
blocked in the local fixture. This validates the database/application paths,
not real Google/Facebook/OpenAI account access, a production-sized load test,
or failover. General MySQL collation equivalence and arbitrary plugin SQL are
not claimed. Automatic ALTER/DROP on restored tables still requires a
migration-aware schema upgrade; it is not enabled by this rehearsal.

The live cutover and rollback boundaries remain those in
[the backup/restore runbook](backup-restore-migration.md). A successful synthetic
rehearsal is not permission to change the production connection.

The adapter also has [structured SQL failure monitoring](sql-error-monitoring.md).
The synthetic diagnostic checks cover actual DSQL failures and translator
rejections, including plugin-suppressed errors, without exporting query values.
HTTP regression checks recognize the structured events as failures too.
