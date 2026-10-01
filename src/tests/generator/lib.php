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

use mod_skilland\local\api_client;
use mod_skilland\local\testing\fixture_api_client;

/**
 * Data generator for mod_skilland.
 *
 * skilland_add_instance() checks the topic against the SkilLand course mapped to the Moodle course
 * over the API, so create_instance() maps the course to the fixture skill when it is unmapped and
 * installs {@see fixture_api_client} unless a fixture client (or a subclass) is already bound.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_skilland_generator extends testing_module_generator {
    /** @var string SkilLand course (skill) of the fixtures. */
    public const FIXTURE_SKILL = 'skill-1';

    /** @var string SkilLand topic of the fixtures. */
    public const FIXTURE_TOPIC = 'topic-1';

    /** @var int Number of lessons created by create_lesson(). */
    protected int $lessoncount = 0;

    /**
     * Reset the generator's counters.
     */
    public function reset() {
        $this->lessoncount = 0;
        parent::reset();
    }

    /**
     * Create a SkilLand activity.
     *
     * Defaults: skilland_topicid topic-1, autoupdate 0, topic_orderindex 1, grade 0, and both fixture
     * lessons selected (pass selected_lessons => '' for none, or a JSON lesson map of your own).
     * Pass provisioned => 1 to also build and link the topic SCORM from the fixture package.
     *
     * @param array|stdClass|null $record
     * @param array|null $options
     * @return stdClass The activity record with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        $record = (object) (array) $record;
        $defaults = [
            'skilland_topicid' => self::FIXTURE_TOPIC,
            'autoupdate' => 0,
            'lockafterfirstaccess' => 0,
            'hidelabels' => 0,
            'topic_orderindex' => 1,
            'grade' => 0,
            'completionlessons' => 0,
            'selected_lessons' => self::fixture_lessons_json(),
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }
        if ($record->selected_lessons === '') {
            unset($record->selected_lessons);
        }

        $provisioned = !empty($record->provisioned);
        unset($record->provisioned);

        $this->ensure_fixture_client();
        if (!empty($record->course) && skilland_get_mapped_courseid((int) $record->course) === null) {
            $this->create_course_mapping((int) $record->course);
        }

        $instance = parent::create_instance($record, (array) $options);
        if ($provisioned) {
            $this->provision_topic_scorm((int) $instance->id);
        }
        return $instance;
    }

    /**
     * Build and link an activity's topic SCORM from the fixture package, as an admin.
     *
     * @param int $skillandid The skilland activity id.
     * @return int The SCORM course module id.
     */
    public function provision_topic_scorm(int $skillandid): int {
        global $DB, $USER;

        $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('skilland', $skilland->id, $skilland->course, false, MUST_EXIST);
        $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

        $this->ensure_fixture_client();
        // The fixture package is signed with a test-only key the site must trust (SKL-650).
        fixture_api_client::trust_fixture_key();
        // The package is staged in the current user's draft area, which a guest or no user lacks.
        $previoususer = $USER;
        $switch = !isloggedin() || isguestuser();
        if ($switch) {
            \core\session\manager::set_user(get_admin());
        }
        try {
            return (int) skilland_provision_topic_scorm($skilland, get_course($cm->course), $sectionnum);
        } finally {
            if ($switch) {
                \core\session\manager::set_user($previoususer);
            }
        }
    }

    /**
     * Install the fixture API client unless a fixture client is already bound.
     *
     * @return fixture_api_client The bound client.
     */
    public function ensure_fixture_client(): fixture_api_client {
        $client = \core\di::get(api_client::class);
        if (!$client instanceof fixture_api_client) {
            $client = new fixture_api_client();
            \core\di::set(api_client::class, $client);
        }
        return $client;
    }

    /**
     * Map a Moodle course to a SkilLand course through the locked course custom field.
     *
     * @param int $courseid Moodle course id.
     * @param string $skillid SkilLand course id.
     */
    public function create_course_mapping(int $courseid, string $skillid = self::FIXTURE_SKILL): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        $field = skilland_get_course_customfield() ?? skilland_ensure_course_customfield();
        if (!$field) {
            throw new coding_exception('The skilland_course_id course custom field is missing');
        }
        /** @var \core_customfield_generator $customfields */
        $customfields = $this->datagenerator->get_plugin_generator('core_customfield');
        $customfields->add_instance_data($field, $courseid, $skillid);
    }

    /**
     * Add a lesson row (skilland_lesson) to an activity.
     *
     * @param array|stdClass $record Needs skillandid.
     * @return stdClass The lesson row.
     */
    public function create_lesson($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        if (empty($record->skillandid)) {
            throw new coding_exception('create_lesson() needs skillandid');
        }
        $this->lessoncount++;
        $n = $this->lessoncount;
        $defaults = [
            'skilland_lessonid' => 'generated-lesson-' . $n,
            'title' => 'Generated lesson ' . $n,
            'orderindex' => $n,
            'visible' => 1,
            'updatedat' => time(),
        ];
        foreach ($defaults as $field => $value) {
            if (!isset($record->$field)) {
                $record->$field = $value;
            }
        }
        $record->id = $DB->insert_record('skilland_lesson', $record);
        return $DB->get_record('skilland_lesson', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * Add a learner progress row (skilland_progress).
     *
     * @param array|stdClass $record Needs skillandid, lessonid (skilland_lesson.id) and userid.
     * @return stdClass The progress row.
     */
    public function create_progress($record): stdClass {
        global $DB;

        $record = (object) (array) $record;
        foreach (['skillandid', 'lessonid', 'userid'] as $required) {
            if (empty($record->$required)) {
                throw new coding_exception('create_progress() needs ' . $required);
            }
        }
        if (!isset($record->status)) {
            $record->status = 'completed';
        }
        if (!property_exists($record, 'score')) {
            $record->score = null;
        }
        if (!isset($record->timemodified)) {
            $record->timemodified = time();
        }
        $record->id = $DB->insert_record('skilland_progress', $record);
        return $DB->get_record('skilland_progress', ['id' => $record->id], '*', MUST_EXIST);
    }

    /**
     * The fixture topic's lessons as the activity form posts them in selected_lessons.
     *
     * @return string JSON lesson map.
     */
    public static function fixture_lessons_json(): string {
        $contents = fixture_api_client::default_responses()['GET topics/{id}/contents']['contents'];
        $selected = [];
        foreach ($contents as $lesson) {
            if ($lesson['type'] !== 'lesson') {
                continue;
            }
            $selected[$lesson['id']] = ['name' => $lesson['name'], 'updatedAt' => $lesson['updatedAt']];
        }
        return json_encode($selected);
    }
}
