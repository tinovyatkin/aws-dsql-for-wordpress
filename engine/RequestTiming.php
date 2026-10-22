<?php
namespace WPDSQL\Engine;

/** Bounded, value-free request telemetry; nested driver operations count once. */
final class RequestTiming {
    private int $depth = 0;
    private float $total = 0.0;
    private int $queries = 0;
    private int $failures = 0;
    private array $phases = ['tokenize_ms'=>0.0,'compile_ms'=>0.0,'metadata_ms'=>0.0,'execute_ms'=>0.0,'decode_ms'=>0.0];
    private \Closure $clock;

    public function __construct(?\Closure $clock = null) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e6;
    }
    public function measure(callable $operation, bool $query = false): mixed {
        if ($query) $this->queries++;
        $outer = $this->depth++ === 0;
        $start = $outer ? ($this->clock)() : 0.0;
        try { return $operation(); }
        catch (\Throwable $error) {
            if ($query) $this->failures++;
            throw $error;
        } finally {
            $this->depth--;
            if ($outer) $this->total += max(0.0, ($this->clock)() - $start);
        }
    }
    /** Accept known numeric phases only, never statements, values, or rows. */
    public function recordNative(array $timing): void {
        foreach ($this->phases as $name => $_) {
            $value = $timing[$name] ?? null;
            if ((is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 0) {
                $this->phases[$name] += $value;
            }
        }
    }
    public function snapshot(): array {
        return ['total_ms'=>$this->total,'query_calls'=>$this->queries,'failed_queries'=>$this->failures] + $this->phases;
    }
}
