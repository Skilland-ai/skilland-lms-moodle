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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_skilland\logger;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Web service mod_skilland_fetch_courses_ajax: list the Skilland courses the caller may link.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_courses extends base {
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
     * Fetch courses from Skilland via GraphQL.
     * Non-admin users will only see courses where they are enrolled with editing capability.
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

        logger::debug('AJAX', 'fetch_courses_ajax called');

        try {
            $courses = mod_skilland_fetch_courses();
            logger::debug('AJAX', 'Received ' . count($courses) . ' courses from API');

            // For non-admin users, filter to only show Skilland courses
            // that are mapped to Moodle courses where the user has editing permissions.
            $isadmin = has_capability('moodle/site:config', \context_system::instance());

            if (!$isadmin) {
                // Get list of Skilland course IDs from Moodle courses
                // where user has editing capability.
                $allowedSkillandIds = self::get_user_allowed_skilland_courses($USER->id);
                logger::debug('AJAX', 'User has access to ' . count($allowedSkillandIds) . ' Moodle-mapped Skilland courses');

                // Also get courses where user has edit permissions in Skilland
                // (owner or collaborator).
                $userCourses = mod_skilland_fetch_user_courses($USER->email);
                foreach ($userCourses as $course) {
                    $allowedSkillandIds[$course['id']] = true;
                }
                logger::debug('AJAX', 'After adding Skilland-editable courses: ' . count($allowedSkillandIds) . ' total allowed');

                // Filter API response to only return matching courses.
                $courses = array_filter($courses, function($course) use ($allowedSkillandIds) {
                    return isset($allowedSkillandIds[$course['id']]);
                });

                // Re-index array after filtering.
                $courses = array_values($courses);
                logger::debug('AJAX', 'After filtering: ' . count($courses) . ' courses available to user');
            }

            // Format courses for response.
            $formatted = [];
            foreach ($courses as $course) {
                $formatted[] = [
                    'id' => $course['id'],
                    'name' => $course['name'] ?? '',
                    'code' => $course['code'] ?? '',
                    'status' => $course['status'] ?? '',
                ];
            }

            return [
                'courses' => $formatted,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'courses' => [],
                'error' => self::client_error($e, 'fetch_courses'),
            ];
        }
    }

    /**
     * Get array of Skilland course IDs that the user has access to.
     * Based on Moodle courses where the user is enrolled with the Studio capability.
     *
     * @param int $userid User ID
     * @return array Associative array with Skilland course IDs as keys
     */
    private static function get_user_allowed_skilland_courses(int $userid): array {
        $allowedSkillandIds = [];

        // Get user's enrolled courses.
        $enrolledcourses = enrol_get_users_courses($userid, true);

        foreach ($enrolledcourses as $course) {
            // Check if user may use SkilLand Studio in this course.
            $ctx = \context_course::instance($course->id);
            if (has_capability('mod/skilland:accessstudio', $ctx, $userid)) {
                // Get the Skilland course ID from the custom field.
                $skillandId = skilland_get_course_customfield_value($course->id);
                if ($skillandId) {
                    $allowedSkillandIds[$skillandId] = true;
                }
            }
        }

        return $allowedSkillandIds;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Course ID'),
                    'name' => new external_value(PARAM_TEXT, 'Course name'),
                    'code' => new external_value(PARAM_TEXT, 'Course code', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Course status', VALUE_OPTIONAL),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
