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
 * Web service mod_skilland_fetch_topics_ajax: list the topics of the Skilland course linked to a Moodle course.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_topics extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_ALPHANUMEXT, 'Skilland course ID', VALUE_REQUIRED),
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Fetch topics from Skilland via GraphQL for a given course.
     *
     * @param string $courseid Skilland course ID
     * @param int $moodlecourseid Moodle course ID
     * @return array
     */
    public static function execute(string $courseid, int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'moodlecourseid' => $moodlecourseid,
        ]);
        $courseid = $params['courseid'];
        $moodlecourseid = $params['moodlecourseid'];

        // Validate the course context, then check capability in it.
        $context = \context_course::instance($moodlecourseid);
        self::validate_context($context);
        require_capability('mod/skilland:accessstudio', $context);

        // The requested Skilland course must be the one this Moodle course is mapped to.
        $courseid = skilland_require_mapped_course($moodlecourseid, $courseid);

        logger::debug('AJAX', 'fetch_topics_ajax called with courseid: ' . $courseid);

        try {
            $courseData = mod_skilland_fetch_topics($courseid);

            $topics = $courseData['topics'] ?? [];
            logger::debug('AJAX', 'Received ' . count($topics) . ' topics');

            // Format topics for response.
            $formatted = [];
            foreach ($topics as $topic) {
                $formatted[] = [
                    'id' => $topic['id'],
                    'name' => $topic['name'] ?? '',
                    'code' => $topic['code'] ?? '',
                    'description' => clean_text($topic['description'] ?? '', FORMAT_HTML),
                ];
            }

            $courseDetails = [
                'id' => $courseData['id'],
                'name' => $courseData['name'] ?? '',
                'code' => $courseData['code'] ?? $courseData['id'], // Fallback to ID if code is missing
            ];

            return [
                'course' => $courseDetails,
                'topics' => $formatted,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'topics' => [],
                'error' => self::client_error($e, 'fetch_topics'),
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
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_TEXT, 'Course ID'),
                'name' => new external_value(PARAM_TEXT, 'Course name', VALUE_OPTIONAL),
                'code' => new external_value(PARAM_TEXT, 'Course code', VALUE_OPTIONAL),
            ], 'Course details', VALUE_OPTIONAL),
            'topics' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Topic ID'),
                    'name' => new external_value(PARAM_TEXT, 'Topic name'),
                    'code' => new external_value(PARAM_TEXT, 'Topic code', VALUE_OPTIONAL),
                    'description' => new external_value(PARAM_CLEANHTML, 'Topic description', VALUE_OPTIONAL),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
