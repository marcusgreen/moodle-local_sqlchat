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

namespace local_sqlchat\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use context;
use context_system;

/**
 * Privacy provider for local_sqlchat.
 *
 * The plugin keeps an audit log (local_sqlchat_log) of each user's questions,
 * generated SQL and execution metadata. All rows live in the system context.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /** Audit log table name. */
    private const TABLE = 'local_sqlchat_log';

    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            self::TABLE,
            [
                'userid' => 'privacy:metadata:log:userid',
                'question' => 'privacy:metadata:log:question',
                'sqlgenerated' => 'privacy:metadata:log:sqlgenerated',
                'success' => 'privacy:metadata:log:success',
                'errormsg' => 'privacy:metadata:log:errormsg',
                'rowsreturned' => 'privacy:metadata:log:rowsreturned',
                'tokensused' => 'privacy:metadata:log:tokensused',
                'latencyms' => 'privacy:metadata:log:latencyms',
                'timecreated' => 'privacy:metadata:log:timecreated',
            ],
            'privacy:metadata'
        );
        return $collection;
    }

    /**
     * Contexts holding data for a user. All rows are in the system context.
     *
     * @param int $userid The user to look up.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if ($DB->record_exists(self::TABLE, ['userid' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Users who have data within the given context.
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }
        $userlist->add_from_sql('userid', 'SELECT userid FROM {' . self::TABLE . '}', []);
    }

    /**
     * Export all log rows for the approved contexts of one user.
     *
     * @param approved_contextlist $contextlist The approved contexts to export.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(CONTEXT_SYSTEM, array_map(static fn($c) => $c->contextlevel, $contextlist->get_contexts()), true)) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $rows = $DB->get_records(self::TABLE, ['userid' => $userid], 'timecreated ASC');
        if (!$rows) {
            return;
        }
        $data = array_map(static function ($r) {
            return (object) [
                'question' => $r->question,
                'sqlgenerated' => $r->sqlgenerated,
                'success' => $r->success,
                'errormsg' => $r->errormsg,
                'rowsreturned' => $r->rowsreturned,
                'tokensused' => $r->tokensused,
                'latencyms' => $r->latencyms,
                'timecreated' => \core_privacy\local\request\transform::datetime($r->timecreated),
            ];
        }, array_values($rows));
        writer::with_context(context_system::instance())->export_data(
            [get_string('pluginname', 'local_sqlchat')],
            (object) ['queries' => $data]
        );
    }

    /**
     * Delete all data in a context (system context only).
     *
     * @param context $context The context to purge.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;
        if ($context instanceof context_system) {
            $DB->delete_records(self::TABLE);
        }
    }

    /**
     * Delete data for one user across their approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete within.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        if (!in_array(CONTEXT_SYSTEM, array_map(static fn($c) => $c->contextlevel, $contextlist->get_contexts()), true)) {
            return;
        }
        $DB->delete_records(self::TABLE, ['userid' => $contextlist->get_user()->id]);
    }

    /**
     * Delete data for a set of users within a context.
     *
     * @param approved_userlist $userlist The approved users to delete.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->delete_records_select(self::TABLE, "userid $insql", $params);
    }
}
