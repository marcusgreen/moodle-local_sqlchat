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
 * Admin settings for local_sqlchat.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage(
        'local_sqlchat_index',
        get_string('pluginname', 'local_sqlchat'),
        new moodle_url('/local/sqlchat/index.php'),
        'local/sqlchat:use'
    ));

    $settings = new admin_settingpage('local_sqlchat', get_string('pluginname', 'local_sqlchat'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_sqlchat/maxrows',
        get_string('settings:maxrows', 'local_sqlchat'),
        get_string('settings:maxrows_desc', 'local_sqlchat'),
        1000,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_sqlchat/timeoutsec',
        get_string('settings:timeoutsec', 'local_sqlchat'),
        get_string('settings:timeoutsec_desc', 'local_sqlchat'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_sqlchat/purpose',
        get_string('settings:purpose', 'local_sqlchat'),
        get_string('settings:purpose_desc', 'local_sqlchat'),
        'feedback',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configselect(
        'local_sqlchat/backend',
        get_string('settings:backend', 'local_sqlchat'),
        get_string('settings:backend_desc', 'local_sqlchat'),
        'core_ai_subsystem',
        [
            'core_ai_subsystem' => get_string('settings:backend_core', 'local_sqlchat'),
            'local_ai_manager'  => get_string('settings:backend_local', 'local_sqlchat'),
            'tool_aimanager'    => get_string('settings:backend_tool', 'local_sqlchat'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'local_sqlchat/retrieval',
        get_string('settings:retrieval', 'local_sqlchat'),
        get_string('settings:retrieval_desc', 'local_sqlchat'),
        'full',
        [
            'full' => get_string('settings:retrieval_full', 'local_sqlchat'),
            'bm25' => get_string('settings:retrieval_bm25', 'local_sqlchat'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_sqlchat/showprompt',
        get_string('settings:showprompt', 'local_sqlchat'),
        get_string('settings:showprompt_desc', 'local_sqlchat'),
        0
    ));

}
