<?php
namespace WPDSQL\Engine;

/** Safe top-level message; native details are available only by explicit access. */
final class QueryException extends \RuntimeException {
    public function __construct(
        private readonly \Throwable $failure,
        public readonly string $stage,
        public readonly string $operation,
        public readonly int $retries,
        public readonly bool $controlledUpgrade = false,
    ) {
        parent::__construct($controlledUpgrade
            ? 'Controlled DSQL upgrade stopped after a database/schema failure; inspect its private journal'
            : 'DSQL query failed during '.$stage);
    }
    public function nativeFailure(): \Throwable { return $this->failure; }
    public function sqlState(): ?string {
        return $this->failure instanceof \PDOException ? (string) $this->failure->getCode() : null;
    }
}
