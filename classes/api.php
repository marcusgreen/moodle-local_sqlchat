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
 * Public API for local_sqlchat consumers (e.g. report_sql).
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /**
     * Generate validated SQL for a natural-language question.
     *
     * The caller is responsible for executing the returned SQL via its own
     * runner (e.g. report_sql). This method does not execute SQL.
     *
     * @param string $question Plain-English description of the desired data.
     * @param int|null $contextid Context for the AI bridge; defaults to system context.
     * @param string $extrarules Optional extra prompt rules appended verbatim to the
     *  Rules block. This plugin is agnostic about their content — a caller such as
     *  report_sql passes the instructions describing its own %%…%% tokens
     *  so the generated SQL is reusable there. Standalone use leaves it empty, so no
     *  caller-specific tokens are ever emitted.
     * @param array $history Prior turns in this conversation, oldest first, as
     *  ['question' => string, 'sql' => string] entries. Lets a caller such as report_sql
     *  carry a multi-turn editing session (refine, "also add X", "instead of Y") into the
     *  prompt instead of only the single most recent SQL. This plugin holds no session state
     *  of its own — the caller owns and passes the turn list. Empty by default.
     * @return result
     */
    public static function generate_sql(
        string $question,
        ?int $contextid = null,
        string $extrarules = '',
        array $history = []
    ): result {
        $context = $contextid !== null
            ? \context::instance_by_id($contextid)
            : \context_system::instance();
        require_capability('local/sqlchat:use', $context);

        // Site-wide admin rules (schema hints for local tables, conventions, etc.)
        // apply to every caller. They sit between the built-in core rules and any
        // caller-supplied rules: core rules → admin setting → caller rules.
        $adminrules = trim((string) get_config('local_sqlchat', 'extrarules'));
        $extrarules = trim($extrarules);
        $mergedrules = implode("\n", array_filter([$adminrules, $extrarules]));

        $result = (new chat_engine())->ask($question, $context->id, $mergedrules, $history);

        // Emit {tablename} braces when report_sql's showbraces setting is on.
        // No-op when report_sql is absent (get_config returns false); execution
        // still works because apply_prefix unwraps the braces.
        if ($result->sql !== '' && get_config('report_sql', 'showbraces')) {
            $result->sql = sql_executor::brace_tables($result->sql);
        }

        return $result;
    }

    /**
     * The calling user's own recent prompt history, newest first.
     *
     * Scoped to the current user — callers cannot read another user's history.
     *
     * @param int $limit Maximum rows to return.
     * @return array Log rows (id, question, sqlgenerated, success, rowsreturned, timecreated).
     */
    public static function history(int $limit = 20): array {
        global $USER;
        require_capability('local/sqlchat:use', \context_system::instance());
        return (new audit_log())->get_user_history((int) $USER->id, $limit);
    }

    /**
     * Validate an arbitrary SQL string against the SELECT-only policy.
     *
     * @param string $sql SQL to check.
     * @return void
     * @throws \moodle_exception When the SQL violates the policy.
     */
    public static function validate(string $sql): void {
        (new sql_validator())->check($sql);
    }

    /**
     * Run a validated SELECT and return its rows.
     *
     * When a log id is provided, the audit row is updated with the execution outcome.
     *
     * @param string $sql Validated SQL.
     * @param int $logid Optional id of the generation log row to annotate.
     * @return array
     */
    public static function execute(string $sql, int $logid = 0): array {
        $sql = (new adhoc_placeholder_processor())->process($sql);
        self::validate($sql);
        $audit = new audit_log();
        try {
            $rows = (new sql_executor())->run($sql);
        } catch (\Throwable $e) {
            $audit->record_execution($logid, false, null, $e->getMessage());
            throw $e;
        }
        $audit->record_execution($logid, true, count($rows), null);
        return $rows;
    }
}
