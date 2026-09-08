# WordPress SQLite architecture review

Reviewed 8 September 2026 against upstream trunk commit
[`bf181a286470be4de550112ce1f1d05322502c0e`](https://github.com/WordPress/sqlite-database-integration/tree/bf181a286470be4de550112ce1f1d05322502c0e)
and DSQL adapter `2486f6b` (0.6.0). This is an architecture assessment, not a
runtime migration. No staging or production connections were used.

## Decision

WordPress's SQLite project would have been the better starting reference for
WordPress/MySQL compatibility. Adopt more of its architecture and selected
compatibility code and tests. Do not replace the current adapter with a wholesale
SQLite fork or attempt to change only its connection class.

The DSQL fork has already removed the PG4WP driver and regex rewriters. Its
runtime extends installed WordPress `wpdb`, uses AWS PDO, and now vendors the
standalone WordPress parser. Repository ancestry is no longer the principal
constraint. The remaining work is improving the compatibility model and separating
WordPress adaptation from database execution.

## What upstream actually contains

The current runtime is organized as follows:

```text
WordPress
  → plugin db.php bootstrap
  → WP_SQLite_DB (wpdb adapter)
  → WP_MySQL_On_SQLite / WP_MySQL_On_SQLite_Statement (MySQL-facing PDO API)
      → MySQL lexer + grammar tree
      → statement execution / expression translation
      → information-schema builder and reconstructor
      → WP_SQLite_Connection + configurator + PHP-defined SQL functions
  → PDO SQLite
```

The database library can run without WordPress. `WP_SQLite_Driver` is now a
**deprecated compatibility wrapper**, not the class to build a new adapter on.
The experimental `mysql-proxy` is a separate MySQL wire-protocol service using a
PDO-like adapter; it is unnecessary for the current WordPress drop-in.

An important distinction: **mysql-on-sqlite still uses the older recursive parser**
under `src/mysql`, optionally accelerated by Rust. It does not use the standalone
Bison/LALR `packages/mysql-parser` we adopted. Its tree walkers expect that older
grammar's camelCase rules and token IDs. Borrowing translator code requires a
semantic adaptation even though both parsers originate in the same repository.

Sources: [loader](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/load.php),
[wpdb adapter](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/plugin-sqlite-database-integration/wp-includes/sqlite/class-wp-sqlite-db.php),
[legacy wrapper](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-sqlite-driver.php).

## What to adopt

| Area | Upstream design | Current DSQL position | Recommendation |
|---|---|---|---|
| WordPress boundary | `wpdb` maps to a standalone driver and statement/result API | `DSQL_WPDB` also owns AWS connection setup, retries, SQL execution, metadata dispatch and upgrade integration | Extract the MySQL-facing engine and connection/session executor; leave WordPress hooks, escaping and result properties in a small shim |
| Logical schema | A MySQL information-schema model retains types, defaults, ordering, indexes, constraints and table options | Restored tables have saved MySQL metadata; newly installed tables use partial PostgreSQL reconstruction | Adopt one logical schema model for installation, restore and upgrades |
| DDL | Parser nodes update logical metadata; physical DDL is generated from that metadata | Grammar validation is followed by a second, hand-written bounded DDL parser | Derive supported schema operations from the standalone AST and feed our existing controlled planner |
| Introspection | `SHOW`, `DESCRIBE`, `INFORMATION_SCHEMA` and reconstructed MySQL DDL use the logical catalog | Regex dispatch covers a small set of core/plugin queries; no general MySQL `INFORMATION_SCHEMA` or `SHOW CREATE TABLE` path | Add AST-based metadata dispatch and supported catalog projections; preserve one source of truth |
| Result metadata | MySQL type/flag/length information is synthesized above native SQLite result metadata | `load_col_info()` exposes only name and PostgreSQL native type | Adapt result metadata mapping and relevant `wpdb` contracts |
| Column contracts | `get_col_charset()` and `get_col_length()` reuse core logic through emulated `SHOW FULL COLUMNS` | Charset is hardcoded; length always returns false | Replace shortcuts after the logical catalog is available |
| Tests | WordPress core integration, SQL behavior, metadata, PDO, concurrency and translation suites | Strong synthetic site/recovery checks, but a much narrower semantic and WordPress API matrix | Port applicable behavioral expectations before replacing implementation |
| Execution and caching | SQLite-specific statement execution; repeated queries are reparsed | Value-free cached IR, native PDO bindings, DSQL-specific transaction/retry behavior | Keep our implementation and make the engine boundary clearer |

The highest-value idea is the **logical MySQL schema as the common input** to
physical DDL, introspection, type decisions and result metadata. This avoids
reconstructing distinctions such as `longtext` versus `text`, `tinyint` versus
`integer`, unsigned declarations and index-prefix metadata from PostgreSQL alone.
It also addresses the split that contributed to the recent installation metadata
cache bug. It does not mean claiming that DSQL enforces every recorded MySQL
collation or constraint: logical declarations and actual physical enforcement
must remain distinguishable.

Upstream's schema builder is not directly portable: it is typed against
`WP_SQLite_Connection`, writes SQLite-specific catalog tables, and uses the older
AST. Borrow its MySQL field definitions, extraction rules and tests; implement
DSQL persistence and publication through our catalog and upgrade journal.

Sources: [schema builder](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-sqlite-information-schema-builder.php),
[result statement](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-mysql-on-sqlite-statement.php),
[metadata tests](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/tests/WP_MySQL_On_SQLite_Metadata_Tests.php),
[WordPress test workflow](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/.github/workflows/wp-tests-phpunit.yml).

## Why a whole-driver port is not a connection swap

`WP_MySQL_On_SQLite` is an approximately 8,000-line class combining the public PDO
surface, private translation methods, session state and execution. Its connection
and schema-builder dependencies are concrete SQLite classes. There is no public
backend interface under which an Aurora connector can simply be substituted.

Several implementation choices require a different DSQL design:

- MySQL functions are often PHP callbacks registered inside SQLite. A remote DSQL
  server cannot call those PHP closures; each function needs a suitable DSQL SQL
  translation or an explicit unsupported result.
- Limited/joined writes use SQLite `rowid`; DSQL needs actual keys.
- Upserts use SQLite's targetless `ON CONFLICT DO UPDATE`; our DSQL path resolves
  a supported unique conflict target. That distinction cannot be erased safely.
- The engine uses `BEGIN IMMEDIATE`, wrapper savepoints, transactional DDL, and
  `PRAGMA` settings. Its table reconstruction creates, copies, drops, renames and
  rebuilds indexes inside the SQLite transaction model.
- DSQL allows only one DDL statement per transaction and separates DDL from DML.
  Keep the controlled upgrade state machine, writer exclusion, verified copies,
  retained originals, catalog fingerprints and restart recovery.
- MySQL `ON UPDATE` behavior can use SQLite triggers. DSQL requires an execution
  strategy appropriate to its supported features.
- Embedded SQLite tolerates many small internal queries. Porting each one to a
  remote network request would add latency and database work.
- The public PDO `prepare()` currently reports `IM001` (unsupported). Replacing
  our compiler/executor must preserve current native parameter binding and
  value-free plan caching. `wpdb::prepare()` still returns a string in both
  adapters; these are different APIs.

Sources: [driver](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-mysql-on-sqlite.php),
[PHP SQL functions](https://github.com/WordPress/sqlite-database-integration/blob/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-on-sqlite/src/sqlite/class-wp-sqlite-pdo-user-defined-functions.php),
[AWS DDL transaction rules](https://docs.aws.amazon.com/aurora-dsql/latest/userguide/working-with-ddl.html).

## Local probes and their limits

Ran the pinned upstream driver using PHP 8.5.10 and in-memory SQLite, with
`PDO::ATTR_STRINGIFY_FETCHES=true`, matching its WordPress adapter configuration.
The reproducible probe is:

```sh
php tests/parser-evaluation/sqlite-architecture.php /path/to/sqlite-database-integration
```

Observed:

- Filtered `SHOW FULL COLUMNS`, filtered `SHOW INDEX`, a projected and ordered
  `INFORMATION_SCHEMA.COLUMNS` query, and `SHOW CREATE TABLE` worked.
- `ALTER TABLE ... ADD COLUMN ... AFTER ...` updated both the table and exposed
  metadata. Savepoint begin/rollback also worked in SQLite.
- Result metadata included MySQL native types and mysqli-compatible fields.
- The example INSERT executed 5 underlying SQLite statements, its upsert 7,
  CREATE 21, and ALTER 21. These counts include internal work; they are not a
  DSQL benchmark or a claim that every query has that cost.
- PDO `prepare('SELECT ?')` failed with `IM001`, as documented in the source.
- An upsert update reported one affected row, so adopting this implementation
  would not by itself resolve our documented MySQL/DSQL affected-row difference.
- A unique prefix index retained `Sub_part=12` in metadata, but inserting
  `sharedprefix-A` and `sharedprefix-B` succeeded. The SQLite index generator
  indexes the whole column, rather than its 12-character prefix. This is a
  concrete reason to validate physical semantics independently of metadata and
  retain our explicit prefix-index handling.

An additional exploratory run without stringify-fetches exposed a dependency on
internal string-valued metadata: the example UNIQUE index became non-unique.
That was not the WordPress configuration, and is not presented as a failure of
its WordPress integration. It reinforces that these classes should not be treated
as an already verified, interchangeable database backend abstraction.

This review did not run their entire test suite or port it to DSQL. Their SQLite
results are a useful compatibility reference, not a substitute for MySQL as the
behavioral reference. Exact SQLite-emitted SQL tests need DSQL-specific expected
output; SQLite locking/file/PRAGMA tests are not reusable unchanged.

## Proposed sequence

1. **Establish compatibility contracts and extract the engine.** Adapt selected
   upstream WordPress/metadata/result tests. Move execution, session state and
   connection ownership behind a standalone MySQL-on-DSQL API while preserving
   the existing compiler, renderer, cache, diagnostics and upgrade behavior.
   Do not implement the entire PDO surface merely to resemble upstream.
2. **Unify logical schema and DDL handling.** Use the standalone AST to produce
   typed schema operations. Evolve the saved catalog through an explicit versioned
   migration; preserve the existing catalog/fingerprints until verified. Use the
   same model for new installs, restores, `SHOW`/`DESCRIBE`, selected
   `INFORMATION_SCHEMA` queries and result metadata. Physical changes still run
   through the controlled DSQL upgrade engine.
3. **Port compatibility features incrementally.** Use upstream tests and MySQL
   differential checks to prioritize expressions, SQL modes, result contracts and
   query forms. Keep declared DSQL limitations explicit. Compare cold/warm CPU,
   database round trips and request timings so a broader compatibility layer does
   not introduce SQLite-style network fan-out.

Acceptance should include the current installation, binding/cache, write-effect,
HTTP, external-writer and upgrade recovery suites, plus new metadata round trips
and selected WordPress core `wpdb` tests. Broadening schema syntax must not silently
broaden which live DDL operations the controlled runner is authorized to perform.
