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
 * BM25 schema retriever.
 *
 * Reduces the full compressed schema (one line per table, produced by
 * {@see schema_compressor}) to only the tables most relevant to a natural
 * language question, using Okapi BM25 ranking over table/column/FK tokens.
 *
 * Goal: cut the schema portion of the LLM prompt from ~all tables to a small
 * relevant subset, without depending on any AI provider (pure PHP).
 *
 * Safety guardrails against the "missed table" failure mode:
 *  - top-N is generous (see {@see TOP_N});
 *  - selected tables are expanded along inferred foreign keys, so join targets
 *    come along even if they did not rank;
 *  - a small set of anchor tables is always included when present;
 *  - if scoring yields nothing, falls back to the full schema.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bm25_retriever {

    /** BM25 term-frequency saturation parameter. */
    private const K1 = 1.5;

    /**
     * BM25 length-normalisation parameter. Kept low because table "documents"
     * are short and fairly uniform in length, unlike prose.
     */
    private const B = 0.3;

    /** Number of top-ranked tables to keep before FK expansion. */
    private const TOP_N = 40;

    /**
     * Tables that join almost everything in Moodle. Always included when they
     * exist in the schema, regardless of score.
     *
     * @var string[]
     */
    private const ANCHORS = ['user', 'course'];

    /**
     * Words to ignore in the question (English filler + SQL verbs that carry no
     * table signal). Schema-side tokens are filtered by {@see SKIP_TOKENS}.
     *
     * @var array<string, true>
     */
    private const STOPWORDS = [
        'the' => true, 'a' => true, 'an' => true, 'of' => true, 'to' => true,
        'in' => true, 'on' => true, 'for' => true, 'and' => true, 'or' => true,
        'with' => true, 'by' => true, 'from' => true, 'all' => true, 'any' => true,
        'show' => true, 'list' => true, 'me' => true, 'get' => true, 'find' => true,
        'give' => true, 'who' => true, 'what' => true, 'which' => true, 'how' => true,
        'many' => true, 'much' => true, 'that' => true, 'have' => true, 'has' => true,
        'are' => true, 'is' => true, 'was' => true, 'were' => true, 'their' => true,
        'they' => true, 'them' => true, 'each' => true, 'per' => true, 'only' => true,
        'count' => true, 'number' => true, 'total' => true, 'last' => true,
        'recent' => true, 'between' => true, 'where' => true, 'when' => true,
        'group' => true, 'order' => true, 'sort' => true, 'top' => true,
        'days' => true, 'day' => true, 'week' => true, 'month' => true, 'year' => true,
    ];

    /**
     * Tokens to ignore on the schema side: appear in nearly every table and
     * carry no discriminating signal.
     *
     * @var array<string, true>
     */
    private const SKIP_TOKENS = [
        'id' => true, 'pk' => true, 'name' => true, 'timecreated' => true,
        'timemodified' => true, 'time' => true, 'created' => true, 'modified' => true,
    ];

    /**
     * Moodle vocabulary bridge: user phrasing → schema token. Lets common
     * human terms reach the right tables despite BM25 being purely lexical.
     *
     * @var array<string, string[]>
     */
    private const SYNONYMS = [
        'student'      => ['user'],
        'students'     => ['user'],
        'teacher'      => ['user'],
        'teachers'     => ['user'],
        'learner'      => ['user'],
        'learners'     => ['user'],
        'participant'  => ['user'],
        'participants' => ['user'],
        'people'       => ['user'],
        'person'       => ['user'],
        'member'       => ['user'],
        'members'      => ['user'],
        'enrolled'     => ['enrol', 'user_enrolments'],
        'enrolment'    => ['enrol', 'user_enrolments'],
        'enrolments'   => ['enrol', 'user_enrolments'],
        'enrollment'   => ['enrol', 'user_enrolments'],
        'login'        => ['logstore_standard_log', 'lastaccess'],
        'logins'       => ['logstore_standard_log', 'lastaccess'],
        'logged'       => ['logstore_standard_log', 'lastaccess'],
        'activity'     => ['course_modules', 'modules'],
        'activities'   => ['course_modules', 'modules'],
        'module'       => ['course_modules', 'modules'],
        'modules'      => ['course_modules', 'modules'],
        'mark'         => ['grade_grades', 'grade_items'],
        'marks'        => ['grade_grades', 'grade_items'],
        'grade'        => ['grade_grades', 'grade_items'],
        'grades'       => ['grade_grades', 'grade_items'],
        'score'        => ['grade_grades', 'grade_items'],
        'scores'       => ['grade_grades', 'grade_items'],
        'role'         => ['role', 'role_assignments'],
        'roles'        => ['role', 'role_assignments'],
        'category'     => ['course_categories'],
        'categories'   => ['course_categories'],
        'cohort'       => ['cohort', 'cohort_members'],
        'cohorts'      => ['cohort', 'cohort_members'],
        'group'        => ['groups', 'groups_members'],
        'groups'       => ['groups', 'groups_members'],
    ];

    /** @var schema_compressor Source of the full compressed schema. */
    private schema_compressor $compressor;

    /**
     * @param schema_compressor $compressor Provides the full schema text.
     */
    public function __construct(schema_compressor $compressor) {
        $this->compressor = $compressor;
    }

    /**
     * Return the compressed schema reduced to the tables relevant to $question.
     *
     * Output shape matches {@see schema_compressor::get_compact()} (one table
     * per line) so it is a drop-in replacement in the prompt builder. On any
     * doubt it returns the full schema rather than risk a missing table.
     *
     * @param string $question Natural-language question.
     * @return string Newline-joined subset of table lines.
     */
    public function retrieve(string $question): string {
        $full = $this->compressor->get_compact();
        $names = $this->retrieve_tables($question);
        if ($names === []) {
            return $full;
        }
        $docs = $this->parse($full);

        // Emit in the original schema order for stable, readable prompts.
        $lines = [];
        foreach ($docs as $name => $doc) {
            if (in_array($name, $names, true)) {
                $lines[] = $doc['line'];
            }
        }
        return $lines === [] ? $full : implode("\n", $lines);
    }

    /**
     * Return the names of the tables relevant to $question, in original schema order.
     *
     * Shares the BM25 scoring with {@see retrieve()} but yields table names rather than rendered
     * lines, so callers can pair the selection with a different renderer (e.g. CREATE TABLE DDL).
     * Returns an empty array when the question cannot be narrowed (empty schema, no query terms,
     * no positive scores) so callers can fall back to the full schema.
     *
     * @param string $question Natural-language question.
     * @return string[] Selected table names (empty = could not narrow; use the full schema).
     */
    public function retrieve_tables(string $question): array {
        $full = $this->compressor->get_compact();
        if (trim($full) === '') {
            return [];
        }

        $docs = $this->parse($full);
        if ($docs === []) {
            return [];
        }

        $queryterms = $this->query_terms($question);
        if ($queryterms === []) {
            return [];
        }

        $scores = $this->score($docs, $queryterms);
        if ($scores === []) {
            return [];
        }

        arsort($scores);
        $selected = array_slice(array_keys($scores), 0, self::TOP_N, true);
        $selected = array_flip($selected);

        $this->add_anchors($docs, $selected);
        $this->expand_fks($docs, $selected);

        $names = [];
        foreach ($docs as $name => $doc) {
            if (isset($selected[$name])) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Parse the compressed schema into per-table documents.
     *
     * Each line has the form `tablename(col PK, col→ref, col, ...)`.
     *
     * @param string $full Full compressed schema.
     * @return array<string, array{line: string, tokens: array<string, int>, fks: string[]}>
     */
    private function parse(string $full): array {
        $docs = [];
        foreach (explode("\n", $full) as $line) {
            $line = rtrim($line);
            if ($line === '') {
                continue;
            }
            $open = strpos($line, '(');
            if ($open === false) {
                continue;
            }
            $name = substr($line, 0, $open);
            $inner = substr($line, $open + 1, -1); // Drop trailing ')'.

            $tokens = [];
            foreach ($this->split($name) as $t) {
                $this->bump($tokens, $t);
            }

            $fks = [];
            foreach (explode(',', $inner) as $coltoken) {
                $coltoken = trim($coltoken);
                if ($coltoken === '') {
                    continue;
                }
                // Split column from any "→reftable" suffix.
                $parts = explode('→', $coltoken);
                $col = trim(str_replace(' PK', '', $parts[0]));
                foreach ($this->split($col) as $t) {
                    $this->bump($tokens, $t);
                }
                if (isset($parts[1])) {
                    $ref = trim($parts[1]);
                    if ($ref !== '') {
                        $fks[] = $ref;
                        foreach ($this->split($ref) as $t) {
                            $this->bump($tokens, $t);
                        }
                    }
                }
            }

            $docs[$name] = ['line' => $line, 'tokens' => $tokens, 'fks' => $fks];
        }
        return $docs;
    }

    /**
     * Tokenise an identifier: lowercase, split on snake_case and non-alphanumerics,
     * drop noise tokens, and add an "id"-stripped stem so e.g. `userid` also
     * yields `user`.
     *
     * @param string $identifier Raw identifier (table, column or ref name).
     * @return string[] Normalised tokens (may contain duplicates).
     */
    private function split(string $identifier): array {
        $identifier = strtolower($identifier);
        $raw = preg_split('/[^a-z0-9]+/', $identifier, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($raw as $t) {
            if (isset(self::SKIP_TOKENS[$t]) || strlen($t) < 2) {
                continue;
            }
            $out[] = $t;
            if (str_ends_with($t, 'id') && strlen($t) > 3) {
                $stem = substr($t, 0, -2);
                if (!isset(self::SKIP_TOKENS[$stem]) && strlen($stem) >= 2) {
                    $out[] = $stem;
                }
            }
        }
        return $out;
    }

    /**
     * Increment a term's frequency in a token map.
     *
     * @param array<string, int> $tokens Token-frequency map, modified in place.
     * @param string $term Term to count.
     * @return void
     */
    private function bump(array &$tokens, string $term): void {
        $tokens[$term] = ($tokens[$term] ?? 0) + 1;
    }

    /**
     * Extract query terms from the question: tokenise, drop stopwords, and
     * expand Moodle synonyms.
     *
     * @param string $question Natural-language question.
     * @return string[] Distinct query terms.
     */
    private function query_terms(string $question): array {
        $words = preg_split('/[^a-z0-9]+/', strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];
        foreach ($words as $w) {
            if (strlen($w) < 2 || isset(self::STOPWORDS[$w])) {
                continue;
            }
            $terms[$w] = true;
            foreach (self::SYNONYMS[$w] ?? [] as $syn) {
                foreach ($this->split($syn) as $t) {
                    $terms[$t] = true;
                }
            }
        }
        return array_keys($terms);
    }

    /**
     * Score every document against the query terms with Okapi BM25.
     *
     * @param array<string, array{line: string, tokens: array<string, int>, fks: string[]}> $docs
     * @param string[] $queryterms Query terms.
     * @return array<string, float> Map of table name => BM25 score (positive only).
     */
    private function score(array $docs, array $queryterms): array {
        $n = count($docs);

        // Document frequency and average document length.
        $df = [];
        $totallen = 0;
        foreach ($docs as $doc) {
            $totallen += array_sum($doc['tokens']);
            foreach (array_keys($doc['tokens']) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }
        $avgdl = $n > 0 ? $totallen / $n : 0.0;
        if ($avgdl <= 0) {
            return [];
        }

        $scores = [];
        foreach ($docs as $name => $doc) {
            $dl = array_sum($doc['tokens']);
            $score = 0.0;
            foreach ($queryterms as $term) {
                $f = $doc['tokens'][$term] ?? 0;
                if ($f === 0) {
                    continue;
                }
                $idf = log(1 + ($n - $df[$term] + 0.5) / ($df[$term] + 0.5));
                $denom = $f + self::K1 * (1 - self::B + self::B * $dl / $avgdl);
                $score += $idf * ($f * (self::K1 + 1)) / $denom;
            }
            if ($score > 0) {
                $scores[$name] = $score;
            }
        }
        return $scores;
    }

    /**
     * Include anchor tables that exist in the schema.
     *
     * @param array<string, mixed> $docs All parsed documents (keyed by name).
     * @param array<string, int> $selected Selection set, modified in place.
     * @return void
     */
    private function add_anchors(array $docs, array &$selected): void {
        foreach (self::ANCHORS as $anchor) {
            if (isset($docs[$anchor])) {
                $selected[$anchor] = 1;
            }
        }
    }

    /**
     * Expand the selection one hop along inferred foreign keys so join targets
     * of selected tables are present even if they did not rank.
     *
     * @param array<string, array{line: string, tokens: array<string, int>, fks: string[]}> $docs
     * @param array<string, int> $selected Selection set, modified in place.
     * @return void
     */
    private function expand_fks(array $docs, array &$selected): void {
        foreach (array_keys($selected) as $name) {
            if (!isset($docs[$name])) {
                continue;
            }
            foreach ($docs[$name]['fks'] as $ref) {
                if (isset($docs[$ref])) {
                    $selected[$ref] = 1;
                }
            }
        }
    }
}
