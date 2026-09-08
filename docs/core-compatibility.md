# WordPress core compatibility additions — 0.9.0

This release implements the bounded cases identified by the
[WordPress 7.1 source audit](wordpress-core-sql-audit.md). It does not enable
automatic updates, add general MySQL collation emulation, or deploy itself to an
existing WordPress installation.

## Site Health and metadata

- `SHOW VARIABLES [LIKE ... | WHERE ...]`, including GLOBAL/SESSION scope,
  returns an empty result. MySQL's server variables have no reliable equivalent
  here; core's `get_mysql_var()` consequently returns null. No MySQL limits are
  invented from DSQL defaults or PostgreSQL frontend settings.
- `SHOW TABLE STATUS [FROM database] [LIKE ... | WHERE ...]` returns the logical
  tables, exact current row counts, and an Aurora DSQL engine label. Physical
  sizes, index sizes, allocation and timestamps are null where unavailable.
  A LIKE filter narrows table selection before counting rows.
- INFORMATION_SCHEMA.TABLES exposes TABLE_ROWS, DATA_LENGTH and INDEX_LENGTH.
  Row counts are queried only when referenced; the storage fields are null.
  DSQL rejects `pg_table_size()` and `pg_indexes_size()` on real tables. These
  fields are not approximated with a PostgreSQL page estimate.
- Core's grouped cache-threshold query is supported: GROUP BY the unique
  TABLE_NAME with SUM and addition over metadata fields. SUM of unknown sizes
  remains null. This bounded case does not introduce general grouping, joins,
  HAVING, DISTINCT aggregates, ROLLUP or metadata subqueries.

WordPress's cache recommendation can use the row counts. Its size information
has an existing “Not available” path when a database size cannot be obtained.
Applications using the metadata directly must preserve null as unknown.

## Dates and explicit binary comparisons

- DAYOFYEAR, DAYOFWEEK and WEEKDAY implement MySQL's numbering conventions.
- WEEK accepts a literal mode from 0 through 7; omitted mode means 0. The
  implementation handles different week starts, four-day/first-weekday rules,
  week zero and previous/next week-years. Expressions and their parameters are
  evaluated once through a derived date column. NULL and the reserved zero-date
  representation are handled explicitly.
- Core's numeric DATE_FORMAT time comparison receives numeric conversion
  rather than comparing PostgreSQL text directly with a number.
- Unsized CAST AS BINARY and the unary BINARY operator support byte-sensitive
  comparisons, literal IN lists, BETWEEN and LIKE. UTF-8 characters and trailing
  spaces remain significant. Sized/padded casts, binary IN subqueries and an
  explicit binary LIKE ESCAPE clause still require separate support.
- Core's explicit binary regex forms use case-sensitive PostgreSQL regex on
  the original text. MySQL 8.4 rejects binary-string regex operands, so the
  differential test compares the intended case-sensitive behavior against
  MySQL's `REGEXP_LIKE(..., ..., 'c')` form instead.

This is not a general regex-dialect or collation conversion. Ordinary regex
case behavior and MySQL's Unicode/accent rules remain documented differences;
ordinary article LIKE search still uses ILIKE. Searching or casting inside
encoded NUL payloads is not added by this release.

## Maintenance and historical upgrades

`CHECK TABLE` validates catalog consistency when saved metadata exists and
performs a real table-read probe. A readable table returns status OK; a missing
table returns an error row. This is not a physical-storage integrity scan.

`REPAIR TABLE`, `ANALYZE TABLE` and `OPTIMIZE TABLE` return structured note rows
explaining that MySQL maintenance is not applicable and that no maintenance was
performed. They do not mutate data or falsely report a successful repair.
WordPress's legacy repair UI may display the note as an unsuccessful MySQL
maintenance attempt; recovery of a damaged/missing table remains an operational
restore, not a SQL repair routine. Qualified references to another schema and
unsupported maintenance modifiers remain rejected.

The old duplicate-options self-join DELETE executes as one native DELETE using
a primary-key subquery. It deletes only rows selected by the specified target
alias; unrelated tables and multi-target joined deletes remain restricted.

The controlled upgrade runner recognizes CONVERT TO CHARACTER SET utf8mb4 with
an explicit collation. It permits an idempotent request or UTF-8 widening only
when table and text-column collations retain the same family (for example,
utf8_unicode_ci to utf8mb4_unicode_ci). Physical DSQL storage is already UTF-8:
this updates logical metadata through the existing journal and recovery path.
Changing general_ci to unicode_ci, converting non-UTF-8 data or changing binary
comparison semantics still fails. This preserves the deferred collation boundary.

## Verification

Completed against isolated fixtures:

- 54 PHPUnit tests / 234 assertions, including parameter counts, unsupported
  signatures, metadata honesty, grouping boundaries, safe schema changes and
  maintenance without hidden writes.
- All 27 source-derived probes accepted by the appropriate adapter path using
  synthetic metadata. This is a translation/dispatch check, not execution proof.
- 25 MySQL 8.4 versus DSQL result/write-effect groups matched, including 402 date
  values across each of eight WEEK modes, leap days, NULL/zero dates, real
  WP_Date_Query output, real WP_Meta_Query binary comparisons, Unicode/trailing
  spaces, and duplicate-option deletion.
- DSQL-specific row-count/unknown-size results, missing variables, table
  readability, missing-table results and maintenance notes passed.
- A controlled UTF-8 widening operation used metadata-only publication;
  interruption after catalog publication recovered, physical fingerprints/data
  stayed unchanged, the actual core helper returned successfully, and repeating
  the conversion was idempotent. The session verified and closed.
- Existing WordPress application smoke tests, 20 standalone schema checks,
  12 WordPress column-contract checks, 17 cache checks, 59 parser-boundary checks
  and the controlled DDL parser checks passed.

Reproduce offline checks:

```sh
php tests/tools/phpunit.phar tests/
php tests/core-sql/audit.php --require-supported
```

With the existing isolated local MySQL, synthetic DSQL WordPress and synthetic
upgrade-lab fixtures configured:

```sh
php tests/core-sql/differential.php mysql
PGSSLROOTCERT=system php tests/core-sql/differential.php dsql
python3 tests/core-sql/compare.py
python3 tests/core-sql/upgrade.py
```

These scripts write only named synthetic probe tables and retain their reports
under ignored `.local/`. The upgrade test retains its closed private recovery
journal and archived test table according to the runner's existing behavior.
Deploy the complete adapter release, including the new calendar helper; its
source is included in the translation-cache build fingerprint.
