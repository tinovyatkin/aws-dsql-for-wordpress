<?php
/** Parse stdin and emit value-free status. SQL is never executed. */
require dirname(__DIR__).'/vendor/autoload.php';
use WPDSQL\MySQL\SqlParser;
use WPDSQL\MySQL\ParseException;
try {
    $parsed=SqlParser::parse(stream_get_contents(STDIN));
    echo json_encode(['accepted'=>true,'tree'=>get_class($parsed->tree),'tokens'=>count($parsed->tokens)],JSON_THROW_ON_ERROR)."\n";
} catch(ParseException $e) {
    echo json_encode(['accepted'=>false,'line'=>$e->errorLine,'column'=>$e->errorColumn],JSON_THROW_ON_ERROR)."\n";
    exit(1);
} catch(InvalidArgumentException $e) {
    echo json_encode(['accepted'=>false,'error'=>$e->getMessage()],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
