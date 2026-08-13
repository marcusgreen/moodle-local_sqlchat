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
 * Orchestrates: schema -> prompt -> LLM (via tool_ai_bridge) -> validator.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_engine {

    /**
     * Generate SQL for a natural-language question.
     *
     * @param string $question The user's question.
     * @param int|null $contextid Context to pass to the AI bridge; defaults to the system context.
     * @param string $extrarules Extra prompt rules appended verbatim to the Rules block; a caller
     *  (e.g. local_reportsources) supplies instructions for its own %%…%% tokens. Empty by default.
     * @return result
     */
    public function ask(string $question, ?int $contextid = null, string $extrarules = ''): result {
        $start = microtime(true);
        $audit = new audit_log();

        $sql = null;
        $raw = '';
        $ailatencyms = 0;
        try {
            $compressor = new schema_compressor();
            $mode = (string) (get_config('local_sqlchat', 'retrieval') ?: 'full');
            [$schema, $isddl] = $this->retrieve_schema($compressor, $mode, $question);

            $prompt = $this->build_prompt($schema, $question, $isddl, $extrarules);

            $contextid = $contextid ?? \context_system::instance()->id;
            $backend = (string) (get_config('local_sqlchat', 'backend') ?: 'core_ai_subsystem');
            $bridge = new \tool_ai_bridge\ai_bridge($contextid, 'local_sqlchat', $backend);
            $purpose = (string) (get_config('local_sqlchat', 'purpose') ?: 'feedback');
            $aistart = microtime(true);
            $raw = $bridge->perform_request($prompt, $purpose);
            $ailatencyms = (int) round((microtime(true) - $aistart) * 1000);

            $sql = $this->extract_sql($raw);
            if ($sql === '') {
                throw new \moodle_exception('error:llmempty', 'local_sqlchat');
            }

            (new sql_validator())->check($sql);

            // Correctness gate: dry-run the SQL so invented tables/columns (e.g.
            // user_enrolments.courseid) are caught before returning. On failure,
            // feed the database error back to the model for a single repair
            // attempt, then re-validate. Security validation always runs first.
            $dberror = (new sql_executor())->dry_run($sql);
            if ($dberror !== null) {
                $repairprompt = $prompt . "\n\nYour previous SQL was rejected by the database:\n"
                    . "  {$sql}\nError: {$dberror}\n"
                    . "Return only the corrected SELECT statement — no explanation, no fences.";
                $aistart = microtime(true);
                $raw = $bridge->perform_request($repairprompt, $purpose);
                $ailatencyms += (int) round((microtime(true) - $aistart) * 1000);

                $sql = $this->extract_sql($raw);
                if ($sql === '') {
                    throw new \moodle_exception('error:llmempty', 'local_sqlchat');
                }
                (new sql_validator())->check($sql);

                $dberror = (new sql_executor())->dry_run($sql);
                if ($dberror !== null) {
                    throw new \moodle_exception('error:schemainvalid', 'local_sqlchat', '', $dberror);
                }
            }
        } catch (\Throwable $e) {
            $latencyms = (int) round((microtime(true) - $start) * 1000);
            $audit->record_generation($question, $sql, false, $e->getMessage(), $latencyms, 0);
            throw $e;
        }

        $latencyms = (int) round((microtime(true) - $start) * 1000);
        $logid = $audit->record_generation($question, $sql, true, null, $latencyms, 0);

        $result = new result();
        $result->sql = $sql;
        $result->raw_response = $raw;
        $result->prompt = $prompt;
        $result->latency_ms = $latencyms;
        $result->ai_latency_ms = $ailatencyms;
        $result->tokens_used = 0;
        $result->logid = $logid;
        return $result;
    }

    /**
     * Resolve the schema text to embed in the prompt for the configured retrieval mode.
     *
     * Modes:
     *  - full      Compact one-line-per-table schema for every table.
     *  - bm25      Compact schema reduced to the tables relevant to the question.
     *  - ddl       CREATE TABLE DDL for every table.
     *  - ddl_bm25  CREATE TABLE DDL for the BM25-relevant tables only.
     *
     * @param schema_compressor $compressor
     * @param string $mode Configured retrieval mode.
     * @param string $question Natural-language question (used by the BM25 modes).
     * @return array{0: string, 1: bool} The schema text and whether it is DDL (drives the legend).
     */
    private function retrieve_schema(schema_compressor $compressor, string $mode, string $question): array {
        switch ($mode) {
            case 'bm25':
                return [(new bm25_retriever($compressor))->retrieve($question), false];
            case 'ddl':
                return [$compressor->get_ddl(), true];
            case 'ddl_bm25':
                $names = (new bm25_retriever($compressor))->retrieve_tables($question);
                return [$compressor->get_ddl($names ?: null), true];
            case 'full':
            default:
                return [$compressor->get_compact(), false];
        }
    }

    /**
     * Build the prompt sent to the LLM.
     *
     * @param string $schema Schema text (compact one-liners or CREATE TABLE DDL).
     * @param string $question The user's question.
     * @param bool $isddl Whether $schema is CREATE TABLE DDL rather than the compact format.
     * @param string $extrarules Caller-supplied rules appended verbatim to the Rules block.
     * @return string
     */
    private function build_prompt(string $schema, string $question, bool $isddl = false, string $extrarules = ''): string {
        global $CFG;
        $dialect = match ($CFG->dbtype ?? 'mariadb') {
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'MS SQL Server',
            'oci' => 'Oracle',
            default => 'MariaDB/MySQL',
        };

        $schemalegend = $isddl
            ? 'Schema (CREATE TABLE statements; table/reference names are unprefixed):'
            : 'Schema (table(col1, col2 PK, fkcol→reftable, ...)):';

        // A caller (e.g. local_reportsources) may append its own rules — token
        // instructions, etc. Trimmed and prefixed with a newline so it slots into
        // the Rules list; empty when the caller supplied nothing.
        $extrarules = trim($extrarules);
        $extrarules = $extrarules === '' ? '' : "\n" . $extrarules;

        return <<<PROMPT
You are a Moodle SQL generator. Output ONLY a single SELECT statement.
No explanation. No markdown fences. No trailing semicolon.

Rules:
- {$dialect} syntax.
- Use UNPREFIXED table names exactly as shown in the schema below
  (e.g. `FROM user`, not `FROM mdl_user`). The runtime adds the prefix
  before execution; output must remain readable to humans.
- SELECT only. Never write.
- When a table in the FROM/JOIN clauses is given an alias, that alias must be
  UNIQUE. Never reuse the same alias for two tables (e.g. do NOT alias both
  user_enrolments and enrol as `e`). Duplicate aliases fail with "Not unique
  table/alias".
- Every selected column must have a UNIQUE output name. When you SELECT the
  same column name from more than one table (e.g. c.id and cm.id), alias each
  so the result has no duplicate column names — e.g. c.id AS courseid,
  cm.id AS cmid, m.id AS moduleid. Duplicate output names fail at execution.
- Always include LIMIT 100 unless the question specifies a different limit.
- Never reference: user.password, user.secret, user.auth_*token,
  user_password_history.*, oauth2_*.client_secret,
  config.value where name LIKE '%key%' OR '%secret%'.
- Moodle relationships (use these join paths; do NOT invent columns):
  - Enrolment: a user is enrolled in a course through user_enrolments, NOT
    directly. Join user u -> user_enrolments ue ON ue.userid = u.id
    -> enrol e ON e.id = ue.enrolid -> course c ON c.id = e.courseid.
    The enrol table has NO userid column; never join user directly to enrol
    or course.
  - Roles: role_assignments (userid, roleid, contextid); the role name is in
    role. A course-level assignment has a context row where contextlevel = 50
    and instanceid = course.id.
  - Context: the context table is polymorphic — context.instanceid points at a
    different table depending on context.contextlevel: 10 = system, 30 = user
    (instanceid = user.id), 40 = course category, 50 = course
    (instanceid = course.id), 70 = course module (instanceid = course_modules.id),
    80 = block. Tables carrying a contextid column join context ON context.id =
    x.contextid; filter by contextlevel to pin the level.
  - Activity modules: course_modules (cm) is a generic link table, NOT an
    activity. cm.module -> modules.id gives the module TYPE (name in
    modules.name, e.g. 'quiz'); cm.instance is the id in that module's OWN table
    (e.g. quiz.id when modules.name = 'quiz'), NOT cm.id. cm.course -> course.id.
    Never join cm.id to an activity table's id.
  - Grades: grade_grades.itemid -> grade_items.id and grade_grades.userid ->
    user.id; the numeric grade is grade_grades.finalgrade. An activity's grade
    item has grade_items.itemtype = 'mod', itemmodule = <module name> and
    iteminstance = <activity id>. grade_items.courseid -> course.id.{$extrarules}

{$schemalegend}
{$schema}

Question: {$question}

SQL:
PROMPT;
    }

    /**
     * Strip markdown fences, leading commentary, and trailing punctuation from the LLM output.
     *
     * @param string $raw Raw LLM response.
     * @return string Cleaned SQL or empty string when nothing recognisable was returned.
     */
    private function extract_sql(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/```(?:sql)?\s*(.*?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        if (preg_match('/((?:WITH|SELECT)\b.*)/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        $raw = rtrim($raw, "; \t\n\r");

        // The prompt asks for bare, unprefixed table names but LLMs often quote identifiers
        // (`user`, "user"). Downstream the runtime brace-wraps bare names, so quoting breaks it.
        // Backticks only ever quote identifiers, so drop them all. Double quotes can be string
        // literals in MySQL mode, so only unwrap those that wrap a plain identifier token
        // (word chars and dots, no spaces) — real string literals use single quotes.
        $raw = str_replace('`', '', $raw);
        $raw = preg_replace('/"([A-Za-z_][A-Za-z0-9_.]*)"/', '$1', $raw) ?? $raw;

        return $raw;
    }
}
