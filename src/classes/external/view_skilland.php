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

namespace mod_skilland\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');
require_once(__DIR__ . '/../../locallib.php');

/**
 * Web service mod_skilland_view_skilland: log that a user viewed a Skilland activity.
 *
 * What view.php does on load, for the Moodle App: trigger the course_module_viewed event and
 * mark the module viewed for view completion. Part of the official mobile service.
 *
 * The access checks propagate as exceptions (no error payload): there is no Skilland API
 * answer to redact and the app expects a web service error when the user may not view.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_skilland extends base {
    /**
     * Parameters for view_skilland.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity instance id', VALUE_REQUIRED),
        ]);
    }

    /**
     * Trigger the course module viewed event and the view completion of a Skilland activity.
     *
     * @param int $skillandid Skilland activity instance id.
     * @return array { status: bool, warnings: array }
     */
    public static function execute(int $skillandid) {
        global $DB;

        self::require_enabled();

        $params = self::validate_parameters(self::execute_parameters(), [
            'skillandid' => $skillandid,
        ]);

        $cm = get_coursemodule_from_instance('skilland', $params['skillandid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $skilland = $DB->get_record('skilland', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Validate the module context (enrolment, visibility), then the same capability view.php requires.
        self::validate_context($context);
        require_capability('mod/skilland:view', $context);

        skilland_view($skilland, $course, $cm, $context);

        return [
            'status' => true,
            'warnings' => [],
        ];
    }

    /**
     * Return type for view_skilland.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'status: true if success'),
            'warnings' => new external_warnings(),
        ]);
    }
}
