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

trait RecognizerConfiguration {
    public int $serverVersion = 80400;
    /** @var array<string, true> */
    public array $sqlModes = [];
    public bool $supportMrs = false;
    public bool $supportMle = false;

    public function isSqlModeActive(SqlMode $mode): bool {
        return isset($this->sqlModes[$mode->name]);
    }

    public function sqlModeFromString(string $modes): void {
        $this->sqlModes = [];
        foreach (explode(',', strtoupper($modes)) as $mode) {
            $flags = match (trim($mode)) {
                'ANSI', 'DB2', 'MAXDB', 'MSSQL', 'ORACLE', 'POSTGRESQL' => [SqlMode::AnsiQuotes, SqlMode::PipesAsConcat, SqlMode::IgnoreSpace],
                'ANSI_QUOTES' => [SqlMode::AnsiQuotes],
                'HIGH_NOT_PRECEDENCE', 'MYSQL323', 'MYSQL40' => [SqlMode::HighNotPrecedence],
                'PIPES_AS_CONCAT' => [SqlMode::PipesAsConcat],
                'IGNORE_SPACE' => [SqlMode::IgnoreSpace],
                'NO_BACKSLASH_ESCAPES' => [SqlMode::NoBackslashEscapes],
                default => [], // Other server modes do not alter this grammar.
            };
            foreach ($flags as $flag) $this->sqlModes[$flag->name] = true;
        }
    }
}
