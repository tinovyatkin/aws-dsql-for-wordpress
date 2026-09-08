<?php
/** Limited structural rewrite proof, not a production SQL translator. */
require dirname(__DIR__, 2) . '/.local/parser-evaluation/vendor/autoload.php';
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\Token;
use PhpMyAdmin\SqlParser\TokenType;

function must(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo "PASS $label\n";
}
$sql = "SELECT SQL_CALC_FOUND_ROWS `id`, (SELECT 2 LIMIT 1) AS nested_value, 'LIMIT 99, 100; `id` 🌍' AS caption FROM `wp_posts` ORDER BY `id` LIMIT 5, 10";
$lexer = new Lexer($sql, true);
$parser = new Parser($lexer->list, true);
$statement = $parser->statements[0];
must(count($parser->statements)===1, 'One parsed statement');
$limit = $statement->limit;
$statement->limit = null;
$statement->options->remove('SQL_CALC_FOUND_ROWS');
$rebuilt = $statement->build();
// Change identifier tokens only, preserving all literal tokens verbatim.
$parts=[];
foreach((new Lexer($rebuilt,true))->list->tokens as $token) {
    $parts[]=$token->type===TokenType::Symbol && ($token->flags & Token::FLAG_SYMBOL_BACKTICK)
        ? '"'.str_replace('"','""',(string)$token->value).'"' : $token->token;
}
$output=trim(implode('',$parts)).' LIMIT '.$limit->rowCount.' OFFSET '.$limit->offset;
must(str_ends_with($output,'LIMIT 10 OFFSET 5'), 'Outer LIMIT converted using parsed offset/count');
must(str_contains($output,'SELECT 2 LIMIT 1'), 'Nested LIMIT preserved');
must(str_contains($output,"'LIMIT 99, 100; `id` 🌍'"), 'SQL-like Unicode literal preserved byte for byte');
must(str_contains($output,'FROM "wp_posts"'), 'Identifier quoted using token type');
must(!str_contains($output,'SQL_CALC_FOUND_ROWS'), 'SELECT option removed structurally');
echo $output."\n";
