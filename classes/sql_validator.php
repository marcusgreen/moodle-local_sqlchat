<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_sqlchat;

/**
 * SELECT-only SQL validator. Rejects DML/DDL and stacked statements.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_validator {

    /** Keywords that must never appear in user-submitted SQL. */
    private const BLOCKED = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'TRUNCATE',
        'GRANT', 'REVOKE', 'CREATE', 'REPLACE', 'RENAME', 'CALL',
        'EXECUTE', 'HANDLER', 'LOCK', 'UNLOCK',
        'INTO\\s+OUTFILE', 'INTO\\s+DUMPFILE',
        'LOAD_FILE', 'LOAD\\s+DATA', 'LOAD\\s+XML',
        'INFORMATION_SCHEMA', 'PERFORMANCE_SCHEMA', 'MYSQL\\.',
    ];

    /**
     * Throw if the SQL is not a single safe SELECT.
     *
     * @param string $sql The SQL to validate.
     * @return void
     * @throws \moodle_exception
     */
    public function check(string $sql): void {
        $stripped = $this->strip_strings_and_comments($sql);
        $trimmed = trim($stripped);

        if (!preg_match('/^\s*(?:WITH\b.*?\bSELECT\b|SELECT\b)/is', $trimmed)) {
            throw new \moodle_exception('error:onlyselect', 'local_sqlchat');
        }

        foreach (self::BLOCKED as $kw) {
            if (preg_match('/\b' . $kw . '\b/i', $trimmed)) {
                throw new \moodle_exception(
                    'error:blockedkeyword',
                    'local_sqlchat',
                    '',
                    str_replace('\\s+', ' ', $kw)
                );
            }
        }

        $body = rtrim($trimmed, "; \t\n\r");
        if (str_contains($body, ';')) {
            throw new \moodle_exception('error:nosemicolons', 'local_sqlchat');
        }
    }

    /**
     * Remove quoted strings and comments so keyword checks don't false-positive on literal text.
     *
     * @param string $sql Raw SQL.
     * @return string SQL with string literals and comments replaced by single spaces.
     */
    private function strip_strings_and_comments(string $sql): string {
        $sql = preg_replace('!/\*.*?\*/!s', ' ', $sql);
        $sql = preg_replace('/--[^\n]*/', ' ', $sql);
        $sql = preg_replace("/'(?:''|\\\\.|[^'\\\\])*'/", "''", $sql);
        $sql = preg_replace('/"(?:""|\\\\.|[^"\\\\])*"/', '""', $sql);
        return $sql;
    }
}
