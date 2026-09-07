<?php
/** Value-free diagnostics: never export SQL, exception messages, or stack arguments. */
final class DSQL_Diagnostics {
    public static function event(Throwable $error, string $query, string $stage, float $started, int $retries = 0): array {
        $state = $error instanceof PDOException ? (string) ($error->errorInfo[0] ?? $error->getCode()) : '';
        $state = preg_match('/^[A-Z0-9]{5}$/D', $state) ? $state : null;
        $context = defined('WP_CLI') && WP_CLI ? 'cli' :
            (defined('DOING_CRON') && DOING_CRON ? 'cron' :
            (defined('REST_REQUEST') && REST_REQUEST ? 'rest' :
            (defined('WP_ADMIN') && WP_ADMIN ? 'admin' : 'frontend')));
        return [
            'event' => 'wordpress_dsql_error', 'version' => 1,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'stage' => $stage, 'kind' => $error instanceof PDOException ? 'database' : 'adapter',
            'sqlstate' => $state, 'operation' => self::operation($query),
            'fingerprint' => hash('sha256', self::normalize($query)),
            'source' => self::source(), 'context' => $context,
            'elapsed_ms' => round(max(0, microtime(true) - $started) * 1000, 2),
            'retries' => $retries,
        ];
    }

    /** A grouping key only, never a SQL template for execution or display. */
    public static function normalize(string $query): string {
        // Consume comments and quoted tokens before numbers/words. Unterminated
        // strings/comments consume the remainder too: failing SQL is often malformed.
        $pattern = <<<'REGEX'
~(/\*.*?(?:\*/|$)|--[^\r\n]*|\#[^\r\n]*)|(?:\$(?:[a-zA-Z_][a-zA-Z_0-9]*)?\$)|(?:'(?:\\.|''|[^'\\])*(?:'|$)|"(?:\\.|""|[^"\\])*(?:"|$)|`(?:``|[^`])*(?:`|$))|(?:\b(?:0x[0-9a-f]+|\d+(?:\.\d*)?(?:e[+-]?\d+)?)\b)|(?:[a-zA-Z_][a-zA-Z_0-9$]*)|(?:\s+)|.~is
REGEX;
        preg_match_all($pattern, $query, $matches, PREG_OFFSET_CAPTURE);
        $tokens = [];
        $skipUntil = 0;
        foreach ($matches[0] as [$token, $offset]) {
            if ($offset < $skipUntil) { continue; }
            if (str_starts_with($token, '$') && str_ends_with($token, '$')) {
                $end = strpos($query, $token, $offset + strlen($token));
                $skipUntil = $end === false ? strlen($query) : $end + strlen($token);
                $tokens[] = '?';
            } elseif (preg_match('~^(?:/\*|--|\#|\s)~', $token)) {
                continue;
            } elseif (str_contains("'\"`", $token[0]) || ctype_digit($token[0])) {
                $tokens[] = '?';
            } else {
                $tokens[] = strtoupper($token);
            }
        }
        return implode(' ', $tokens);
    }

    private static function operation(string $query): string {
        preg_match('/^([A-Z]+)/', self::normalize($query), $match);
        $op = $match[1] ?? '';
        return in_array($op, ['SELECT','INSERT','UPDATE','DELETE','REPLACE','CREATE','ALTER','DROP','SHOW','DESCRIBE','SET','BEGIN','START','COMMIT','ROLLBACK','WITH','EXPLAIN','TRUNCATE'], true) ? $op : 'OTHER';
    }

    private static function source(): string {
        $core = 'unclassified';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
            $file = str_replace('\\', '/', $frame['file'] ?? '');
            if (str_contains($file, '/pg4wp/') || str_ends_with($file, '/class-wpdb.php')) { continue; }
            // Only code-relative paths, never request URLs, absolute paths or arguments.
            if (preg_match('~/(wp-content/(?:mu-plugins|plugins|themes)/[a-zA-Z0-9_./-]+\.php|wp-includes/[a-zA-Z0-9_./-]+\.php|wp-admin/[a-zA-Z0-9_./-]+\.php)$~', $file, $match)) {
                $source = $match[1] . ':' . (int) ($frame['line'] ?? 0);
                if (str_starts_with($match[1], 'wp-content/')) { return $source; }
                if ($core === 'unclassified') { $core = $source; }
            }
        }
        return $core;
    }

    public static function emit(array $event): void {
        // The PHP log collector can ship this JSON without querying the failed DB.
        // Observability must not replace a database error with a logging exception.
        try { error_log(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        catch (Throwable $ignored) {}
    }
}
