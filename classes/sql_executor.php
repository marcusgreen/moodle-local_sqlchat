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
 * Run validated SELECT statements through a read-only DB connection when configured,
 * falling back to the default $DB. Always injects a LIMIT and a statement timeout.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_executor {

    /**
     * Execute a SELECT statement.
     *
     * @param string $sql Validated SELECT SQL.
     * @return array Rows returned by the query.
     * @throws \moodle_exception When execution fails.
     */
    public function run(string $sql): array {
        $maxrows = (int) (get_config('local_sqlchat', 'maxrows') ?: 1000);
        $timeoutsec = (int) (get_config('local_sqlchat', 'timeoutsec') ?: 5);

        (new dialect_checker())->check($sql);

        $db = $this->get_connection();
        $sql = $this->apply_prefix($sql, $db);
        $sql = $this->ensure_limit($sql, $maxrows);

        $this->set_timeout($db, $timeoutsec);

        try {
            $rs = $db->get_recordset_sql($sql);
            $rows = [];
            foreach ($rs as $row) {
                $rows[] = $row;
            }
            $rs->close();
            return $rows;
        } catch (\dml_exception $e) {
            throw new \moodle_exception(
                'error:execfailed',
                'local_sqlchat',
                '',
                $e->getMessage()
            );
        }
    }

    /**
     * Prefix every bare Moodle table name in the SQL with $CFG->prefix.
     * Tokens already starting with the prefix are left alone, so callers can
     * safely pass either prefixed or unprefixed SQL.
     *
     * @param string $sql SQL with unprefixed table names.
     * @param \moodle_database $db Connection used to enumerate tables.
     * @return string SQL with table names prefixed for execution.
     */
    private function apply_prefix(string $sql, \moodle_database $db): string {
        global $CFG;
        $prefix = (string) ($CFG->prefix ?? '');
        if ($prefix === '') {
            return $sql;
        }
        $tables = $db->get_tables(true);
        if (!$tables) {
            return $sql;
        }
        $names = array_values($tables);
        usort($names, static fn($a, $b) => strlen($b) <=> strlen($a));
        $alts = implode('|', array_map('preg_quote', $names));
        $pattern = '/(?<![A-Za-z0-9_])(' . $alts . ')(?![A-Za-z0-9_])/';
        return preg_replace_callback(
            $pattern,
            static fn($m) => $prefix . $m[1],
            $sql
        ) ?? $sql;
    }

    /**
     * Append a LIMIT clause unless one is already present.
     *
     * @param string $sql Input SQL.
     * @param int $max Cap to apply when no LIMIT is present.
     * @return string
     */
    private function ensure_limit(string $sql, int $max): string {
        if (preg_match('/\bLIMIT\s+\d+/i', $sql)) {
            return $sql;
        }
        return rtrim($sql) . " LIMIT {$max}";
    }

    /**
     * Return a read-only DB connection if credentials are configured, otherwise the default $DB.
     *
     * Read-only credentials are read from $CFG: dbreadonly_user, dbreadonly_pass.
     * They live in $CFG (not plugin settings) so the password is never exposed via the admin UI.
     *
     * @return \moodle_database
     */
    private function get_connection(): \moodle_database {
        global $CFG, $DB;

        if (empty($CFG->dbreadonly_user) || empty($CFG->dbreadonly_pass)) {
            return $DB;
        }

        $ro = \moodle_database::get_driver_instance($CFG->dbtype, $CFG->dblibrary);
        $ro->connect(
            $CFG->dbhost,
            $CFG->dbreadonly_user,
            $CFG->dbreadonly_pass,
            $CFG->dbname,
            $CFG->prefix,
            $CFG->dboptions ?? []
        );
        return $ro;
    }

    /**
     * Apply a per-session statement timeout where the driver supports it.
     *
     * @param \moodle_database $db Connection to configure.
     * @param int $seconds Timeout in seconds.
     * @return void
     */
    private function set_timeout(\moodle_database $db, int $seconds): void {
        global $CFG;
        try {
            if ($CFG->dbtype === 'pgsql') {
                $db->execute('SET LOCAL statement_timeout = ' . ($seconds * 1000));
            } else if (in_array($CFG->dbtype, ['mariadb', 'mysqli'], true)) {
                $db->execute('SET SESSION max_statement_time = ' . $seconds);
            }
        } catch (\Throwable $e) {
            debugging('local_sqlchat: failed to set statement timeout: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
