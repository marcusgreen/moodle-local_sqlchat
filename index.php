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
 * Standalone test page: ask a question, see generated SQL, optionally execute it.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

defined('MOODLE_INTERNAL') || die();

admin_externalpage_setup('local_sqlchat_index');
$context = context_system::instance();
require_capability('local/sqlchat:use', $context);

$question = optional_param('question', '', PARAM_RAW_TRIMMED);
$sqltorun = optional_param('sqltorun', '', PARAM_RAW_TRIMMED);
$logid = optional_param('logid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

echo $OUTPUT->header();

echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
echo html_writer::tag('label',
    get_string('form:question', 'local_sqlchat'),
    ['for' => 'sqlchat-question']
);
$helpbody = html_writer::tag('p', get_string('form:helpintro', 'local_sqlchat'));
$helpbody .= html_writer::tag('p', get_string('form:helptips', 'local_sqlchat'));
$helpbody .= html_writer::tag('p', get_string('form:helpexamples', 'local_sqlchat'));
$helpbody .= html_writer::start_tag('ul');
$helpbody .= html_writer::tag('li', get_string('form:helpexample1', 'local_sqlchat'));
$helpbody .= html_writer::tag('li', get_string('form:helpexample2', 'local_sqlchat'));
$helpbody .= html_writer::tag('li', get_string('form:helpexample3', 'local_sqlchat'));
$helpbody .= html_writer::end_tag('ul');
$helpbody .= html_writer::tag('p', html_writer::link(
    'https://www.examulator.com/ai',
    get_string('form:helpmore', 'local_sqlchat'),
    ['target' => '_blank', 'rel' => 'noopener']
));

echo html_writer::tag('a',
    $OUTPUT->pix_icon('docs', get_string('form:helpsummary', 'local_sqlchat')),
    [
        'href' => '#',
        'role' => 'button',
        'tabindex' => '0',
        'class' => 'ml-1',
        'data-toggle' => 'popover',
        'data-bs-toggle' => 'popover',
        'data-trigger' => 'focus',
        'data-bs-trigger' => 'focus',
        'data-html' => 'true',
        'data-bs-html' => 'true',
        'data-placement' => 'right',
        'data-bs-placement' => 'right',
        'title' => get_string('form:helpsummary', 'local_sqlchat'),
        'data-content' => $helpbody,
        'data-bs-content' => $helpbody,
        'onclick' => 'return false;',
    ]
);

$PAGE->requires->js_amd_inline("
require(['jquery'], function(\$) {
    if (typeof \$.fn.popover === 'function') {
        \$('[data-toggle=\"popover\"]').popover({container: 'body'});
    } else if (window.bootstrap && bootstrap.Popover) {
        document.querySelectorAll('[data-bs-toggle=\"popover\"]').forEach(function(el) {
            new bootstrap.Popover(el);
        });
    }
});
");
echo html_writer::tag('textarea', s($question), [
    'name' => 'question',
    'id' => 'sqlchat-question',
    'rows' => 3,
    'cols' => 80,
    'class' => 'form-control mb-2',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'action', 'value' => 'generate',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
]);
echo html_writer::tag('button', get_string('form:submit', 'local_sqlchat'), [
    'type' => 'submit', 'class' => 'btn btn-primary',
]);
echo html_writer::end_tag('form');

if ($action === 'generate' && $question !== '') {
    require_sesskey();
    try {
        $result = \local_sqlchat\api::generate_sql($question, $context->id);
        $sqltorun = $result->sql;
        $logid = $result->logid;
        echo $OUTPUT->heading(get_string('result:sql', 'local_sqlchat'), 4);
        echo html_writer::tag('pre', s($result->sql), ['class' => 'bg-light p-2']);
        echo html_writer::tag('p', get_string('result:latency', 'local_sqlchat', $result->latency_ms));
    } catch (\Throwable $e) {
        echo $OUTPUT->notification($e->getMessage(), 'error');
    }
}

if ($sqltorun !== '') {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $PAGE->url->out(false)]);
    echo html_writer::tag('textarea', s($sqltorun), [
        'name' => 'sqltorun',
        'rows' => 6,
        'cols' => 100,
        'class' => 'form-control mb-2',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'action', 'value' => 'execute',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'logid', 'value' => $logid,
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey(),
    ]);
    echo html_writer::tag('button', get_string('form:execute', 'local_sqlchat'), [
        'type' => 'submit', 'class' => 'btn btn-success',
    ]);
    echo html_writer::end_tag('form');
}

if ($action === 'execute' && $sqltorun !== '') {
    require_sesskey();
    try {
        $rows = \local_sqlchat\api::execute($sqltorun, $logid);
        echo html_writer::tag('p', get_string('result:rows', 'local_sqlchat', count($rows)));
        if ($rows !== []) {
            $first = (array) reset($rows);
            $headers = array_keys($first);
            $table = new html_table();
            $table->head = $headers;
            foreach ($rows as $row) {
                $r = (array) $row;
                $table->data[] = array_map(static fn($v) => s((string) $v), array_values($r));
            }
            echo html_writer::table($table);
        }
    } catch (\Throwable $e) {
        echo $OUTPUT->notification($e->getMessage(), 'error');
    }
}

echo $OUTPUT->footer();
