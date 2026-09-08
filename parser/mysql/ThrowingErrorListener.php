<?php
namespace WPDSQL\MySQL;

use Antlr\Antlr4\Runtime\Error\Listeners\BaseErrorListener;
use Antlr\Antlr4\Runtime\Error\Exceptions\RecognitionException;
use Antlr\Antlr4\Runtime\Recognizer;

final class ThrowingErrorListener extends BaseErrorListener {
    public function syntaxError(Recognizer $recognizer, ?object $offendingSymbol, int $line,
        int $charPositionInLine, string $msg, ?RecognitionException $exception): void {
        throw new ParseException($line, $charPositionInLine);
    }
}
