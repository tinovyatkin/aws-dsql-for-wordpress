<?php
/*
 * Copyright (c) 2020, 2025, Oracle and/or its affiliates.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2.0,
 * as published by the Free Software Foundation.
 *
 * This program is designed to work with certain software (including
 * but not limited to OpenSSL) that is licensed under separate terms, as
 * designated in a particular file or component or in included license
 * documentation. The authors of MySQL hereby grant you an additional
 * permission to link the program and your derivative works with the
 * separately licensed software that they have included with
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See
 * the GNU General Public License, version 2.0, for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */
namespace WPDSQL\MySQL;

use Antlr\Antlr4\Runtime\Lexer;
use Antlr\Antlr4\Runtime\Token;
use WPDSQL\MySQL\Generated\MySQLLexer;

/** PHP port of Oracle's grammar action helpers; see grammar/oracle/README.md. */
abstract class MySQLBaseLexer extends Lexer {
    use RecognizerConfiguration;

    /** Charset introducers recognized by MySQL 8.x; callers may override the set. */
    public array $charsets = [
        '_armscii8'=>true, '_ascii'=>true, '_big5'=>true, '_binary'=>true, '_cp1250'=>true,
        '_cp1251'=>true, '_cp1256'=>true, '_cp1257'=>true, '_cp850'=>true, '_cp852'=>true,
        '_cp866'=>true, '_cp932'=>true, '_dec8'=>true, '_eucjpms'=>true, '_euckr'=>true,
        '_gb18030'=>true, '_gb2312'=>true, '_gbk'=>true, '_geostd8'=>true, '_greek'=>true,
        '_hebrew'=>true, '_hp8'=>true, '_keybcs2'=>true, '_koi8r'=>true, '_koi8u'=>true,
        '_latin1'=>true, '_latin2'=>true, '_latin5'=>true, '_latin7'=>true, '_macce'=>true,
        '_macroman'=>true, '_sjis'=>true, '_swe7'=>true, '_tis620'=>true, '_ucs2'=>true,
        '_ujis'=>true, '_utf16'=>true, '_utf16le'=>true, '_utf32'=>true, '_utf8'=>true,
        '_utf8mb3'=>true, '_utf8mb4'=>true,
    ];
    protected bool $inVersionComment = false;
    /** @var list<Token> */
    private array $pendingTokens = [];

    public function reset(): void {
        $this->inVersionComment = false;
        $this->pendingTokens = [];
        parent::reset();
    }

    public function nextToken(): ?Token {
        if ($this->pendingTokens) return array_shift($this->pendingTokens);
        $next = parent::nextToken();
        if ($this->pendingTokens) {
            $pending = array_shift($this->pendingTokens);
            if ($next !== null) $this->pendingTokens[] = $next;
            return $pending;
        }
        return $next;
    }

    public function hasUnclosedVersionComment(): bool {
        return $this->inVersionComment;
    }

    protected function checkMySQLVersion(string $text): bool {
        if (strlen($text) < 8 || (int)substr($text, 3) > $this->serverVersion) return false;
        $this->inVersionComment = true;
        return true;
    }

    protected function determineFunction(int $proposed): int {
        // Look ahead without consuming whitespace into the function token. Keeping
        // whitespace separate preserves token spans and the default token channel.
        $offset = 1;
        if ($this->isSqlModeActive(SqlMode::IgnoreSpace)) {
            while (in_array($this->input->LA($offset), [32, 9, 13, 10], true)) $offset++;
        }
        return $this->input->LA($offset) === 40 ? $proposed : MySQLLexer::IDENTIFIER;
    }

    protected function determineNumericType(string $text): int {
        // Compare decimal strings: PHP integers cannot represent unsigned 64-bit
        // boundaries, and converting to float would lose precision.
        $negative = str_starts_with($text, '-');
        $digits = ltrim(ltrim($text, '+-'), '0');
        if ($digits === '') $digits = '0';
        $within = static fn(string $max): bool => strlen($digits) < strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) <= 0);
        if ($within($negative ? '2147483648' : '2147483647')) return MySQLLexer::INT_NUMBER;
        if ($within($negative ? '9223372036854775808' : '9223372036854775807')) return MySQLLexer::LONG_NUMBER;
        if (!$negative && $within('18446744073709551615')) return MySQLLexer::ULONGLONG_NUMBER;
        return MySQLLexer::DECIMAL_NUMBER;
    }

    protected function checkCharset(string $text): int {
        return isset($this->charsets[strtolower($text)]) ? MySQLLexer::UNDERSCORE_CHARSET : MySQLLexer::IDENTIFIER;
    }

    protected function emitDot(): void {
        $this->pendingTokens[] = $this->factory->createEx($this->tokenFactorySourcePair,
            MySQLLexer::DOT_SYMBOL, null, $this->channel, $this->tokenStartCharIndex,
            $this->tokenStartCharIndex, $this->tokenStartLine, $this->tokenStartCharPositionInLine);
        $this->tokenStartCharIndex++;
        $this->tokenStartCharPositionInLine++;
    }
}
