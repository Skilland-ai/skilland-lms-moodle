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
 * Web service mod_skilland_fetch_lessons_ajax: list the lessons of a topic of the linked Skilland course.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_lessons extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'topicid' => new external_value(PARAM_ALPHANUMEXT, 'Skilland topic ID', VALUE_REQUIRED),
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Fetch lessons from Skilland via GraphQL for a given topic.
     *
     * @param string $topicid Skilland topic ID
     * @param int $moodlecourseid Moodle course ID
     * @return array
     */
    public static function execute(string $topicid, int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'topicid' => $topicid,
            'moodlecourseid' => $moodlecourseid,
        ]);
        $topicid = $params['topicid'];
        $moodlecourseid = $params['moodlecourseid'];

        // Validate the course context, then check capability in it.
        $context = \context_course::instance($moodlecourseid);
        self::validate_context($context);
        require_capability('mod/skilland:accessstudio', $context);

        // The requested topic must belong to the Skilland course this Moodle course is mapped to.
        $skillandcourseid = skilland_get_mapped_courseid($moodlecourseid);
        if ($skillandcourseid === null) {
            throw new \moodle_exception('error_course_not_mapped', 'mod_skilland');
        }
        try {
            $belongs = skilland_topic_belongs_to_course($topicid, $skillandcourseid);
        } catch (\Throwable $e) {
            // An API failure must not carry upstream text to the browser; user-facing codes pass.
            if (mod_skilland_is_client_error($e)) {
                throw $e;
            }
            logger::error('AJAX', 'fetch_lessons topic check error: ' . $e->getMessage());
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        if (!$belongs) {
            throw new \moodle_exception('error_course_not_mapped_to_skill', 'mod_skilland');
        }

        logger::debug('AJAX', 'fetch_lessons_ajax called with topicid: ' . $topicid);

        try {
            $lessons = mod_skilland_fetch_lessons($topicid);
            logger::debug('AJAX', 'Received ' . count($lessons) . ' lessons');

            // Format lessons for response.
            $formatted = [];
            foreach ($lessons as $lesson) {
                $formatted[] = [
                    'id' => $lesson['id'],
                    'name' => $lesson['name'] ?? '',
                    'updatedAt' => $lesson['updatedAt'] ?? '',
                ];
            }

            return [
                'lessons' => $formatted,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'lessons' => [],
                'error' => self::client_error($e, 'fetch_lessons'),
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
            'lessons' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Lesson ID'),
                    'name' => new external_value(PARAM_TEXT, 'Lesson name'),
                    'updatedAt' => new external_value(PARAM_TEXT, 'Last update timestamp'),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
