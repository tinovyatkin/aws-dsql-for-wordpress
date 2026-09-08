<?php
namespace WPDSQL\MySQL;
final class ParseException extends \RuntimeException {
    public function __construct(public readonly ?int $errorLine=null,public readonly ?int $errorColumn=null) {
        // The standalone parser reports rejection, not an exact failure position.
        parent::__construct($errorLine===null?'Invalid MySQL syntax':"Invalid MySQL syntax at line {$errorLine}, column {$errorColumn}");
    }
}
