# WordPress core SQL audit

Source audit performed on 8 September 2026 against adapter `96c24bc` (runtime
0.8.1). This audit changes no runtime behavior and makes no database connections.

## Source and scope

- Released WordPress 7.1: [WordPress/WordPress, b998fef](https://github.com/WordPress/WordPress/tree/b998fef9238af183f9523b3df71618e6e57498b6).
- Upcoming maintenance-branch snapshot: [wordpress-develop, 9fced37](https://github.com/WordPress/wordpress-develop/tree/9fced3752a800d7c291fdd7430b557d39dc8beeb),
  identifying itself as `7.1.1-alpha-63326-src`. This is not a released upgrade candidate.
- Both report database revision 61833.

The released checkout contains 1,315 PHP files outside `wp-content`, with **897
direct `$wpdb` call sites in 81 files**. These include 333 `prepare()` calls,
some nested inside execution calls; this is not a count of unique SQL statements.
There are 2,649 SQL-keyword string tokens, including fragments and prose false
positives. The inventory uses PHP tokens and excludes comments, rather than
treating every textual mention of SQL as executable code.

The maintenance snapshot has the same 897 direct call expressions, compared by
file, method and expression rather than line number. The source files for
WP_Query, WP_Meta_Query, WP_Date_Query, WP_Comment_Query, WP_User_Query,
WP_Term_Query, WP_Site_Query, WP_Network_Query, WP_Tax_Query, wpdb, core schema
definitions and core upgrade routines are also byte-identical between these
two pinned snapshots. This says nothing about future commits on that branch.

Review covered direct calls, dynamic query-builder branches, wpdb's internal
statement builders, schema definitions, old upgrade paths, admin diagnostics,
repair tools, and multisite entry points. `wp-content` and external plugins are
outside scope. WordPress was cloned into ignored `.local` directories; no core
checkout, user data or credentials are committed here.

## Concrete findings

### 1. Site Health metadata needs explicit support

- [SHOW VARIABLES](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/includes/class-wp-debug-data.php#L1827)
  supplies `max_allowed_packet` and `max_connections` in database debug information.
  The metadata dispatcher rejects `show_variables_stmt`.
- [SHOW TABLE STATUS](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/includes/class-wp-debug-data.php#L1920)
  is used for database size. The dispatcher rejects `show_table_status_stmt`.
- [The persistent-cache recommendation](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/includes/class-wp-site-health.php#L3811)
  selects TABLE_ROWS and SUM(DATA_LENGTH + INDEX_LENGTH), grouped by TABLE_NAME.
  Grouping is rejected and these numeric fields are absent from the logical
  TABLES model. The ordinary test returns before this query when an external
  object cache is active (line 2595); thresholds and a filter can also bypass it.

These are concrete core cases to prioritize ahead of arbitrary metadata joins.
Support must define honest DSQL equivalents or a deliberate unavailable result;
inventing MySQL server limits or reporting unknown sizes as measured zero is not
an adequate implementation.

The different [core update-check query](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-includes/update.php#L120)
uses a simple TABLE_NAME IN predicate and is already covered by the 0.8.1 fix.

### 2. Optional core date filters are incomplete

[WP_Date_Query](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-includes/class-wp-date-query.php#L753)
can emit DAYOFYEAR, DAYOFWEEK and WEEKDAY. [_wp_mysql_week](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-includes/functions.php#L7183)
emits WEEK(date, mode), including shifted dates for other week starts. All four
functions are rejected by the compiler. Ordinary year/month filters translate.

This affects callers asking for week/day-of-week/day-of-year filters, including
themes or plugins using core's public API. It is not evidence that every normal
archive is broken. Implementing these functions needs MySQL-compatible week
numbering, year boundaries and weekday offsets, not just matching function names.

### 3. Core metadata-query options have binary and semantic gaps

[WP_Meta_Query](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-includes/class-wp-meta-query.php#L681)
supports BINARY casts for case-sensitive key regexes and value comparisons.
The compiler rejects CAST AS BINARY. Signed and decimal casts translate in the
probe harness, but that alone does not prove identical MySQL coercion behavior.

Ordinary REGEXP/RLIKE predicates compile to PostgreSQL `~`/`!~`. This does not
preserve MySQL case-insensitive regex semantics under its usual nonbinary
collations, nor every regex dialect feature. Core uses such queries in
WP_Meta_Query, XML-RPC pingback title matching and user-role filtering. This is
separate from ordinary article search's LIKE-to-ILIKE conversion. Collation
emulation remains deferred; accepted translation must not be called semantic
equivalence.

### 4. Repair and historic upgrade paths need separate treatment

The explicitly enabled [database repair screen](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/maint/repair.php#L111)
uses CHECK TABLE, REPAIR TABLE and OPTIMIZE TABLE. These are rejected. They need
a DSQL-specific operational response, not an assertion that a MySQL repair ran.

Older upgrades include [duplicate-option cleanup with a self-join DELETE](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/includes/upgrade.php#L3736)
and [charset conversion](https://github.com/WordPress/WordPress/blob/b998fef9238af183f9523b3df71618e6e57498b6/wp-admin/includes/upgrade.php#L2826).
Both probe forms are rejected. Their presence in core does not mean a current
7.1 database will execute them during a maintenance upgrade; upgrade version
gates and caller paths must be inspected. Multisite remains outside the adapter's
supported deployment scope.

### 5. Previously proposed bulk-write work is not driven by core

No direct core use of INSERT SELECT or multi-row REPLACE was found in this
inventory or the reviewed statement builders. There are no direct
`$wpdb->replace()` calls in the scanned runtime. The public `wpdb::replace()`
method exists and its shared helper constructs one VALUES row. Core does use
multi-row INSERT VALUES, INSERT IGNORE and ON DUPLICATE KEY UPDATE, including
option writes, upgrade locks and taxonomy ordering. Those forms have existing
adapter support, subject to the documented unique-key and execution limits.

This lowers the priority of INSERT SELECT and multi-row REPLACE for core
compatibility; it does not prove that plugins or dynamically supplied SQL never
use them. Core's normal article search does not require a FULLTEXT index.

## Reproduction

```sh
git clone --depth 1 --branch 7.1 https://github.com/WordPress/WordPress.git .local/wordpress-core-7.1
git -C .local/wordpress-core-7.1 rev-parse HEAD
php scripts/audit-wordpress-sql.php .local/wordpress-core-7.1 .local/core-sql-7.1.json
php tests/core-sql/audit.php > .local/core-sql-probes.json
```

Verify the checkout commit against the pin above. The inventory accepts a
WordPress source root, so another release can be compared without changing it.
It does not bootstrap core or open a database connection.

The curated 26 probes instantiate source templates with synthetic values and
fixed physical metadata. **11 translate and 15 are rejected** at the audited
adapter revision. These are deliberately selected boundary probes, not a random
sample or a percentage estimate of core compatibility. Multiple rejections can
belong to one missing feature. They are observations, not tests requiring the
adapter to keep rejecting those forms. Unexpected harness/programming errors
fail the script rather than becoming compatibility findings.

Accepted output is not executed against either MySQL or DSQL. For example,
WP_Date_Query's fractional DATE_FORMAT comparison translates, but its output
type/coercion still needs a differential execution test. Source scanning and
translation acceptance cannot establish result equivalence, affected-row counts,
index behavior, locking or transaction behavior.

## Next coverage work

Prioritize the concrete Site Health queries and date functions, then binary
meta-query options. Add source-derived tests that compare results and write
effects on disposable MySQL and DSQL fixtures. Exercise real WP_Query,
WP_Meta_Query and WP_Date_Query parameter combinations, administrative routes,
and version-gated upgrade routines. Re-run the inventory and query-builder diff
for each proposed core upgrade.

There is no finite static list of every possible core-generated query: arguments,
meta/taxonomy combinations, nested queries and filters construct SQL dynamically.
The inventory is a traceable starting point, not proof of complete coverage.
