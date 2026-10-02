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

namespace mod_skilland;

use mod_skilland\local\api_client;
use mod_skilland\local\testing\fixture_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/skilland/lib.php');
require_once($CFG->dirroot . '/mod/skilland/locallib.php');

/**
 * Shared setup of the mod_skilland PHPUnit tests: a configured plugin talking to the fixture API
 * client, a course mapped to the fixture skill, and helpers around the topic SCORM.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class skilland_testcase extends \advanced_testcase {
    /** @var fixture_api_client The API client every Skilland call goes to. */
    protected fixture_api_client $client;

    /**
     * Reset after the test, configure the plugin and bind a fresh fixture client.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        set_config('orgid', 'org-fixture', 'mod_skilland');
        set_config('apikey', 'fixture-api-key', 'mod_skilland');
        set_config('graphql_endpoint', 'https://api.skilland.test', 'mod_skilland');
        logger::reset_cache();
        // Every rebuild deletes a SCORM; skip the recycle bin's backup of each one.
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $this->client = new fixture_api_client();
        \core\di::set(api_client::class, $this->client);
        // The fixture client signs its packages with a test-only key; trust it as an admin would (SKL-650).
        fixture_api_client::trust_fixture_key();
    }

    /**
     * The mod_skilland data generator.
     *
     * @return \mod_skilland_generator
     */
    protected function generator(): \mod_skilland_generator {
        return $this->getDataGenerator()->get_plugin_generator('mod_skilland');
    }

    /**
     * Create a Skilland activity in a course.
     *
     * @param \stdClass $course
     * @param array $record Extra fields for the generator.
     * @return \stdClass The skilland record, with ->cmid.
     */
    protected function create_activity(\stdClass $course, array $record = []): \stdClass {
        global $DB;

        $instance = $this->generator()->create_instance(['course' => $course->id] + $record);
        $skilland = $DB->get_record('skilland', ['id' => $instance->id], '*', MUST_EXIST);
        $skilland->cmid = (int) $instance->cmid;
        return $skilland;
    }

    /**
     * Provision the activity's topic SCORM as the admin, like the provision web service does.
     *
     * @param \stdClass $skilland
     * @return int The SCORM course module id.
     */
    protected function provision(\stdClass $skilland): int {
        $this->setAdminUser();
        [$course, $sectionnum] = $this->course_and_section($skilland);
        return (int) skilland_provision_topic_scorm($skilland, $course, $sectionnum);
    }

    /**
     * Rebuild the activity's topic SCORM as the admin, like the update web service does.
     *
     * @param \stdClass $skilland
     * @return int The new SCORM course module id.
     */
    protected function update(\stdClass $skilland): int {
        $this->setAdminUser();
        [$course, $sectionnum] = $this->course_and_section($skilland);
        return (int) skilland_update_topic_scorm($skilland, $course, $sectionnum);
    }

    /**
     * The course record and section number of an activity.
     *
     * @param \stdClass $skilland
     * @return array [course record, section number]
     */
    protected function course_and_section(\stdClass $skilland): array {
        global $DB;

        $cm = get_coursemodule_from_instance('skilland', $skilland->id, $skilland->course, false, MUST_EXIST);
        $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $cm->section]);
        return [get_course($cm->course), $sectionnum];
    }

    /**
     * Ids of the SCORM course modules of a course.
     *
     * @param int $courseid
     * @return int[]
     */
    protected function scorm_cmids(int $courseid): array {
        global $DB;

        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'scorm'
                 WHERE cm.course = :course AND cm.deletioninprogress = 0
              ORDER BY cm.id";
        return array_map('intval', array_keys($DB->get_records_sql($sql, ['course' => $courseid])));
    }

    /**
     * The activity's lesson rows keyed by Skilland lesson id.
     *
     * @param int $skillandid
     * @return \stdClass[]
     */
    protected function lessons(int $skillandid): array {
        global $DB;

        $lessons = [];
        foreach ($DB->get_records('skilland_lesson', ['skillandid' => $skillandid], 'orderindex') as $lesson) {
            $lessons[$lesson->skilland_lessonid] = $lesson;
        }
        return $lessons;
    }

    /**
     * The course's skilland_course_id custom field row, read straight from customfield_data.
     *
     * @param int $courseid
     * @return \stdClass|null The customfield_data row, null when the course has none.
     */
    protected function course_mapping_row(int $courseid): ?\stdClass {
        global $DB;

        $row = $DB->get_record_sql(
            "SELECT d.*
               FROM {customfield_data} d
               JOIN {customfield_field} f ON f.id = d.fieldid
              WHERE f.shortname = :shortname AND d.instanceid = :courseid",
            ['shortname' => 'skilland_course_id', 'courseid' => $courseid]
        );
        return $row ?: null;
    }

    /**
     * Return and clear the debugging() messages the plugin's logger emitted.
     *
     * @return string[]
     */
    protected function take_debugging(): array {
        $messages = array_map(fn($debug) => $debug->message, $this->getDebuggingMessages());
        $this->resetDebugging();
        return $messages;
    }

    /**
     * Enrol a new user in a course with a role.
     *
     * @param \stdClass $course
     * @param string $role Role shortname.
     * @return \stdClass The user.
     */
    protected function enrol(\stdClass $course, string $role): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $role);
        return $user;
    }
}
