# Generated MySQL parser

The repository now includes a PHP parser generated from Oracle's MySQL grammar,
with a lexer, complete grammar parse tree, and generated visitor interfaces.
It is a foundation for structured SQL translation. The existing DSQL runtime
translator still uses its current implementation; no deployment was changed.

## Usage

Requires PHP 8.2+, native `mbstring`, and the normal Composer installation:

```php
require 'vendor/autoload.php';

use WPDSQL\MySQL\SqlParser;

$query = SqlParser::parse(
    "SELECT id FROM wp_posts ORDER BY title LIKE '%needle%' DESC LIMIT 5, 10",
    serverVersion: 80400,
    sqlMode: ''
);

$tree = $query->tree;     // Generated QueryContext with nested grammar contexts.
$tokens = $query->tokens; // Includes whitespace, comments, and original token text.
```

A visitor can extend `WPDSQL\MySQL\Generated\MySQLParserBaseVisitor` and override
methods such as `visitLimitClause`, `visitFunctionCallGeneric`, and
`visitRuntimeFunctionCall`. Nested functions and nested query clauses are
independent nodes, rather than opaque SQL strings. This is a concrete syntax
tree; a normalized AST or DSQL emitter can be built on top of it.

`SqlParser::parse()` accepts exactly one complete statement, including an optional
trailing semicolon, and requires valid UTF-8 input. Empty/comment-only input and
multiple statements are rejected. SQL is never executed. Syntax failures throw
`ParseException`, which exposes line/column information without including SQL
values in its message. The caller still controls exception logging and traces.

The facade removes ANTLR's default console listeners, checks lexer errors and
unterminated comments, and throws on parser errors. It does not silently return
an error-recovered tree. It first tries SLL prediction with bail-on-error, then
retries from the beginning in full LL mode if necessary. `sllFirst: false` is
available for comparison and debugging.

Token offsets count Unicode code points, not bytes. Preserve the supplied input
and raw token text when extracting spans or rewriting values. Do not use the
whitespace-free tree `getText()` as a replacement for the original SQL.

A value-free command-line check is also available:

```sh
printf '%s' 'SELECT id FROM wp_posts ORDER BY title LIKE '\''%needle%'\'' DESC' | php scripts/parse-mysql.php
```

## Verification

- All **504 inherited SQL fixtures** parsed.
- All **148 distinct captured synthetic WordPress queries** parsed, including
  the normal search ORDER BY expression rejected by phpMyAdmin's parser.
- **51 helper/boundary checks** cover numeric limits, SQL modes, function
  recognition, charset introducers, version comments and gates, Unicode spans,
  lexer reset, malformed statements, nested functions, and visitors.
- **676 successful cases** produced identical SLL-first and full-LL trees, and
  their full token streams reproduced the original input exactly.
- Existing translation regression tests remain green: **31 tests / 541 assertions**.
  The controlled schema-upgrade parser checks also pass.

The shared 31-probe set yields 24 accepted inputs. All six malformed-syntax probes
are rejected. The multi-statement probe is rejected by the single-statement API.
`COALESCE()` remains accepted as a syntactically generic function call: function
arity, name resolution, type checking, and other execution semantics are not
validated by the grammar. Parsing success also does not establish DSQL support.

Run the tests with:

```sh
php tests/mysql-parser/helpers.php
php tests/mysql-parser/corpus.php
php tests/mysql-parser/benchmark.php antlr
```

The corpus script always checks inherited fixtures and handwritten probes. It
also uses `.local/parser-evaluation/wordpress-queries.json` when the optional
synthetic capture from the [earlier evaluation](parser-evaluation.md) exists.
No production access is required. Aggregate results go under ignored `.local/`.

## Performance

Measured in separate PHP 8.5.10 CLI processes, CLI OPcache disabled, native
mbstring, on the same 504 inherited fixtures. Five warmed passes per parser
provided 2,520 measurements each. Parsing includes tree construction but no
SQL translation, database execution, or network access.

| Measurement | Oracle grammar / ANTLR PHP | phpMyAdmin parser 6.0.0 |
| --- | ---: | ---: |
| Warm median | 0.758 ms | 0.176 ms |
| Warm p95 | 5.992 ms | 0.564 ms |
| Warm mean | 1.729 ms | 0.229 ms |
| First `SELECT 1`, including lazy parser loading | 145.45 ms | 7.97 ms |
| Whole-process peak allocated memory | 74.03 MiB | 4 MiB |

This implementation provides better grammar structure and tested syntax coverage,
but is **slower and larger** than phpMyAdmin's parser. It must not be described
as a performance improvement. Static automaton/DFA state warms within a process;
these measurements do not establish FPM request latency or cross-request cache
behavior. Runtime integration needs its own end-to-end performance work.

To reproduce the comparison, install the isolated phpMyAdmin evaluation package
as described in the earlier evaluation, then run:

```sh
php tests/mysql-parser/benchmark.php antlr
php tests/mysql-parser/benchmark.php phpmyadmin
```

## Source and build

See [the pinned Oracle source, adaptations, and regeneration procedure](../grammar/oracle/README.md).
The generator and Composer runtime versions match ANTLR 4.13.2. Oracle's grammar
notices are retained in both source and generated files. See the
[official PHP target documentation](https://github.com/antlr/antlr4/blob/4.13.2/doc/php-target.md)
and [PHP runtime](https://github.com/antlr/antlr-php-runtime) for the target APIs.
