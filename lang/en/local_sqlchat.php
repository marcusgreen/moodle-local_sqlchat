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
 * Language strings for local_sqlchat.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'SQL Chat';

$string['sqlchat:use'] = 'Use SQL Chat to generate and run SQL queries via LLM';

$string['settings:maxrows'] = 'Maximum result rows';
$string['settings:maxrows_desc'] = 'Hard cap on rows returned per query.';
$string['settings:timeoutsec'] = 'Query timeout (seconds)';
$string['settings:timeoutsec_desc'] = 'Statement timeout enforced on the read-only connection.';
$string['settings:purpose'] = 'AI bridge purpose';
$string['settings:purpose_desc'] = 'Routing tag passed to tool_ai_bridge::perform_request().';

$string['settings:backend'] = 'AI backend';
$string['settings:backend_desc'] = 'Which AI subsystem to use for SQL generation.';
$string['settings:backend_core'] = 'Moodle core AI subsystem (4.5+)';
$string['settings:backend_local'] = 'local_ai_manager (MEBIS)';
$string['settings:backend_tool'] = 'tool_aiconnect (AIConnect)';

$string['settings:retrieval'] = 'Schema retrieval mode';
$string['settings:retrieval_desc'] = 'How much of the Moodle schema is sent to the LLM. "Full" sends every table (most accurate, most tokens). "BM25" sends only the tables most relevant to the question (far fewer tokens, may miss a table on unusual phrasing).';
$string['settings:retrieval_full'] = 'Full schema (send every table)';
$string['settings:retrieval_bm25'] = 'BM25 retrieval (send relevant tables only)';

$string['cachedef_schema'] = 'Compressed Moodle schema cache';

$string['form:question'] = 'Question';
$string['form:question_help'] = 'Plain English description of the data you want.';
$string['form:questionhelp'] = 'Help: view the Moodle ER diagram (opens in a new tab)';
$string['form:helpsummary'] = 'How to write a good question';
$string['form:helpintro'] = 'Describe the data you want in plain English. Be specific about which entities, time ranges and filters matter.';
$string['form:helpexamples'] = 'Examples:';
$string['form:helpexample1'] = 'List the 10 most recently enrolled students in the course with shortname "CS101", showing their full name and enrolment date.';
$string['form:helpexample2'] = 'Count how many users logged in during the last 7 days, grouped by day.';
$string['form:helpexample3'] = 'Show all quiz attempts by user "jdoe" that scored below 50%, including quiz name and attempt date.';
$string['form:helptips'] = 'Name the tables/entities you care about (users, courses, enrolments, quiz attempts, logs). Mention timeframes ("last 30 days"), filters ("only active users"), and the columns you want back. Avoid vague terms like "stuff" or "things".';
$string['form:helpmore'] = 'More prompting guidance';
$string['form:submit'] = 'Generate SQL';
$string['form:clear'] = 'Clear';
$string['form:execute'] = 'Run SQL';

$string['result:sql'] = 'Generated SQL';
$string['result:rows'] = 'Rows returned: {$a}';
$string['result:tokens'] = 'Tokens used: {$a}';
$string['result:latency'] = 'Latency: {$a} ms';

$string['error:adhoc_learnmore'] = 'Learn more about ad-hoc query syntax';
$string['error:unknownplaceholders'] = 'This SQL contains ad-hoc report tokens that could not be resolved: {$a}. Replace them with literal values before running. <a href="https://docs.moodle.org/502/en/Custom_SQL_queries_report" target="_blank" rel="noopener">Learn more about ad-hoc query syntax</a>.';
$string['error:namedparams'] = 'This SQL uses ad-hoc report named parameters that require manual substitution: {$a}. Replace each :param_name placeholder with the value you want to filter by, then run the query again. <a href="https://docs.moodle.org/502/en/Custom_SQL_queries_report" target="_blank" rel="noopener">Learn more about ad-hoc query syntax</a>.';
$string['error:onlyselect'] = 'Only SELECT statements are permitted.';
$string['error:blockedkeyword'] = 'Blocked SQL keyword: {$a}';
$string['error:nosemicolons'] = 'Stacked statements are not permitted.';
$string['error:llmempty'] = 'LLM returned no SQL.';
$string['error:execfailed'] = 'Query execution failed: {$a}';
$string['error:dialectmismatch'] = 'SQL contains syntax incompatible with {$a->dialect}. Suggestions: {$a->suggestions}';

$string['privacy:metadata'] = 'local_sqlchat logs the question, generated SQL, and execution metadata for each query made by a user.';
$string['privacy:metadata:log:userid'] = 'The user who made the query.';
$string['privacy:metadata:log:question'] = 'The natural-language question submitted.';
$string['privacy:metadata:log:sqlgenerated'] = 'The SQL generated by the LLM.';
$string['privacy:metadata:log:timecreated'] = 'When the query was made.';
