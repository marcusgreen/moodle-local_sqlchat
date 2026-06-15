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
 * Public API for local_sqlchat consumers (e.g. local_reportsources).
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
     * runner (e.g. local_reportsources). This method does not execute SQL.
     *
     * @param string $question Plain-English description of the desired data.
     * @param int|null $contextid Context for the AI bridge; defaults to system context.
     * @return result
     */
    public static function generate_sql(string $question, ?int $contextid = null): result {
        $context = $contextid !== null
            ? \context::instance_by_id($contextid)
            : \context_system::instance();
        require_capability('local/sqlchat:use', $context);

        return (new chat_engine())->ask($question, $context->id);
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
