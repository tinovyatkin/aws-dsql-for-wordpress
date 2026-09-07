# DSQL WordPress verification — 7 September 2026

## Environment

- WordPress 7.1 (stock core; checksums verified).
- PHP 8.5.10; native PDO PostgreSQL.
- AWS Aurora DSQL in eu-central-1, PostgreSQL 16 compatibility.
- AWS PHP connector 0.1.1; AWS Node.js connector 0.1.9.
- Twenty Twenty-Five theme; synthetic content and accounts only.
- No production site or production data used.

## Passed checks

Clean bootstrap: all 12 WordPress tables and their indexes created; zero database errors.

WordPress API integration: 24 checks; 0 database errors.

- WordPress uses AWS DSQL PDO drop-in.
- Core installation is persistent.
- Administrator authentication.
- Serialized option and UTF-8 round trip.
- Option update.
- Option upsert updates the unique option name.
- Create draft and return identity.
- Article text survives uncached database read.
- Serialized post metadata round trip.
- Create taxonomy term.
- Assign taxonomy relationship.
- Publish draft.
- Search and pagination FOUND_ROWS.
- Insert native comment and return identity.
- Native comment text round trip.
- Comment count maintained by WordPress.
- REST API reads published post.
- REST API creates editor draft.
- REST API publishes editor draft.
- REST API permanently deletes synthetic post.
- Delete option.
- Trash post.
- Restore post.
- No database errors during WordPress API tests.

Independent compute: a standalone Node.js process inserts a native WordPress comment
and updates its post comment count in one DSQL transaction. A fresh WordPress
process reads the exact text and count. The same comment is visible in the browser.

HTTP checks: login and session cookie, dashboard, posts list, editor, comments
screen, plugins screen, public article containing the external comment; no new
database errors or PHP fatal errors during these requests.

Inherited PG4WP unit suite: **33 tests, 545 assertions, all passing**.

## Remaining limitation observed

A read-only `dbDelta(wp_get_db_schema(), false)` comparison reported 54 proposed
schema changes, with 0 query errors. Most are type-representation differences
between MySQL and PostgreSQL. This is a real compatibility gap; schema-upgrade
idempotence is not claimed. The proposed alterations were not applied.

This verifies a working proof of concept, not a production migration, full plugin
compatibility, performance under load, or database-failure recovery.

## Reproduction

See the root README and `scripts/bootstrap-local.sh`. Runtime configuration,
credentials, WordPress files, dependency directories, and detailed test output
remain outside Git under ignored paths.
