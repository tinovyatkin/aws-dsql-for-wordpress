<?php
namespace WPDSQL\MySQL;

final class ParseException extends \RuntimeException {
    public function __construct(public readonly int $errorLine, public readonly int $errorColumn) {
        // Do not put SQL values or ANTLR's raw diagnostic into public PHP logs.
        parent::__construct("Invalid MySQL syntax at line {$errorLine}, column {$errorColumn}");
    }
}
