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

declare(strict_types=1);

namespace local_sqlchat;

/**
 * Tests that the curated implied foreign keys reach the compressed schema and DDL.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_sqlchat\schema_compressor
 */
final class schema_compressor_test extends \advanced_testcase {
    /**
     * A conventional implied key (course_modules.course -> course.id), undeclared in install.xml,
     * appears as an arrow in the compact schema and as REFERENCES in the DDL.
     */
    public function test_implied_key_reaches_compact_and_ddl(): void {
        $this->resetAfterTest();
        $compressor = new schema_compressor();

        $compact = $compressor->get_compact(true);
        $this->assertMatchesRegularExpression('/course_modules\([^)]*\bcourse→course\b/', $compact);

        $ddl = $compressor->get_ddl(['course_modules'], true);
        $this->assertStringContainsString('REFERENCES course(id)', $ddl);

        // Walking every install.xml can surface core XMLDB debugging about third-party plugins' own
        // schema quirks (e.g. a CHAR NOT NULL column defaulting to ''); unrelated to implied keys.
        $this->resetDebugging();
    }

    /**
     * A curated key with a non-id reference column (block_instances.blockname -> block.name) keeps its
     * target column in both the compact arrow and the DDL REFERENCES clause.
     */
    public function test_non_id_refcol_is_preserved(): void {
        $this->resetAfterTest();
        $compressor = new schema_compressor();

        $compact = $compressor->get_compact(true);
        $this->assertStringContainsString('blockname→block.name', $compact);

        $ddl = $compressor->get_ddl(['block_instances'], true);
        $this->assertStringContainsString('REFERENCES block(name)', $ddl);

        // See test_implied_key_reaches_compact_and_ddl(): tolerate core XMLDB parse debugging.
        $this->resetDebugging();
    }
}
