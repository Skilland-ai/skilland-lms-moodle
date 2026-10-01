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
use mod_skilland\logger;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Web service mod_skilland_create_course_ajax: create a Skilland course and map it to a Moodle course.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_course extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Create a new course in Skilland and map it to a Moodle course.
     *
     * @param int $moodlecourseid Moodle course ID
     * @return array
     */
    public static function execute(int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'moodlecourseid' => $moodlecourseid,
        ]);
        $moodlecourseid = $params['moodlecourseid'];

        // Validate the course context, then check capability in it.
        $context = \context_course::instance($moodlecourseid);
        self::validate_context($context);
        require_capability('mod/skilland:accessstudio', $context);

        logger::debug('AJAX', 'create_course_ajax called for moodle course ' . $moodlecourseid);

        try {
            // Get the Moodle course name.
            $course = get_course($moodlecourseid);
            $coursename = $course->fullname;

            // Create course in Skilland; the Moodle user id lets Skilland link a user it creates for SSO.
            $skill = mod_skilland_create_course($coursename, $USER->email, (string) $USER->id);
            $skillid = $skill['id'];
            $skillname = $skill['name'] ?? $coursename;

            logger::debug('AJAX', 'Created Skilland course: ' . $skillid . ' (' . $skillname . ')');

            // Save mapping to course custom field.
            skilland_set_course_customfield_value($moodlecourseid, $skillid);

            // The Studio link is offered after the course form saves (observer::course_updated);
            // sso_redirect.php mints the SSO token when the teacher clicks it.
            mod_skilland_set_pending_studio_path($moodlecourseid, $skill['path']);

            return [
                'skillid' => $skillid,
                'name' => $skillname,
                'redirect_url' => '',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'skillid' => '',
                'name' => '',
                'redirect_url' => '',
                'error' => self::client_error($e, 'create_course'),
            ];
        }
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'skillid' => new external_value(PARAM_TEXT, 'Skilland skill/course ID'),
            'name' => new external_value(PARAM_TEXT, 'Course name'),
            'redirect_url' => new external_value(PARAM_URL, 'SSO redirect URL to Skilland'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
