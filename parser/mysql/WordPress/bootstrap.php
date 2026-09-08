<?php
foreach (['class-wp-parser-token.php','class-wp-parser-node.php','class-wp-parser-grammar.php','class-wp-parser.php'] as $file) {
    require_once __DIR__.'/src/parser/'.$file;
}
foreach (['class-wp-mysql-token.php','class-wp-mysql-lexer.php','class-wp-mysql-parser-factory.php'] as $file) {
    require_once __DIR__.'/src/'.$file;
}
