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
 * Stub compressor returning a fixed schema so BM25 ranking is tested in
 * isolation, without walking the real Moodle install.xml files.
 */
class fake_schema_compressor extends schema_compressor {
    /** @var string Schema text to return. */
    private string $schema;

    /**
     * @param string $schema Fixed compressed schema.
     */
    public function __construct(string $schema) {
        $this->schema = $schema;
    }

    #[\Override]
    public function get_compact(bool $forcerefresh = false): string {
        return $this->schema;
    }
}

/**
 * Unit tests for bm25_retriever.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_sqlchat\bm25_retriever
 */
class bm25_retriever_test extends \advanced_testcase {

    /** A small representative slice of the Moodle schema in compressed form. */
    private const SCHEMA = <<<'SCHEMA'
user(id PK, username, email, firstname, lastname, lastaccess)
course(id PK, fullname, shortname, category→course_categories)
course_categories(id PK, name, parent)
course_modules(id PK, course→course, module→modules, instance)
modules(id PK, name)
user_enrolments(id PK, enrolid→enrol, userid→user, timestart)
enrol(id PK, courseid→course, enrol)
quiz(id PK, course→course, name, timeopen)
quiz_attempts(id PK, quiz→quiz, userid→user, sumgrades)
grade_grades(id PK, itemid→grade_items, userid→user, finalgrade)
grade_items(id PK, courseid→course, itemname)
logstore_standard_log(id PK, userid→user, action, target, timecreated)
forum(id PK, course→course, name)
forum_posts(id PK, discussion→forum_discussions, userid→user, message)
forum_discussions(id PK, forum→forum, course→course)
SCHEMA;

    /**
     * Parse the retrieved schema text back into the set of table names.
     *
     * @param string $text Retrieved schema text.
     * @return string[] Table names present.
     */
    private function tables(string $text): array {
        $names = [];
        foreach (explode("\n", trim($text)) as $line) {
            $open = strpos($line, '(');
            if ($open !== false) {
                $names[] = substr($line, 0, $open);
            }
        }
        return $names;
    }

    public function test_returns_subset_not_full_schema(): void {
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('show quiz attempts and their scores');
        $tables = $this->tables($out);

        $this->assertContains('quiz_attempts', $tables);
        // Should not drag in unrelated forum tables.
        $this->assertNotContains('forum_posts', $tables);
    }

    public function test_synonym_student_maps_to_user(): void {
        // "students" never appears in the schema; synonym bridge must reach user.
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('list students who never logged in');
        $tables = $this->tables($out);

        $this->assertContains('user', $tables);
        $this->assertContains('logstore_standard_log', $tables);
    }

    public function test_fk_expansion_pulls_join_targets(): void {
        // Asking about enrolments should pull the user/course join targets via FK.
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('enrolment start dates');
        $tables = $this->tables($out);

        $this->assertContains('user_enrolments', $tables);
        $this->assertContains('enrol', $tables);
        // FK targets of the matched/anchor tables.
        $this->assertContains('user', $tables);
        $this->assertContains('course', $tables);
    }

    public function test_anchors_always_present(): void {
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('quiz names');
        $tables = $this->tables($out);

        $this->assertContains('user', $tables);
        $this->assertContains('course', $tables);
    }

    public function test_empty_schema_returns_empty(): void {
        $retriever = new bm25_retriever(new fake_schema_compressor(''));
        $this->assertSame('', $retriever->retrieve('anything'));
    }

    public function test_no_query_signal_falls_back_to_full(): void {
        // A question with only stopwords yields no terms → full schema returned.
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('show me all the of the');
        $this->assertSame(15, count($this->tables($out)));
    }

    public function test_relevant_table_outranks_irrelevant(): void {
        $retriever = new bm25_retriever(new fake_schema_compressor(self::SCHEMA));
        $out = $retriever->retrieve('forum discussion posts');
        $tables = $this->tables($out);

        $this->assertContains('forum_posts', $tables);
        $this->assertContains('forum_discussions', $tables);
    }
}
