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
 * Web service mod_skilland_provision_topic_scorm_ajax: provision a topic-level SCORM package containing all lessons as SCOs.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision_topic_scorm extends base {
    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity record ID', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the skilland activity', VALUE_REQUIRED),
        ]);
    }

    /**
     * Provision a topic-level SCORM package containing all lessons as SCOs.
     *
     * @param int $skillandid The skilland activity record ID
     * @param int $cmid The course module ID of the skilland activity
     * @return array
     */
    public static function execute(int $skillandid, int $cmid) {
        global $DB;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'skillandid' => $skillandid,
            'cmid' => $cmid,
        ]);

        $skillandid = $params['skillandid'];
        $cmid = $params['cmid'];

        // Get the course module and context.
        $cm = get_coursemodule_from_id('skilland', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Validate the module context, then check capability in it.
        self::validate_context($context);
        require_capability('mod/skilland:provision', $context);

        logger::debug('AJAX', 'provision_topic_scorm_ajax called for skilland ' . $skillandid . ', cm ' . $cmid);

        try {
            // Get the skilland activity record.
            $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);

            // Verify this is the right activity.
            if ($skilland->id != $cm->instance) {
                throw new \moodle_exception('invalidactivity', 'mod_skilland');
            }

            // Get the course.
            $course = get_course($cm->course);

            // Get the section number for the skilland activity.
            $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

            // Call the topic-level provisioning function.
            $scormcmid = skilland_provision_topic_scorm($skilland, $course, $sectionnum);

            logger::debug('AJAX', 'Successfully provisioned topic SCORM, cmid = ' . $scormcmid);

            return [
                'success' => true,
                'scormcmid' => $scormcmid,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'scormcmid' => 0,
                'error' => self::client_error($e, 'provision_topic_scorm'),
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
            'success' => new external_value(PARAM_BOOL, 'Whether provisioning succeeded'),
            'scormcmid' => new external_value(PARAM_INT, 'The new SCORM course module ID'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
