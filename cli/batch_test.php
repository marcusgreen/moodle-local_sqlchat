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

/**
 * Batch tester: run a list of natural-language questions through the LLM and
 * execute the generated SQL against this Moodle's database, reporting which
 * questions produce SQL that runs without error.
 *
 * Each question costs one live LLM request plus one read-only DB query. The
 * execution path is SELECT-only (re-validated by {@see \local_sqlchat\api::execute}),
 * prefixed, LIMITed and timeout-bounded, and uses the read-only connection when
 * $CFG->dbreadonly_user / dbreadonly_pass are configured.
 *
 * Usage (from the Moodle root, e.g. /var/www/mdl52/public):
 *   php local/sqlchat/cli/batch_test.php --file=local/sqlchat/cli/questions.txt
 *   php local/sqlchat/cli/batch_test.php --file=questions.txt --mode=ddl_bm25 --limit=5
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    [
        'help'  => false,
        'file'  => '',
        'limit' => 0,
        'mode'  => '',
    ],
    [
        'h' => 'help',
        'f' => 'file',
        'l' => 'limit',
        'm' => 'mode',
    ]
);

if ($unrecognised) {
    $unrecognised = implode("\n  ", $unrecognised);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognised));
}

if ($options['help'] || $options['file'] === '') {
    cli_writeln(<<<EOT
Run natural-language questions through local_sqlchat and execute the generated
SQL against this Moodle's database, reporting per-question pass/fail.

Options:
  -f, --file=PATH   File of questions, one per line. Blank lines and lines
                    beginning with # are ignored. (Required.)
  -l, --limit=N     Only run the first N questions from the file (0 = all).
  -m, --mode=MODE   Override the retrieval mode for this run only:
                    full | bm25 | ddl | ddl_bm25. Restored on exit.
  -h, --help        Show this help.

Example:
  php local/sqlchat/cli/batch_test.php --file=local/sqlchat/cli/questions.txt
EOT);
    exit(0);
}

// Resolve the questions file relative to CWD or dirroot.
$filepath = $options['file'];
if (!file_exists($filepath)) {
    $alt = $CFG->dirroot . '/' . ltrim($filepath, '/');
    if (file_exists($alt)) {
        $filepath = $alt;
    } else {
        cli_error("Questions file not found: {$options['file']}");
    }
}

$lines = file($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$questions = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $questions[] = $line;
}

if (!$questions) {
    cli_error('No questions found in file (all lines blank or commented).');
}

$limit = (int) $options['limit'];
if ($limit > 0) {
    $questions = array_slice($questions, 0, $limit);
}

// Run as the site admin so require_capability('local/sqlchat:use') passes.
// CLI scripts start with no user; get_admin() gives a siteadmin, which holds
// every capability.
$admin = get_admin();
if (!$admin) {
    cli_error('No site admin account found to run as.');
}
\core\session\manager::set_user($admin);

// Optionally override the retrieval mode for this run; restore it afterwards so
// the harness never mutates the admin-configured setting.
$originalmode = null;
if ($options['mode'] !== '') {
    $valid = ['full', 'bm25', 'ddl', 'ddl_bm25'];
    if (!in_array($options['mode'], $valid, true)) {
        cli_error('Invalid --mode. Use one of: ' . implode(', ', $valid));
    }
    $originalmode = get_config('local_sqlchat', 'retrieval');
    set_config('retrieval', $options['mode'], 'local_sqlchat');
}

$activemode = (string) (get_config('local_sqlchat', 'retrieval') ?: 'full');
cli_writeln('Retrieval mode: ' . $activemode);
cli_writeln('Questions:      ' . count($questions));
cli_writeln(str_repeat('-', 72));

$counts = ['ok' => 0, 'gen' => 0, 'exec' => 0];
$execfailures = [];

foreach ($questions as $i => $question) {
    $label = sprintf('%2d/%d', $i + 1, count($questions));

    // Phase A: generate SQL (LLM + validator).
    try {
        $result = \local_sqlchat\api::generate_sql($question);
    } catch (\Throwable $e) {
        $counts['gen']++;
        cli_writeln(sprintf('%s [GEN ] error: %s | %s', $label, oneline($e->getMessage()), $question));
        continue;
    }

    // Phase B: execute the generated SQL against the real database.
    try {
        $rows = \local_sqlchat\api::execute($result->sql, $result->logid);
    } catch (\Throwable $e) {
        $counts['exec']++;
        $execfailures[] = ['q' => $question, 'sql' => $result->sql, 'err' => $e->getMessage()];
        cli_writeln(sprintf(
            '%s [EXEC] gen %5dms          ERROR: %s | %s',
            $label,
            $result->latency_ms,
            oneline($e->getMessage()),
            $question
        ));
        continue;
    }

    $counts['ok']++;
    cli_writeln(sprintf(
        '%s [OK  ] gen %5dms rows=%-5d | %s',
        $label,
        $result->latency_ms,
        count($rows),
        $question
    ));
}

cli_writeln(str_repeat('-', 72));
cli_writeln(sprintf(
    'Total %d | OK %d | gen-fail %d | exec-fail %d',
    count($questions),
    $counts['ok'],
    $counts['gen'],
    $counts['exec']
));

// Dump the offending SQL for exec failures so join/column mistakes are visible
// for prompt tuning.
if ($execfailures) {
    cli_writeln('');
    cli_writeln('Execution failures (generated SQL rejected by the database):');
    foreach ($execfailures as $f) {
        cli_writeln(str_repeat('-', 72));
        cli_writeln('Q:   ' . $f['q']);
        cli_writeln('ERR: ' . oneline($f['err']));
        cli_writeln('SQL: ' . $f['sql']);
    }
}

// Restore the retrieval mode if we changed it.
if ($originalmode !== null) {
    set_config('retrieval', $originalmode, 'local_sqlchat');
}

exit($counts['gen'] + $counts['exec'] === 0 ? 0 : 1);

/**
 * Collapse whitespace/newlines so an error message fits on one report line.
 *
 * @param string $text
 * @return string
 */
function oneline(string $text): string {
    return trim(preg_replace('/\s+/', ' ', $text));
}
