<?php
namespace WPDSQL\MySQL;

use Antlr\Antlr4\Runtime\CommonTokenStream;
use Antlr\Antlr4\Runtime\InputStream;
use Antlr\Antlr4\Runtime\Atn\PredictionMode;
use Antlr\Antlr4\Runtime\Error\BailErrorStrategy;
use Antlr\Antlr4\Runtime\Error\DefaultErrorStrategy;
use Antlr\Antlr4\Runtime\Error\Exceptions\ParseCancellationException;
use WPDSQL\MySQL\Generated\MySQLLexer;
use WPDSQL\MySQL\Generated\MySQLParser;

/** Parse exactly one complete MySQL statement, preserving its concrete syntax tree. */
final class SqlParser {
    public static function parse(string $sql, int $serverVersion = 80400, string $sqlMode = '', bool $sllFirst = true): ParsedQuery {
        if ($serverVersion < 80000) throw new \InvalidArgumentException('This grammar targets MySQL 8.0 and newer');
        if (!mb_check_encoding($sql, 'UTF-8')) throw new \InvalidArgumentException('SQL input must be UTF-8');
        $errors = new ThrowingErrorListener();
        $lexer = new MySQLLexer(InputStream::fromString($sql));
        $lexer->serverVersion = $serverVersion;
        $lexer->sqlModeFromString($sqlMode);
        $lexer->removeErrorListeners();
        $lexer->addErrorListener($errors);
        $tokens = new CommonTokenStream($lexer);
        $tokens->fill();
        foreach ($tokens->getAllTokens() as $token) {
            if ($token->getType() === MySQLLexer::INVALID_BLOCK_COMMENT) {
                throw new ParseException($token->getLine(), $token->getCharPositionInLine());
            }
        }
        if ($lexer->hasUnclosedVersionComment()) throw new ParseException($lexer->getLine(), $lexer->getCharPositionInLine());
        $parser = new MySQLParser($tokens);
        $parser->serverVersion = $serverVersion;
        $parser->sqlModeFromString($sqlMode);
        $parser->removeErrorListeners();
        $parser->addErrorListener($errors);
        if ($sllFirst) {
            $parser->getInterpreter()->setPredictionMode(PredictionMode::SLL);
            $parser->setErrorHandler(new BailErrorStrategy());
            try {
                $tree = $parser->query();
            } catch (ParseCancellationException | ParseException $e) {
                // SLL failure can be a context ambiguity rather than invalid SQL.
                // Reparse the complete token stream with full LL prediction.
                $parser->reset();
                $parser->getInterpreter()->setPredictionMode(PredictionMode::LL);
                $parser->setErrorHandler(new DefaultErrorStrategy());
                $tree = $parser->query();
            }
        } else {
            $tree = $parser->query();
        }
        if ($tree->simpleStatement() === null && $tree->beginWork() === null) throw new ParseException(1, 0);
        return new ParsedQuery($sql, $tokens, $tree);
    }
}
