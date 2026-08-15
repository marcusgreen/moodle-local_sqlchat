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
        $probe = preg_replace('/%%[A-Za-z0-9_]+(?:\([^%]*\))?%%/', 'NULL', $sql);
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
     * Turn a raw driver error from dry_run() into a clean, user-facing message.
     *
     * The raw debuginfo is verbose: the driver reason ("Unknown column
     * 'e.courseid' in 'ON'") followed by the entire failing EXPLAIN SQL and a
     * params dump. That detail helps the LLM repair the query, but is noise to
     * a human. This strips it to the reason line, then — when the reason names
     * a missing table or column — verifies that against the live schema so the
     * message states the confirmed cause and (for columns) lists the real
     * columns the table does have, rather than parroting the driver text.
     *
     * @param string $sql The unprefixed SELECT that failed the dry run.
     * @param string $rawerror The debuginfo string returned by dry_run().
     * @return string A clean, verified message suitable for display to the user.
     */
    public function diagnose(string $sql, string $rawerror): string {
        $reason = $this->clean_db_error($rawerror);
        $db = $this->get_connection();

        // Missing column: MySQL/MariaDB "Unknown column 'x'" or PostgreSQL
        // 'column "x" does not exist'. The identifier may be alias-qualified.
        if (preg_match('/Unknown column [\'"]([^\'"]+)[\'"]/i', $reason, $m)
                || preg_match('/column [\'"]?([A-Za-z0-9_.]+)[\'"]? does not exist/i', $reason, $m)) {
            [$alias, $column] = $this->split_qualified($m[1]);
            $table = $alias !== null ? $this->resolve_alias($sql, $alias) : null;
            if ($table !== null) {
                $columns = $this->table_columns($db, $table);
                if ($columns !== null && !in_array(strtolower($column), $columns, true)) {
                    $shown = array_slice($columns, 0, 40);
                    if (count($columns) > 40) {
                        $shown[] = '…';
                    }
                    $message = get_string('error:nocolumn', 'local_sqlchat', (object) [
                        'column' => $column,
                        'table' => $table,
                        'columns' => implode(', ', $shown),
                    ]);
                    // The invented column often lives on a related table (e.g.
                    // course_enrolments.courseid → enrol.courseid). Point at any
                    // similarly-named table that actually has it.
                    $needle = strtolower($column);
                    $elsewhere = $this->related_tables($db, $table, function ($candidate) use ($db, $needle) {
                        $cols = $this->table_columns($db, $candidate);
                        return $cols !== null && in_array($needle, $cols, true);
                    });
                    if ($elsewhere) {
                        $message .= ' ' . get_string('error:columnelsewhere', 'local_sqlchat', (object) [
                            'column' => $column,
                            'tables' => implode(', ', $elsewhere),
                        ]);
                    }
                    return $message;
                }
            }
            return $reason;
        }

        // Missing table: MySQL/MariaDB "Table '...' doesn't exist" or
        // PostgreSQL 'relation "..." does not exist'.
        if (preg_match('/Table [\'"]([^\'"]+)[\'"] doesn\'t exist/i', $reason, $m)
                || preg_match('/relation [\'"]([^\'"]+)[\'"] does not exist/i', $reason, $m)) {
            [, $table] = $this->split_qualified($m[1]); // Drop any db.name prefix.
            $table = $this->strip_prefix($table);
            if (!$this->table_columns($db, $table)) {
                $message = get_string('error:notable', 'local_sqlchat', (object) ['table' => $table]);
                // Point at real tables with a similar name (enrolments → enrol,
                // user_enrolments, enrol_flatfile, ...).
                $similar = $this->related_tables($db, $table);
                if ($similar) {
                    $message .= ' ' . get_string('error:tableelsewhere', 'local_sqlchat', (object) [
                        'tables' => implode(', ', $similar),
                    ]);
                }
                return $message;
            }
        }

        return $reason;
    }

    /**
     * Reduce a Moodle dml debuginfo string to its first, human-readable line:
     * the driver reason, without the appended SQL and params dump.
     *
     * @param string $rawerror Raw debuginfo.
     * @return string The reason line.
     */
    private function clean_db_error(string $rawerror): string {
        $reason = preg_split('/\r?\n/', trim($rawerror))[0];
        // Some drivers append the query on the same line — cut at EXPLAIN.
        $reason = preg_split('/\s+EXPLAIN\s+/i', $reason)[0];
        return trim($reason);
    }

    /**
     * Split an identifier into [alias, name]. "e.courseid" → ['e', 'courseid'];
     * "courseid" → [null, 'courseid'].
     *
     * @param string $identifier Possibly qualified identifier.
     * @return array{0: ?string, 1: string}
     */
    private function split_qualified(string $identifier): array {
        if (strpos($identifier, '.') !== false) {
            $parts = explode('.', $identifier);
            $name = array_pop($parts);
            return [array_pop($parts), $name];
        }
        return [null, $identifier];
    }

    /**
     * Find the table an alias is bound to in a FROM/JOIN clause of the SQL.
     * "FROM user_enrolments e" with alias "e" → "user_enrolments". When the
     * alias is itself an unaliased table name, returns it unchanged.
     *
     * @param string $sql The unprefixed SELECT.
     * @param string $alias The alias to resolve.
     * @return string|null The bare table name, or null when it can't be found.
     */
    private function resolve_alias(string $sql, string $alias): ?string {
        $q = preg_quote($alias, '/');
        if (preg_match('/(?:from|join)\s+([a-z][a-z0-9_]*)\s+(?:as\s+)?' . $q . '\b/i', $sql, $m)) {
            return $this->strip_prefix($m[1]);
        }
        // No binding found: the identifier may be an unaliased table name.
        if (preg_match('/(?:from|join)\s+' . $q . '\b/i', $sql)) {
            return $this->strip_prefix($alias);
        }
        return null;
    }

    /**
     * Lowercased column names of a table, or null when the table does not exist.
     *
     * @param \moodle_database $db Connection.
     * @param string $table Unprefixed table name.
     * @return string[]|null
     */
    private function table_columns(\moodle_database $db, string $table): ?array {
        try {
            $columns = $db->get_columns($table);
        } catch (\Throwable $e) {
            return null;
        }
        return $columns ? array_map('strtolower', array_keys($columns)) : null;
    }

    /**
     * Find existing tables whose name is related to $table, ranked by relevance,
     * optionally keeping only those that pass $filter (e.g. having a column).
     *
     * Relevance is token-rarity weighted: a table name is split on "_" and each
     * token contributes a score inversely proportional to how many tables carry
     * it. A rare token like "enrol" scores far higher than a ubiquitous one like
     * "user", so `enrol`, `user_enrolments`, `enrol_flatfile` outrank the dozens
     * of unrelated `*_user_*` tables that a plain any-token match would surface.
     *
     * @param \moodle_database $db Connection.
     * @param string $table The missing/invalid table name (unprefixed).
     * @param callable|null $filter Optional predicate(string $candidate): bool.
     * @param int $limit Maximum suggestions to return.
     * @return string[] Related table names, most relevant first.
     */
    private function related_tables(
        \moodle_database $db,
        string $table,
        ?callable $filter = null,
        int $limit = 6
    ): array {
        $qtokens = array_filter(explode('_', strtolower($table)), static fn($t) => strlen($t) >= 3);
        if (!$qtokens) {
            return [];
        }
        $tables = array_values($db->get_tables(true));
        $weights = $this->token_weights($tables);

        // Score by name only first (cheap); apply the filter afterwards so
        // any per-candidate get_columns() lookups happen for a bounded few.
        $scored = [];
        foreach ($tables as $candidate) {
            if ($candidate === $table) {
                continue;
            }
            $score = $this->relatedness($qtokens, $candidate, $weights);
            if ($score > 0) {
                $scored[$candidate] = $score;
            }
        }
        arsort($scored);

        $out = [];
        foreach (array_keys($scored) as $candidate) {
            if ($filter !== null && !$filter($candidate)) {
                continue;
            }
            $out[] = $candidate;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * Inverse document frequency weight per token: rarer tokens weigh more.
     *
     * @param string[] $tables All table names.
     * @return array<string,float> token => weight (1 / number of tables using it).
     */
    private function token_weights(array $tables): array {
        $freq = [];
        foreach ($tables as $t) {
            foreach (array_unique(explode('_', strtolower($t))) as $token) {
                if (strlen($token) >= 3) {
                    $freq[$token] = ($freq[$token] ?? 0) + 1;
                }
            }
        }
        $weights = [];
        foreach ($freq as $token => $count) {
            $weights[$token] = 1.0 / $count;
        }
        return $weights;
    }

    /**
     * Rarity-weighted relatedness of a candidate table to the query tokens.
     * Each query token takes its best match against the candidate's tokens:
     * an exact match earns the token's full weight, a substring match half.
     *
     * @param string[] $qtokens Query table tokens (length >= 3).
     * @param string $candidate Candidate table name.
     * @param array<string,float> $weights Token weights.
     * @return float
     */
    private function relatedness(array $qtokens, string $candidate, array $weights): float {
        $ctokens = array_filter(explode('_', strtolower($candidate)), static fn($t) => strlen($t) >= 3);
        $score = 0.0;
        foreach ($qtokens as $qt) {
            $weight = $weights[$qt] ?? 1.0;
            $best = 0.0;
            foreach ($ctokens as $ct) {
                if ($qt === $ct) {
                    $best = max($best, $weight);
                } else if (strpos($ct, $qt) !== false || strpos($qt, $ct) !== false) {
                    $best = max($best, $weight * 0.5);
                }
            }
            $score += $best;
        }
        return $score;
    }

    /**
     * Strip $CFG->prefix from a table name if present, so schema lookups (which
     * expect unprefixed names) work whether or not the driver quoted the prefix.
     *
     * @param string $table Table name, possibly prefixed.
     * @return string Unprefixed table name.
     */
    private function strip_prefix(string $table): string {
        global $CFG;
        $prefix = (string) ($CFG->prefix ?? '');
        if ($prefix !== '' && strpos($table, $prefix) === 0) {
            return substr($table, strlen($prefix));
        }
        return $table;
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
