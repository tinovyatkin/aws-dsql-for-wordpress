<?php
namespace WPDSQL\MySQL;
/** Compiler view of a WordPress lexer token; positions are UTF-8 byte offsets. */
final class Token {
    public function __construct(public readonly WordPress\WP_Parser_Token $token) {}
    public function getText():string {return rtrim($this->token->get_bytes());}
    public function getSymbol():self {return $this;}
    public function getStartIndex():int {return $this->token->start;}
    public function getType():int {return $this->token->id;}
    public function isEnd():bool {return $this->token->length===0;}
}
