<?php
namespace WPDSQL\Engine;

/** Preserve native SQLSTATE for the existing value-free diagnostics contract. */
final class NativeDatabaseException extends \PDOException {
    public function __construct(string $message, string $sqlstate) {
        parent::__construct($message);
        $this->code = $sqlstate;
        $this->errorInfo = [$sqlstate, null, $message];
    }
}
