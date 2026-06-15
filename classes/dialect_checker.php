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
 * Detects SQL syntax that is incompatible with the active database engine.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dialect_checker {

    /**
     * Patterns that are only valid in PostgreSQL; not in MySQL/MariaDB.
     * [ pattern => suggested alternative ]
     */
    private const PGSQL_ONLY = [
        '/\bILIKE\b/i'                          => 'LIKE (MySQL/MariaDB LIKE is case-insensitive by default)',
        '/\b\w+\s*::\s*\w+/i'                   => 'CAST(value AS type)',
        '/\bTO_CHAR\s*\(/i'                      => 'DATE_FORMAT()',
        '/\bTO_DATE\s*\(/i'                      => 'STR_TO_DATE()',
        '/\bGENERATE_SERIES\s*\(/i'             => '(no direct equivalent; use a numbers table)',
        '/EXTRACT\s*\(\s*EPOCH\s+FROM/i'        => 'UNIX_TIMESTAMP()',
        '/\bDATE_TRUNC\s*\(/i'                  => "DATE_FORMAT() (e.g. DATE_FORMAT(col, '%Y-%m-01') to truncate to month)",
        '/\bRETURNING\b/i'                       => '(not supported; use LAST_INSERT_ID())',
        '/\bSTRING_AGG\s*\(/i'                  => 'GROUP_CONCAT()',
        '/\bARRAY_AGG\s*\(/i'                   => '(no direct equivalent)',
        '/\bREGEXP_MATCHES\s*\(/i'              => 'REGEXP / RLIKE',
        '/\bUNNEST\s*\(/i'                       => '(no direct equivalent)',
        '/\bINTERVAL\s+\'\d+\s+\w+\'/i'        => "INTERVAL expression (use INTERVAL 1 DAY syntax without quotes)",
    ];

    /**
     * Patterns that are only valid in MySQL/MariaDB; not in PostgreSQL.
     * [ pattern => suggested alternative ]
     */
    private const MYSQL_ONLY = [
        '/\bDATE_FORMAT\s*\(/i'                 => 'TO_CHAR()',
        '/\bGROUP_CONCAT\s*\(/i'               => 'STRING_AGG()',
        '/\bIFNULL\s*\(/i'                      => 'COALESCE()',
        '/\bIF\s*\(\s*[^,]+,/i'                => 'CASE WHEN ... THEN ... ELSE ... END',
        '/`[^`]+`/'                              => 'double-quote identifiers ("column")',
        '/\bYEAR\s*\(\s*\w/i'                  => 'EXTRACT(year FROM ...)',
        '/\bMONTH\s*\(\s*\w/i'                 => 'EXTRACT(month FROM ...)',
        '/\bDAY\s*\(\s*\w/i'                    => 'EXTRACT(day FROM ...)',
        '/\bUNIX_TIMESTAMP\s*\(/i'             => 'EXTRACT(epoch FROM ...)',
        '/\bDATEDIFF\s*\(/i'                    => 'date1 - date2',
        '/\bSTR_TO_DATE\s*\(/i'                => 'TO_DATE()',
        '/\bLIMIT\s+\d+\s*,\s*\d+/i'          => 'LIMIT n OFFSET m',
        '/\bCONCAT_WS\s*\(/i'                  => "CONCAT() with separator, or use || operator",
        '/\bFROM_UNIXTIME\s*\(/i'              => 'TO_TIMESTAMP()',
    ];

    /**
     * Check SQL for patterns incompatible with the active database.
     *
     * @param string $sql SQL to check (unprefixed, no string literals need stripping — patterns
     *                    target function names that are unlikely to appear in string values).
     * @return void
     * @throws \moodle_exception When incompatible syntax is detected.
     */
    public function check(string $sql): void {
        global $CFG;

        $dbtype = $CFG->dbtype ?? 'mariadb';

        if ($dbtype === 'pgsql') {
            $incompatible = self::MYSQL_ONLY;
            $dialect = 'PostgreSQL';
        } else if (in_array($dbtype, ['mariadb', 'mysqli'], true)) {
            $incompatible = self::PGSQL_ONLY;
            $dialect = 'MariaDB/MySQL';
        } else {
            return;
        }

        $hits = [];
        foreach ($incompatible as $pattern => $suggestion) {
            if (preg_match($pattern, $sql)) {
                $hits[] = $suggestion;
            }
        }

        if ($hits) {
            $detail = implode('; ', $hits);
            throw new \moodle_exception(
                'error:dialectmismatch',
                'local_sqlchat',
                '',
                (object) ['dialect' => $dialect, 'suggestions' => $detail]
            );
        }
    }
}
