<?php
namespace WPDSQL\MySQL;
use WPDSQL\MySQL\WordPress\WP_MySQL_Lexer;
use WPDSQL\MySQL\WordPress\WP_MySQL_Parser_Factory;

/** Parse a complete statement using WordPress's standalone MySQL 8.4.10 grammar. */
final class SqlParser {
    public const GRAMMAR_VERSION='8.4.10';
    public static function parse(string $sql,int $serverVersion=80410,string $sqlMode=''):ParsedQuery {
        if($serverVersion<80400||$serverVersion>=80500)throw new \InvalidArgumentException('This parse table targets MySQL 8.4');
        if(!mb_check_encoding($sql,'UTF-8'))throw new \InvalidArgumentException('SQL input must be UTF-8');
        require_once __DIR__.'/WordPress/bootstrap.php';
        $modes=array_values(array_filter(array_map('trim',explode(',',strtoupper($sqlMode)))));
        $tokens=(new WP_MySQL_Lexer($sql,$serverVersion,$modes))->remaining_tokens();
        if(!$tokens||end($tokens)->id!==0||!array_filter($tokens,fn($t)=>$t->length>0&&$t->get_bytes()!==';'))throw new ParseException();
        $tree=WP_MySQL_Parser_Factory::create_parser()->parse($tokens);
        if($tree===null)throw new ParseException();
        return new ParsedQuery($sql,$tokens,$tree);
    }
}
