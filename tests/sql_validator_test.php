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
 * Unit tests for sql_validator.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_sqlchat\sql_validator
 */
class sql_validator_test extends \advanced_testcase {

    private sql_validator $validator;

    protected function setUp(): void {
        parent::setUp();
        $this->validator = new sql_validator();
    }

    // --- passing cases ---

    public function test_simple_select_passes(): void {
        $this->validator->check('SELECT id, name FROM user');
    }

    public function test_select_with_where_passes(): void {
        $this->validator->check('SELECT * FROM user WHERE id = 1');
    }

    public function test_cte_select_passes(): void {
        $this->validator->check('WITH cte AS (SELECT id FROM user) SELECT * FROM cte');
    }

    public function test_blocked_keyword_in_string_literal_passes(): void {
        // "DELETE" inside a string value must not be rejected.
        $this->validator->check("SELECT id FROM user WHERE status = 'DELETE_PENDING'");
    }

    public function test_blocked_keyword_in_comment_passes(): void {
        // "DROP" inside a comment must not be rejected.
        $this->validator->check("SELECT id FROM user -- DROP TABLE user");
    }

    public function test_trailing_semicolon_is_stripped_and_passes(): void {
        // Single trailing semicolon is allowed (stripped before stacked-statement check).
        $this->validator->check('SELECT id FROM user;');
    }

    // --- blocking cases ---

    public function test_non_select_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('SHOW TABLES');
    }

    public function test_delete_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('DELETE FROM user WHERE id = 1');
    }

    public function test_drop_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('DROP TABLE user');
    }

    public function test_insert_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('INSERT INTO user (name) VALUES ("bob")');
    }

    public function test_stacked_statements_throw(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('SELECT id FROM user; DROP TABLE user');
    }

    public function test_into_outfile_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('SELECT * FROM user INTO OUTFILE "/tmp/dump.csv"');
    }

    public function test_information_schema_throws(): void {
        $this->expectException(\moodle_exception::class);
        $this->validator->check('SELECT * FROM INFORMATION_SCHEMA.TABLES');
    }
}
