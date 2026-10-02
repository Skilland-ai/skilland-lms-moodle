<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Test connection admin page: checks the saved plugin settings against Skilland (SKL-992).
 *
 * A plain GET only shows the page; the check runs on a POST carrying the session key.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use mod_skilland\local\connection_check;

admin_externalpage_setup('mod_skilland_connection_check');

$pageurl = new moodle_url('/mod/skilland/connection_check.php');

$results = null;
if (data_submitted() && optional_param('run', 0, PARAM_BOOL)) {
    require_sesskey();
    $results = (new connection_check())->run();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('connectioncheck', 'mod_skilland'));
echo html_writer::tag('p', get_string('connectioncheck_desc', 'mod_skilland'));
echo html_writer::tag('p', html_writer::link(
    new moodle_url('/admin/settings.php', ['section' => 'modsettingskilland']),
    get_string('connectioncheck_settings_link', 'mod_skilland')
));

echo $OUTPUT->single_button(
    new moodle_url($pageurl, ['run' => 1]),
    get_string('connectioncheck_run', 'mod_skilland'),
    'post',
    ['type' => single_button::BUTTON_PRIMARY]
);

if ($results !== null) {
    $types = [
        connection_check::STATUS_OK => notification::NOTIFY_SUCCESS,
        connection_check::STATUS_WARN => notification::NOTIFY_WARNING,
        connection_check::STATUS_FAIL => notification::NOTIFY_ERROR,
    ];
    echo $OUTPUT->heading(get_string('connectioncheck_results', 'mod_skilland'), 3, 'mt-4');
    $items = '';
    foreach ($results as $result) {
        $items .= html_writer::tag(
            'li',
            $OUTPUT->notification(s($result['message']), $types[$result['status']], false),
            ['data-check' => $result['check'], 'data-status' => $result['status']]
        );
    }
    echo html_writer::tag('ul', $items, ['class' => 'list-unstyled', 'id' => 'mod_skilland_connection_check_results']);
}

echo $OUTPUT->footer();
