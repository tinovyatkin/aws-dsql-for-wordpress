<?php
namespace WPDSQL\MySQL;
use WPDSQL\MySQL\WordPress\WP_Parser_Node;
use WPDSQL\MySQL\WordPress\WP_Parser_Token;

final class ParsedQuery {
    /** @param list<WP_Parser_Token> $tokens Significant tokens with byte offsets; source retains whitespace/comments. */
    public function __construct(public readonly string $sql,public readonly array $tokens,public readonly WP_Parser_Node $tree) {}
}
