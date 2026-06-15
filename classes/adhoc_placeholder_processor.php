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
 * Resolves ad-hoc report placeholder tokens before SQL validation and execution.
 *
 * Handles the token syntax used by the Moodle ad-hoc contributed reports
 * (https://docs.moodle.org/en/ad-hoc_contributed_reports):
 *   %%WWWROOT%%   site base URL
 *   %%USERID%%    current user id
 *   %%STARTTIME%% Unix timestamp — start of current reporting week (Monday 00:00)
 *   %%ENDTIME%%   Unix timestamp — end of current reporting week (Sunday 23:59:59)
 *   %%C%%         colon   (:)
 *   %%S%%         semicolon (;)
 *   %%Q%%         question mark (?)
 *
 * Named parameters (:param_name) cannot be resolved automatically — the processor
 * throws a descriptive exception listing them so the user knows what to substitute.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adhoc_placeholder_processor {

    /**
     * Replace known tokens and throw for anything that cannot be resolved.
     *
     * @param string $sql SQL potentially containing ad-hoc report tokens.
     * @return string SQL with all resolvable tokens substituted.
     * @throws \moodle_exception When unresolvable tokens or named parameters remain.
     */
    public function process(string $sql): string {
        global $CFG, $USER;

        $sql = strtr($sql, [
            '%%WWWROOT%%'   => rtrim($CFG->wwwroot, '/'),
            '%%USERID%%'    => (int) $USER->id,
            '%%STARTTIME%%' => $this->week_start(),
            '%%ENDTIME%%'   => $this->week_end(),
            '%%C%%'         => ':',
            '%%S%%'         => ';',
            '%%Q%%'         => '?',
        ]);

        // Any remaining %%TOKEN%% are unknown.
        if (preg_match_all('/%%.+?%%/', $sql, $m)) {
            $tokens = implode(', ', array_unique($m[0]));
            throw new \moodle_exception('error:unknownplaceholders', 'local_sqlchat', '', $tokens);
        }

        // Detect unresolved named parameters (:param_name) outside string literals.
        $stripped = $this->strip_strings($sql);
        // Exclude Postgres cast operator (::type) by requiring no preceding colon.
        if (preg_match_all('/(?<!:):\b([a-z_][a-z0-9_]*)\b/i', $stripped, $m)) {
            $params = implode(', ', array_map(static fn($p) => ':' . $p, array_unique($m[1])));
            throw new \moodle_exception('error:namedparams', 'local_sqlchat', '', $params);
        }

        return $sql;
    }

    /** Unix timestamp for Monday 00:00:00 of the current week. */
    private function week_start(): int {
        return (int) strtotime('monday this week 00:00:00');
    }

    /** Unix timestamp for Sunday 23:59:59 of the current week. */
    private function week_end(): int {
        return (int) strtotime('sunday this week 23:59:59');
    }

    /** Remove quoted strings so named-param scan does not false-positive on literals. */
    private function strip_strings(string $sql): string {
        $sql = preg_replace("/'(?:''|\\\\.|[^'\\\\])*'/", "''", $sql) ?? $sql;
        $sql = preg_replace('/"(?:""|\\\\.|[^"\\\\])*"/', '""', $sql) ?? $sql;
        return $sql;
    }
}
