<?php
/** Offline parser evaluation; reads synthetic corpora and never executes SQL. */
$root = dirname(__DIR__, 2);
require $root . '/.local/parser-evaluation/vendor/autoload.php';
use PhpMyAdmin\SqlParser\Lexer;
use PhpMyAdmin\SqlParser\Parser;
use PhpMyAdmin\SqlParser\TokenType;

function inspect_sql(string $sql): array {
    $lexer = new Lexer($sql);
    $parser = new Parser($lexer->list);
    $errors = array_map(static fn($e) => $e->getMessage(), [...$lexer->errors, ...$parser->errors]);
    return [$lexer, $parser, $errors];
}
function literal_tokens(Lexer $lexer): array {
    return array_values(array_map(static fn($t) => $t->token, array_filter($lexer->list->tokens, static fn($t) => $t->type === TokenType::String)));
}
function evaluate_sql(string $sql): array {
    [$lexer, $parser, $errors] = inspect_sql($sql);
    $result = ['accepted' => !$errors && count($parser->statements) > 0, 'errors' => $errors,
        'statements' => count($parser->statements),
        'classes' => array_map(static fn($s) => basename(str_replace('\\','/',get_class($s))), $parser->statements)];
    $result['token_roundtrip_exact'] = implode('', array_map(static fn($t) => $t->token, $lexer->list->tokens)) === $sql;
    if (!$result['accepted']) return $result;
    try {
        $rebuilt = implode('; ', array_map(static fn($s) => $s->build(), $parser->statements));
        [$nextLexer, $nextParser, $nextErrors] = inspect_sql($rebuilt);
        $result['rebuild_accepted'] = !$nextErrors && count($nextParser->statements) === count($parser->statements);
        $result['literal_tokens_preserved'] = literal_tokens($lexer) === literal_tokens($nextLexer);
        $result['rebuilt'] = $rebuilt;
    } catch (Throwable $e) {
        $result['rebuild_accepted'] = false;
        $result['build_error'] = $e->getMessage();
    }
    return $result;
}
$corpora = ['inherited' => [], 'wordpress' => [], 'probes' => []];
foreach (glob($root . '/tests/stubs/*.txt') as $file) {
    $item = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $corpora['inherited'][basename($file)] = $item['mysql'];
}
$capture = $root . '/.local/parser-evaluation/wordpress-queries.json';
if (is_file($capture)) foreach (array_values(array_unique(json_decode(file_get_contents($capture),true,flags:JSON_THROW_ON_ERROR))) as $i => $sql) $corpora['wordpress']['query-'.$i] = $sql;
$probes = require __DIR__ . '/cases.php';
foreach ($probes as $name => [$valid, $sql]) $corpora['probes'][$name] = $sql;
$report = ['php' => PHP_VERSION, 'parser' => Composer\InstalledVersions::getPrettyVersion('phpmyadmin/sql-parser'), 'mbstring' => extension_loaded('mbstring'), 'corpora' => [], 'details' => []];
foreach ($corpora as $name => $queries) {
    $stats = ['queries'=>count($queries), 'accepted'=>0, 'rejected'=>0, 'rebuilt_accepted'=>0, 'literal_tokens_changed'=>0, 'token_roundtrip_changed'=>0, 'statement_classes'=>[]];
    foreach ($queries as $id => $sql) {
        $item = evaluate_sql($sql);
        $stats[$item['accepted']?'accepted':'rejected']++;
        foreach($item['classes'] as $class) $stats['statement_classes'][$class]=($stats['statement_classes'][$class]??0)+1;
        $stats['rebuilt_accepted'] += (int)($item['rebuild_accepted']??false);
        $stats['literal_tokens_changed'] += (int)(isset($item['literal_tokens_preserved']) && !$item['literal_tokens_preserved']);
        $stats['token_roundtrip_changed'] += (int)!$item['token_roundtrip_exact'];
        if ($name==='probes') $item['expected_valid']=$probes[$id][0];
        $report['details'][$name][$id] = $item;
    }
    // Warmed parse-only timings, including lexing. No SQL execution or network.
    $samples=[];
    foreach($queries as $sql) inspect_sql($sql);
    for($round=0;$round<5;$round++) foreach($queries as $sql) {
        $start=hrtime(true); inspect_sql($sql); $samples[]=(hrtime(true)-$start)/1e6;
    }
    sort($samples); $n=count($samples);
    $stats['parse_ms'] = $n ? ['median'=>$samples[(int)floor(($n-1)*.5)],'p95'=>$samples[(int)floor(($n-1)*.95)],'mean'=>array_sum($samples)/$n] : [];
    $lexerSamples=[];
    for($round=0;$round<5;$round++) foreach($queries as $sql) {
        $start=hrtime(true); new Lexer($sql); $lexerSamples[]=(hrtime(true)-$start)/1e6;
    }
    sort($lexerSamples);$n=count($lexerSamples);
    $stats['lexer_ms']=$n?['median'=>$lexerSamples[(int)floor(($n-1)*.5)],'p95'=>$lexerSamples[(int)floor(($n-1)*.95)]]:[];
    $report['corpora'][$name]=$stats;
}
// Strict mode does not add expression grammar validation.
$strictAccepted=[];
foreach($probes as $id=>[$valid,$sql]) if(!$valid) {
    try {$l=new Lexer($sql,true);new Parser($l->list,true);$strictAccepted[]=$id;}catch(Throwable $expected){}
}
$report['invalid_accepted_in_strict_mode']=$strictAccepted;
$report['peak_allocated_mb']=memory_get_peak_usage(true)/1048576;
// Inspect the structure required to recursively translate nested functions.
[, $nested] = inspect_sql($probes['nested_functions'][1]);
$s=$nested->statements[0];
$report['structure']=['select_expression'=>get_object_vars($s->expr[0]), 'where'=>array_map('get_object_vars',$s->where),'limit'=>get_object_vars($s->limit)];
file_put_contents($root.'/.local/parser-evaluation/report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR));
chmod($root.'/.local/parser-evaluation/report.json',0600);
echo json_encode(['corpora'=>$report['corpora'],'peak_allocated_mb'=>$report['peak_allocated_mb']],JSON_PRETTY_PRINT)."\n";
