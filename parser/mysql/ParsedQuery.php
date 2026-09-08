<?php
namespace WPDSQL\MySQL;

use Antlr\Antlr4\Runtime\CommonTokenStream;
use WPDSQL\MySQL\Generated\Context\QueryContext;

final class ParsedQuery {
    public function __construct(
        public readonly string $sql,
        public readonly CommonTokenStream $tokens,
        public readonly QueryContext $tree,
    ) {}
}
