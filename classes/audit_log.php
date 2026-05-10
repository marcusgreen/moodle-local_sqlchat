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
 * Insert and update rows in the local_sqlchat_log audit table.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_log {

    /** Database table name (Moodle prefixes it automatically). */
    private const TABLE = 'local_sqlchat_log';

    /**
     * Insert a row recording a generation attempt.
     *
     * @param string $question The user's question.
     * @param string|null $sql SQL produced by the LLM, if any.
     * @param bool $success True when the generation succeeded.
     * @param string|null $error Error message when generation failed.
     * @param int $latencyms Generation latency in milliseconds.
     * @param int $tokensused Tokens reported by the backend (0 when unknown).
     * @return int The new log row id.
     */
    public function record_generation(
        string $question,
        ?string $sql,
        bool $success,
        ?string $error,
        int $latencyms,
        int $tokensused
    ): int {
        global $DB, $USER;
        $row = (object) [
            'userid' => (int) ($USER->id ?? 0),
            'question' => $question,
            'sqlgenerated' => $sql,
            'success' => $success ? 1 : 0,
            'errormsg' => $error,
            'rowsreturned' => null,
            'tokensused' => $tokensused,
            'latencyms' => $latencyms,
            'timecreated' => time(),
        ];
        return (int) $DB->insert_record(self::TABLE, $row);
    }

    /**
     * Update an existing log row with execution outcome.
     *
     * @param int $logid Id returned by record_generation().
     * @param bool $success Whether execution succeeded.
     * @param int|null $rowsreturned Row count when known.
     * @param string|null $error Error message when execution failed.
     * @return void
     */
    public function record_execution(
        int $logid,
        bool $success,
        ?int $rowsreturned,
        ?string $error
    ): void {
        global $DB;
        if ($logid <= 0) {
            return;
        }
        $existing = $DB->get_record(self::TABLE, ['id' => $logid]);
        if (!$existing) {
            return;
        }
        $existing->success = $success ? 1 : 0;
        $existing->rowsreturned = $rowsreturned;
        if ($error !== null) {
            $existing->errormsg = trim(($existing->errormsg ?? '') . "\nexec: " . $error);
        }
        $DB->update_record(self::TABLE, $existing);
    }
}
