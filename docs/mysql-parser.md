# Standalone MySQL parser

Adapter 0.6 uses the standalone `packages/mysql-parser` from
[WordPress/sqlite-database-integration at bf181a2](https://github.com/WordPress/sqlite-database-integration/tree/bf181a286470be4de550112ce1f1d05322502c0e/packages/mysql-parser).
It is a pure PHP LALR(1) parser with compact ACTION/GOTO tables generated from
MySQL 8.4.10's `sql/sql_yacc.yy`, Bison 3.8.2, and MySQL's token definitions.
It does not use the separate ANTLR-derived recursive parser under
`packages/mysql-on-sqlite/src/mysql`.

## Runtime and API

The pinned sources and upstream license are vendored under `parser/mysql/WordPress`.
Classes use the private `WPDSQL\MySQL\WordPress` namespace to avoid collisions with
WordPress's SQLite adapter. Composer loads the adapter facade; the parser itself
loads lazily on a cache miss. No native parser extension is required.

```php
require 'vendor/autoload.php';

$query = WPDSQL\MySQL\SqlParser::parse(
    'SELECT `ID` FROM `wp_posts` WHERE `ID` = 123',
    sqlMode: 'ANSI_QUOTES,NO_BACKSLASH_ESCAPES'
);

$query->sql;    // Original SQL string.
$query->tokens; // WP_Parser_Token[]; start/length are UTF-8 byte offsets.
$query->tree;   // WP_Parser_Node; named grammar nodes and token children.
```

The facade accepts one complete, nonempty statement with an optional terminator.
Malformed input, unterminated strings/comments, and multiple statements fail.
Input must be UTF-8. Syntax failures throw `ParseException`; line/column are null
because this parser does not expose a reliable error location. Invalid encoding
or an unsupported grammar family throws `InvalidArgumentException`.

The parse table is fixed to MySQL 8.4.10. The optional `serverVersion` argument
(default 80410) accepts the 8.4 family and controls lexer version conditions;
it does not switch grammar versions. SQL modes affect tokenization, including
ANSI_QUOTES, NO_BACKSLASH_ESCAPES, IGNORE_SPACE, PIPES_AS_CONCAT, and
HIGH_NOT_PRECEDENCE. The adapter rejects unsupported SQL execution constructs
even when the grammar accepts their syntax.

`TreeAdapter` normalizes grammar wrappers, recursive lists, predicates, functions,
and statements into the compiler's semantic node vocabulary. The existing
compiler and renderer retain PDO parameter binding, current schema lookups,
value codecs, and bounded translation caching. The cache fingerprint includes
the bridge, compiler, renderer, facade, and pinned parser manifest.

## Rust extension evaluation

We built and loaded the upstream
[`php-ext-wp-mysql-parser`](https://github.com/WordPress/sqlite-database-integration/tree/bf181a286470be4de550112ce1f1d05322502c0e/packages/php-ext-wp-mysql-parser)
Rust extension with PHP 8.5.10. Its native parser expects the older recursive
engine's `rules`, lookahead data, and `highest_terminal_id`, rather than the
standalone engine's ACTION/GOTO tables. Passing the standalone grammar fails
with `Missing grammar highest_terminal_id`; its token definitions also target
the older grammar. This is an incompatible parser implementation, not a missing
PHP configuration option.

The release therefore uses standalone PHP. Using Rust here would require a
native LALR implementation plus compatible token and tree bindings. No Rust
binary is shipped or globally enabled by this repository.

## Updating the vendored sources

```sh
python3 scripts/sync-wordpress-parser.py
```

The script fetches the pinned revision, verifies raw upstream SHA-256 hashes
against `source.json`, applies namespace/import adaptation, and reapplies a narrow
lexer fix that rejects unterminated block and executable comments. Those patches
are recorded in the manifest and covered by helper tests. Upstream copyright and
license notices remain intact. The upstream README is retained unmodified; its
build commands describe development in the upstream package.

CI runs the synchronization script and rejects a changed vendor directory.
For an intentional upstream update, review the new sources and regenerate the
pin and checksum manifest together. Re-run grammar, translation, mode, cache,
and integration tests; grammar compatibility alone does not prove translation
compatibility. No grammar generator is needed for an ordinary installation.

## Verification and measurements

```sh
php tests/mysql-parser/helpers.php
php tests/mysql-parser/corpus.php
php tests/mysql-parser/benchmark.php wordpress
php tests/tools/phpunit.phar tests/
php tests/translation/cache.php
```

The local run passed 59 helper checks, all 504 inherited query fixtures, all 148
queries captured from the synthetic WordPress fixture, and the expected outcomes
for 31 syntax probes. In total, 675 accepted queries passed source/byte-span
checks. CI uses the tracked corpus; optional local WordPress captures are not
required. Invalid probes include malformed expressions, empty input, multiple
statements, unterminated comments, and an empty COALESCE call.

On PHP 8.5.10 CLI with OPcache disabled, 504 fixtures over five passes gave:

| Measurement | Standalone PHP parser |
|---|---:|
| First parse including lazy initialization | 5.75 ms |
| Median parse | 0.023 ms |
| p95 parse | 0.072 ms |
| Peak process allocation | 6 MiB |

These measure parsing only. See [translation and caching](sql-translation.md)
for end-to-end translation timings and the synthetic database checks.
