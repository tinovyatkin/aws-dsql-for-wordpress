# ANTLR translation and caching

Adapter **0.5.0** uses the Oracle/ANTLR MySQL parse tree for ordinary SQL
translation. The old PG4WP regex rewriters have been removed. Metadata requests
continue through the adapter's bounded MySQL catalog emulation. Controlled schema
changes pass ANTLR syntax validation and the existing migration-aware schema
planner; they are not cached as ordinary queries.

## Request flow

1. A lightweight scanner identifies quoted tokens, numbers, identifiers, comments,
   and whitespace. It extracts values and makes a query-shape key. It does not
   perform SQL rewrites. Whitespace and comments remain part of the key because
   they can affect MySQL lexing, including function recognition and `N'...'`.
2. A cache hit retrieves serializable translation instructions. It does not load
   the ANTLR lexer/parser, rebuild a tree, or reuse previous parameter values.
3. On a miss, ANTLR parses a **value-free template**. Large article bodies and
   serialized values are replaced with representative literals before parsing.
   Numeric token classes and digit-leading identifiers remain distinct.
4. A structural compiler translates expression and statement contexts into
   instructions. A renderer resolves current column types and unique indexes,
   transforms applicable values, and emits SQL plus PDO bindings.
5. PDO executes the translated query with current values. Numeric SQL syntax such
   as an ordinal `ORDER BY 1` retains its role; it is not blindly converted into
   a parameter. Numeric text emitted inline is lexically validated.

`wpdb::prepare()` still returns WordPress's normal string. The override records
its shape in a bounded request-local registry. `query()` uses that capture only
when the final SQL, after query filters and placeholder unescaping, matches.
Prepared fragments, composed queries, changed queries, and direct SQL all use the
same general shape-cache path. There is no opaque statement object or SQL marker
inserted into the WordPress API.

The cache contains neither query results nor bound values. WordPress's object
cache and page cache remain separate concerns.

## Cache behavior

The default has two levels:

- An in-process LRU with at most 256 entries and a 4 MiB serialized-plan budget.
  Plans are held as JSON strings rather than retaining hundreds of PHP trees.
- A private filesystem cache under the system temporary directory, scoped by PHP
  user, endpoint, schema, codec configuration, SQL modes, and compiler build
  fingerprint. Each namespace has at most 256 entries, each no larger than
  128 KiB. Persistent entries expire after 24 hours; old namespaces can be removed
  during routine cache cleanup.

Files use JSON, atomic publication, integrity checks, and private permissions.
They never contain PHP-serialized executable objects or parameter values. Invalid,
expired, corrupt, or unavailable entries are misses. Unavailable persistent
storage falls back to the in-process cache. A cache failure does not authorize a
fallback to the removed SQL rewriters.

The prepared-query registry is capped at 64 entries and a 1 MiB estimated budget;
large prepared strings take the general path.

Optional configuration, before WordPress loads:

```php
// Default: enabled. Set false to bypass both cache levels for troubleshooting.
define('DSQL_TRANSLATION_CACHE', true);

// Optional. Create a custom directory for the PHP user with mode 0700.
define('DSQL_TRANSLATION_CACHE_DIR', '/var/cache/wordpress-dsql');

// An empty directory setting disables persistence but keeps the in-process LRU.
// define('DSQL_TRANSLATION_CACHE_DIR', '');
```

Inspect per-instance counters with `$wpdb->translation_cache_stats()`: hits,
misses, disk hits, writes, cache errors, compilations, prepared hits, and whether
persistence is enabled. Counters are not a cross-request analytics service.

Plans do not freeze schema metadata. A new connection or a controlled schema
change refreshes the renderer's metadata. Unique conflict targets, temporal
columns, and the NUL/index contract are checked with current metadata when values
are rendered. Release fingerprints prevent reuse across compiler changes.

## Translation behavior

The implemented path covers the tested WordPress query shapes: SELECTs, joins,
subqueries, predicates, common functions and aggregates, pagination, INSERT
VALUES with explicit or implicit columns, unambiguous upserts, UPDATE, DELETE,
and one-row REPLACE VALUES.

Notable behaviors include:

- Nested function calls and inner/outer LIMIT clauses are translated separately.
- Limited UPDATE and DELETE select primary keys instead of discarding LIMIT.
- Global aggregate counts omit only irrelevant ordering on known table columns.
  Distinct year/month dropdowns use chronological projection ordering when the
  source column is temporal; ordinary and grouped query ordering is preserved.
- Multi-alias deletes on one physical table preserve both requested targets.
  Deletes spanning different physical tables are rejected.
- REPLACE performs conflict-row deletion and insertion atomically. A failed
  insertion rolls back its deletion. An adapter-owned batch can retry a known
  aborted concurrency conflict as a whole; caller-owned transactions remain the
  caller's responsibility.
- Upserts use an actual unambiguous unique key. Multiple possible conflict
  targets are rejected instead of guessing.
- Sequential assignment dependencies are rejected when PostgreSQL would observe
  different values from MySQL.
- Zero dates are transformed only for temporal columns. NUL encoding requires a
  direct assignment to an unindexed TEXT column and the enabled value codec.
- FOUND_ROWS uses connection-local state for the latest SQL_CALC_FOUND_ROWS plan,
  populated with that query's current values on both hits and misses.
- Supported grammar-affecting SQL modes have separate cache namespaces. Mode
  changes clear prepared captures. Escaping honors NO_BACKSLASH_ESCAPES.

A grammar accepting a statement does not mean the DSQL emitter supports it.
Unsupported constructs fail explicitly. Examples include INSERT SELECT/SET,
multi-row REPLACE, ambiguous upserts, cross-schema SQL, JSON-path operators,
full-text expressions, windowed aggregates, optimizer hints/executable comments, custom TRIM forms,
and unimplemented function signatures. MySQL execution semantics, collations,
coercions, unsigned ranges, and explicit-ID sequence handling are not universally
emulated. Controlled imports remain responsible for identity high-water marks.

Two existing backend differences were made explicit in differential tests:
DSQL reports one affected row for an upsert update where MySQL reports two, and
DSQL's collation does not provide MySQL's Unicode LOWER behavior. These are not
cache hits returning stale data.

## Memory and deployment

Cache hits avoid ANTLR initialization entirely. A cold compilation still needs
substantial memory. In WordPress, cold query/schema compilation calls
`wp_raise_memory_limit('dsql_translation')`, using WordPress's configured
`WP_MAX_MEMORY_LIMIT` and its standard context filter. It does not set an unlimited
limit. The full synthetic plugin fixture required the usual 256 MiB ceiling;
standalone tools should be given comparable headroom when needed.

Deploy the complete release, including `parser/mysql/`, `pg4wp/`, `upgrade/`,
`migration/`, scripts, Composer files, and installed dependencies. Native
`mbstring` and `pdo_pgsql` are required. Java is needed only to regenerate the
parser. Verification uses synthetic fixtures; site-specific integration and performance
checks remain necessary.

## Verification and measurement

Verification covers fresh installation, WordPress's API smoke test, the full
synthetic plugin fixture, parser/compiler/cache tests, differential MySQL/DSQL
results and write effects, controlled-upgrade interruption recovery, and schema
changes applied while a reusable INSERT plan exists.

Verified for this release: 16 compiler tests (38 assertions), 17 cache checks,
51 lexer/parser helper checks, 24 WordPress API checks, 33 full-fixture checks,
20 native write/mode guards, 18 differential comparisons with the two documented
backend differences, 27 upgrade recovery checks, three upgrade rejection checks,
and the cached-schema/index check. A fresh installation and seven HTTP checks
also passed, including the independently written Node.js comment. The final HTTP
run produced no new DSQL diagnostics or PHP fatal errors.

On the representative benchmark (PHP 8.5.10 CLI, fixed schema responses, 1,000
changing-value queries), the warm median was **0.043 ms** and p95 **0.051 ms**.
The first translation in a separate process using the persistent cache took
**1.35 ms**, loaded no ANTLR parser, and peaked at **2 MiB** for that benchmark
process. A cold miss took **307.9 ms**. These are isolated translation measurements,
not full WordPress request timings.

Useful commands:

```sh
php tests/tools/phpunit.phar tests/
php tests/translation/cache.php
php tests/mysql-parser/helpers.php
php tests/upgrade/parser.php

# Existing synthetic DSQL fixture only:
PGSSLROOTCERT=system php tests/dsql/smoke.php
PGSSLROOTCERT=system php tests/translation/guards.php

# Configured local MySQL and synthetic DSQL fixtures:
php tests/translation/differential.php mysql
PGSSLROOTCERT=system php tests/translation/differential.php dsql
python3 tests/translation/compare-results.py

# Configured synthetic upgrade lab:
python3 tests/upgrade/recovery.py
python3 tests/upgrade/boundaries.py
python3 tests/translation/schema-cache.py
```

`tests/translation/benchmark.php PRIVATE_CACHE_DIRECTORY` isolates translation
CPU using fixed schema responses and a representative SELECT with a predicate,
IN list, ordering, and LIMIT. Run it twice in separate PHP processes using the
same private directory to compare cold and persistent-cache behavior. Its output
includes first-translation latency, warm percentiles, parser loading, peak
process allocation, and counters. It does not measure network latency or a full
WordPress request.

See [the parser build and syntax tests](antlr-mysql-parser.md) for the underlying
Oracle grammar and ANTLR runtime.
