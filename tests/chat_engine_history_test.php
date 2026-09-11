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
 * Unit tests for chat_engine's conversation-history prompt block.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_sqlchat\chat_engine
 */
class chat_engine_history_test extends \advanced_testcase {

    /**
     * Call the private build_history_block() method via reflection.
     *
     * @param array $history
     * @return string
     */
    private function build_history_block(array $history): string {
        $engine = new chat_engine();
        $method = new \ReflectionMethod(chat_engine::class, 'build_history_block');
        $method->setAccessible(true);
        return $method->invoke($engine, $history);
    }

    public function test_empty_history_produces_no_block(): void {
        $this->assertSame('', $this->build_history_block([]));
    }

    public function test_single_turn_is_rendered(): void {
        $block = $this->build_history_block([
            ['question' => 'list active users', 'sql' => 'SELECT id FROM user WHERE suspended = 0'],
        ]);
        $this->assertStringContainsString('Conversation so far', $block);
        $this->assertStringContainsString('Q: list active users', $block);
        $this->assertStringContainsString('SQL: SELECT id FROM user WHERE suspended = 0', $block);
    }

    public function test_only_last_three_turns_kept(): void {
        $history = [];
        for ($i = 1; $i <= 5; $i++) {
            $history[] = ['question' => "question {$i}", 'sql' => "SELECT {$i}"];
        }
        $block = $this->build_history_block($history);

        $this->assertStringNotContainsString('question 1', $block);
        $this->assertStringNotContainsString('question 2', $block);
        $this->assertStringContainsString('question 3', $block);
        $this->assertStringContainsString('question 4', $block);
        $this->assertStringContainsString('question 5', $block);
    }

    public function test_long_fields_are_truncated(): void {
        $longquestion = str_repeat('a', 400);
        $longsql = 'SELECT ' . str_repeat('b', 600);
        $block = $this->build_history_block([
            ['question' => $longquestion, 'sql' => $longsql],
        ]);

        $this->assertStringContainsString('…', $block);
        $this->assertStringNotContainsString($longquestion, $block);
        $this->assertStringNotContainsString($longsql, $block);
        // The question line (up to the newline before "SQL:") must be capped well under the
        // full 400-char input — allow slack for the "Q: " prefix and ellipsis.
        $questionline = strstr(strstr($block, 'Q: '), "\nSQL:", true);
        $this->assertLessThan(320, strlen($questionline));
    }

    public function test_blank_entries_are_skipped(): void {
        $block = $this->build_history_block([
            ['question' => '', 'sql' => ''],
        ]);
        $this->assertSame('', $block);
    }

    public function test_missing_keys_are_tolerated(): void {
        // Defensive decode in report_sql/edit.php should already filter these out, but the
        // engine itself must not fatal on a malformed entry.
        $block = $this->build_history_block([
            ['question' => 'list users'],
        ]);
        $this->assertStringContainsString('Q: list users', $block);
        $this->assertStringContainsString('SQL:', $block);
    }
}
