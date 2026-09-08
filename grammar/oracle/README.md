# Oracle MySQL grammar

`MySQLLexer.g4` and `MySQLParser.g4` are unmodified copies from
[Oracle's MySQL Shell plugins](https://github.com/mysql/mysql-shell-plugins/tree/758c33bdf23785769e7d5aa88bfe5494f86aa6d4/gui/frontend/src/parsing/mysql),
commit `758c33bdf23785769e7d5aa88bfe5494f86aa6d4`. `source.json` records SHA-256
checksums for the grammars and the upstream TypeScript helper sources used for
the PHP port.

The Oracle files, generated derivatives, and ported helpers retain Oracle's
copyright and GPL version 2 notices, including its additional linking permission.
See the notices in those files and the [GPL text](../../license.md). Other
repository files retain their existing licenses.

## Generation

Run `python3 scripts/generate-mysql-parser.py` from the repository root. It uses
ANTLR **4.13.2**, verifies the generator JAR and input grammar checksums, converts
target-specific action syntax, and generates PHP lexer/parser/visitor classes.
The matching Composer runtime is **antlr/antlr4-php-runtime 0.10.0**. Java and
Python are build tools only; deployed PHP does not invoke Java.

Generated files live in `parser/mysql/Generated/` and are committed so Composer
installation does not require running a compiler. Regeneration is byte-for-byte
reproducible. Edit the port or generation script, never generated PHP by hand.

## Target adaptations

The generation script changes `this.member`/class constants into PHP accessors,
uses `getText()` for lexer text, and replaces TypeScript imports with PHP imports.
It carries the original copyright/license notice into generated files.

Two explicit grammar corrections are applied to the temporary build copy:

- `UNDERSCORE_CHARSET` accepts uppercase letters as well as lowercase letters.
- The upstream `INTERSECT_SYMBOL` rule spells the literal `INTERSECT_SYMBOL`;
  the build copy spells the SQL keyword `INTERSECT` instead.

The original grammar files and their checksums remain unchanged. The helper
suite tests both corrections, including the MySQL 8.0.31 version boundary.

## PHP superclass port

`MySQLBaseLexer` implements only the action/runtime helpers required by this
grammar. SQL editor completion, keyword lookup, and UI query classification from
the upstream TypeScript application are not needed here.

The port preserves server-version predicates, SQL modes, charset introducers,
numeric classification, executable comments, and the pending token queue used
for a qualified identifier such as `t.select`. Dot-token spans and lexer reset
are tested with Unicode identifiers.

Two helper implementation details deliberately differ from the TypeScript code:

- `IGNORE_SPACE` looks ahead over whitespace instead of consuming it into the
  function token and changing its channel. This preserves both token visibility
  and exact source spans.
- Numeric classification compares decimal strings at signed/unsigned boundaries.
  This avoids integer overflow, float precision loss, and the upstream helper's
  off-by-one length calculation.

`MySQLBaseRecognizer` and the lexer share configuration through
`RecognizerConfiguration`. MySQL 8.4 syntax is the default; callers can select a
MySQL 8.0+ version. MRS and MLE extensions are disabled. Version gates describe
syntax support, not a guarantee that every server-version combination has been
exhaustively validated.
