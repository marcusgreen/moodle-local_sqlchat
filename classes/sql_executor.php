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
     * Dry-run a SELECT to check that every referenced table/column exists,
     * without returning rows. Runs EXPLAIN, which parses and plans the query
     * (touching no data) and raises "Unknown column"/"Unknown table" for any
     * identifier the LLM invented — the common failure mode where a model
     * fabricates a column such as user_enrolments.courseid.
     *
     * Placeholders that are resolved later by api::execute (%%TOKEN%% and
     * :named params) are neutralised to NULL first so EXPLAIN validates the
     * identifiers rather than choking on the unresolved tokens. The original
     * SQL is never mutated — only this throwaway probe copy is.
     *
     * @param string $sql Unprefixed, security-validated SELECT SQL.
     * @return string|null Null when the query is structurally valid; otherwise
     *  the database error message (suitable for feeding back to the LLM).
     */
    public function dry_run(string $sql): ?string {
        global $CFG;
        $dbtype = $CFG->dbtype ?? 'mariadb';
        // EXPLAIN of a SELECT parses + plans on MySQL/MariaDB and PostgreSQL.
        // Skip silently on engines where that guarantee does not hold.
        if (!in_array($dbtype, ['mariadb', 'mysqli', 'pgsql'], true)) {
            return null;
        }

        // Neutralise deferred placeholders. Named-param regex excludes '::'
        // (a PostgreSQL cast) via the lookbehind so casts survive intact.
        $probe = preg_replace('/%%[A-Za-z0-9_]+%%/', 'NULL', $sql);
        $probe = preg_replace('/(?<!:):[A-Za-z_][A-Za-z0-9_]*/', 'NULL', $probe);

        $db = $this->get_connection();
        $probe = $this->apply_prefix($probe, $db);

        try {
            $rs = $db->get_recordset_sql('EXPLAIN ' . $probe);
            $rs->close();
            return null;
        } catch (\dml_exception $e) {
            // debuginfo carries the specific driver message ("Unknown column
            // 'ue.courseid'"); getMessage() is only the generic wrapper. The
            // specific text is what lets the model repair the SQL.
            return !empty($e->debuginfo) ? $e->debuginfo : $e->getMessage();
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
        // Lookbehind excludes '.' as well as word chars so a column reference
        // whose name matches a table (e.g. quiz.course, forum_discussions.forum)
        // is never prefixed. Bare table names in FROM/JOIN are never dotted.
        $pattern = '/(?<![A-Za-z0-9_.])(' . $alts . ')(?![A-Za-z0-9_])/';
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
