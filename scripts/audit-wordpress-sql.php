<?php
/** Inventory public core SQL source; never loads WordPress or connects to a database. */
if ($argc !== 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php scripts/audit-wordpress-sql.php CORE_ROOT OUTPUT_JSON\n");
    exit(2);
}
$root = realpath($argv[1]);
$calls = $strings = [];
$files = 0;
$methods = ['query','prepare','get_results','get_row','get_col','get_var','insert','replace','update','delete'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = substr($file->getPathname(), strlen($root) + 1);
    if ($file->getExtension() !== 'php' || str_starts_with($path, 'wp-content/') || str_starts_with($path, '.git/')) continue;
    ++$files;
    $tokens = token_get_all(file_get_contents($file->getPathname()));
    $significant = [];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT,T_DOC_COMMENT,T_WHITESPACE], true)) continue;
        $significant[] = $token;
    }
    $text = static fn($t) => is_array($t) ? $t[1] : $t;
    foreach ($significant as $i => $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING,T_ENCAPSED_AND_WHITESPACE], true)
            && preg_match('/\b(?:SELECT|INSERT|REPLACE|UPDATE|DELETE|CREATE|ALTER|DROP|SHOW|DESCRIBE|REPAIR|OPTIMIZE|CHECK|INFORMATION_SCHEMA|REGEXP|RLIKE|GROUP_CONCAT|CAST|COLLATE)\b/i', $token[1])) {
            $strings[] = ['file'=>$path,'line'=>$token[2],'text'=>$token[1]];
        }
        if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$wpdb'
            || $text($significant[$i+1] ?? '') !== '->'
            || !in_array($text($significant[$i+2] ?? ''), $methods, true)
            || $text($significant[$i+3] ?? '') !== '(') continue;
        $depth = 0; $expression = '';
        for ($j=$i; $j<count($significant); ++$j) {
            $t = $significant[$j]; $expression .= $text($t);
            if ($t === '(') ++$depth;
            if ($t === ')' && --$depth === 0) break;
        }
        $calls[] = ['file'=>$path,'line'=>$token[2],'method'=>$text($significant[$i+2]),'expression'=>$expression];
    }
}
usort($calls, static fn($a,$b) => [$a['file'],$a['line']] <=> [$b['file'],$b['line']]);
usort($strings, static fn($a,$b) => [$a['file'],$a['line']] <=> [$b['file'],$b['line']]);
$counts = array_count_values(array_column($calls,'method')); ksort($counts);
$report = ['php_files'=>$files,'wpdb_calls'=>count($calls),'methods'=>$counts,
    'limitations'=>['Static call sites are not complete executable queries or proof of runtime coverage.',
        'Includes prepare calls nested in execution calls; counts are not unique SQL shapes.',
        'SQL-keyword strings include fragments and false positives requiring review.',
        'Does not resolve aliases, dynamic method names, filters, or arbitrary PHP data flow.',
        'Excludes wp-content; inspect core query builders and wpdb internal methods separately.'],
    'calls'=>$calls,'sql_keyword_strings'=>$strings];
file_put_contents($argv[2], json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
echo json_encode(['php_files'=>$files,'wpdb_calls'=>count($calls),'methods'=>$counts,'sql_keyword_strings'=>count($strings)], JSON_PRETTY_PRINT)."\n";
