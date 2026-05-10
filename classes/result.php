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
 * Result of a chat_engine::ask() call.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result {
    /** @var string Validated SQL produced by the LLM. */
    public string $sql = '';

    /** @var string Raw LLM response (pre-cleaning). */
    public string $raw_response = '';

    /** @var int Total time spent generating, in milliseconds. */
    public int $latency_ms = 0;

    /** @var int Token usage if reported by the backend; otherwise 0. */
    public int $tokens_used = 0;

    /** @var int Audit log row id (0 if no log was written). */
    public int $logid = 0;
}
