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

use core_external\external_api;
use mod_skilland\external\check_topic_snapshot;
use mod_skilland\external\create_course;
use mod_skilland\external\fetch_courses;
use mod_skilland\external\fetch_lessons;
use mod_skilland\external\fetch_topics;
use mod_skilland\external\provision_topic_scorm;
use mod_skilland\external\update_topic_scorm;
use mod_skilland\local\topic_scorm_updater;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * The web services of db/services.php: parameters, context and capability checks, and return
 * values cleaned against their declared structure.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\external\base
 * @covers     \mod_skilland\external\fetch_courses
 * @covers     \mod_skilland\external\create_course
 * @covers     \mod_skilland\external\fetch_topics
 * @covers     \mod_skilland\external\fetch_lessons
 * @covers     \mod_skilland\external\provision_topic_scorm
 * @covers     \mod_skilland\external\update_topic_scorm
 * @covers     \mod_skilland\external\check_topic_snapshot
 */
final class external_functions_test extends skilland_testcase {

    /**
     * A course mapped to a SkilLand course, with an editing teacher and a student.
     *
     * @param string $skillid SkilLand course the Moodle course is mapped to.
     * @return array [course, teacher, student]
     */
    private function mapped_course(string $skillid = 'skill-1'): array {
        $course = $this->getDataGenerator()->create_course();
        $this->generator()->create_course_mapping($course->id, $skillid);
        return [$course, $this->enrol($course, 'editingteacher'), $this->enrol($course, 'student')];
    }

    public function test_services_are_registered_with_their_classes(): void {
        global $DB;

        $expected = [
            'mod_skilland_fetch_courses_ajax' => fetch_courses::class,
            'mod_skilland_create_course_ajax' => create_course::class,
            'mod_skilland_fetch_topics_ajax' => fetch_topics::class,
            'mod_skilland_fetch_lessons_ajax' => fetch_lessons::class,
            'mod_skilland_provision_topic_scorm_ajax' => provision_topic_scorm::class,
            'mod_skilland_update_topic_scorm_ajax' => update_topic_scorm::class,
            'mod_skilland_check_topic_snapshot' => check_topic_snapshot::class,
        ];
        $registered = $DB->get_records_menu('external_functions', ['component' => 'mod_skilland'], '', 'name, classname');
        ksort($expected);
        ksort($registered);
        $this->assertSame(array_map(fn($class) => ltrim($class, '\\'), $expected),
            array_map(fn($class) => ltrim($class, '\\'), $registered));
    }

    public function test_fetch_courses_lists_every_skill_to_an_admin(): void {
        [$course] = $this->mapped_course();
        $this->setAdminUser();

        $result = external_api::clean_returnvalue(fetch_courses::execute_returns(), fetch_courses::execute($course->id));

        $this->assertNull($result['error']);
        $this->assertSame(['skill-1', 'skill-2'], array_column($result['courses'], 'id'));
        $this->assertSame(0, $this->client->count_calls('MoodleUserCourses'));
    }

    public function test_fetch_courses_limits_a_teacher_to_their_mapped_and_editable_skills(): void {
        [$course, $teacher] = $this->mapped_course('skill-2');
        $this->client->set_response('MoodleUserCourses', ['moodleUserCourses' => []]);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(fetch_courses::execute_returns(), fetch_courses::execute($course->id));

        $this->assertSame(['skill-2'], array_column($result['courses'], 'id'));
        $calls = array_values(array_filter($this->client->calls, fn($c) => $c['operation'] === 'MoodleUserCourses'));
        $this->assertSame($teacher->email, $calls[0]['variables']['userEmail']);
    }

    public function test_fetch_courses_returns_a_safe_error_when_the_api_fails(): void {
        [$course] = $this->mapped_course();
        $this->client->set_response('MoodleListCourses',
            new \moodle_exception('error_graphql_http', 'mod_skilland', '', null, 'upstream secret detail'));
        $this->setAdminUser();

        $result = external_api::clean_returnvalue(fetch_courses::execute_returns(), fetch_courses::execute($course->id));
        $this->take_debugging();

        $this->assertSame([], $result['courses']);
        $this->assertNotEmpty($result['error']);
        $this->assertStringNotContainsString('upstream secret detail', $result['error']);
    }

    /**
     * Each course-level service, called by a student, who lacks mod/skilland:accessstudio.
     *
     * @return array
     */
    public static function course_services_provider(): array {
        return [
            'fetch_courses' => [fetch_courses::class, fn($course) => [$course->id]],
            'create_course' => [create_course::class, fn($course) => [$course->id]],
            'fetch_topics' => [fetch_topics::class, fn($course) => ['skill-1', $course->id]],
            'fetch_lessons' => [fetch_lessons::class, fn($course) => ['topic-1', $course->id]],
        ];
    }

    /**
     * @dataProvider course_services_provider
     * @param string $class External function class.
     * @param \Closure $args Builds the arguments from the course.
     */
    public function test_course_services_require_studio_access(string $class, \Closure $args): void {
        [$course, , $student] = $this->mapped_course();
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        $class::execute(...$args($course));
    }

    /**
     * @dataProvider course_services_provider
     * @param string $class External function class.
     * @param \Closure $args Builds the arguments from the course.
     */
    public function test_course_services_refuse_a_course_the_user_is_not_enrolled_in(string $class, \Closure $args): void {
        [$course] = $this->mapped_course();
        [, $otherteacher] = $this->mapped_course();
        $this->setUser($otherteacher);

        $this->expectException(\require_login_exception::class);
        $class::execute(...$args($course));
    }

    public function test_create_course_creates_the_skill_and_offers_the_studio_link(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Algebra 101']);
        // A course saved through the course form has a (still empty) SkilLand course field.
        $this->generator()->create_course_mapping($course->id, '');
        $teacher = $this->enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(create_course::execute_returns(), create_course::execute($course->id));

        $this->assertNull($result['error']);
        $this->assertSame('skill-new', $result['skillid']);
        $this->assertSame(['name' => 'Algebra 101', 'userEmail' => $teacher->email], $this->client->calls[0]['variables']);
        $this->assertSame('/skills-studio/create/microcredential-upload/skill-new',
            mod_skilland_peek_pending_studio_path((int) $course->id));
        // The mapping is persisted in the text field's own column and reads back.
        $this->assertSame('skill-new', $this->course_mapping_row((int) $course->id)->charvalue);
        $this->assertSame('skill-new', skilland_get_course_customfield_value((int) $course->id));
        $this->assertSame('skill-new', skilland_get_mapped_courseid((int) $course->id));
    }

    public function test_create_course_persists_the_mapping_on_a_course_without_field_data(): void {
        $course = $this->getDataGenerator()->create_course(['fullname' => 'Geometry 101']);
        $this->assertNotNull(skilland_ensure_course_customfield());
        $this->assertNull($this->course_mapping_row((int) $course->id));
        $teacher = $this->enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(create_course::execute_returns(), create_course::execute($course->id));

        $this->assertNull($result['error']);
        $this->assertSame('skill-new', $result['skillid']);
        $row = $this->course_mapping_row((int) $course->id);
        $this->assertNotNull($row);
        $this->assertSame('skill-new', $row->charvalue);
        $this->assertEquals(\context_course::instance($course->id)->id, $row->contextid);
        $this->assertSame('skill-new', skilland_get_course_customfield_value((int) $course->id));
    }

    public function test_fetch_topics_returns_the_topics_of_the_mapped_skill(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(fetch_topics::execute_returns(),
            fetch_topics::execute('skill-1', $course->id));

        $this->assertNull($result['error']);
        $this->assertSame('skill-1', $result['course']['id']);
        $this->assertSame(['topic-1', 'topic-2'], array_column($result['topics'], 'id'));
        $this->assertSame('<p>First fixture topic</p>', $result['topics'][0]['description']);
    }

    public function test_fetch_topics_refuses_a_skill_the_course_is_not_mapped_to(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->setUser($teacher);

        try {
            fetch_topics::execute('skill-2', $course->id);
            $this->fail('Another skill must be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_not_mapped_to_skill', $e->errorcode);
        }
        $this->assertSame([], $this->client->calls);
    }

    public function test_fetch_topics_refuses_an_unmapped_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->enrol($course, 'editingteacher'));

        $this->expectExceptionObject(new \moodle_exception('error_course_not_mapped', 'mod_skilland'));
        fetch_topics::execute('skill-1', $course->id);
    }

    public function test_fetch_topics_validates_its_parameters(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->setUser($teacher);

        $this->expectException(\invalid_parameter_exception::class);
        fetch_topics::execute('skill 1; DROP', $course->id);
    }

    public function test_fetch_lessons_returns_the_lessons_of_a_topic_of_the_mapped_skill(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(fetch_lessons::execute_returns(),
            fetch_lessons::execute('topic-1', $course->id));

        $this->assertNull($result['error']);
        $this->assertSame(['lesson-1', 'lesson-2'], array_column($result['lessons'], 'id'));
        $this->assertSame('2026-01-01T10:00:00Z', $result['lessons'][0]['updatedAt']);
    }

    public function test_fetch_lessons_refuses_a_topic_of_another_skill(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->setUser($teacher);

        $this->expectExceptionObject(new \moodle_exception('error_course_not_mapped_to_skill', 'mod_skilland'));
        fetch_lessons::execute('topic-of-another-skill', $course->id);
    }

    public function test_fetch_lessons_hides_an_api_failure_behind_a_generic_error(): void {
        [$course, $teacher] = $this->mapped_course();
        $this->client->set_response('MoodleListTopics', new \coding_exception('curl said: secret'));
        $this->setUser($teacher);

        try {
            fetch_lessons::execute('topic-1', $course->id);
            $this->fail('An API failure must surface as an error');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_api_unavailable', $e->errorcode);
        }
        $this->take_debugging();
    }

    public function test_provision_builds_the_topic_scorm_for_a_teacher(): void {
        global $DB;

        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(provision_topic_scorm::execute_returns(),
            provision_topic_scorm::execute($skilland->id, $skilland->cmid));

        $this->assertTrue($result['success']);
        $this->assertNull($result['error']);
        $this->assertEquals($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]), $result['scormcmid']);
        $this->assertSame([$result['scormcmid']], $this->scorm_cmids($course->id));
    }

    /**
     * Each module-level service.
     *
     * @return array
     */
    public static function module_services_provider(): array {
        return [
            'provision_topic_scorm' => [provision_topic_scorm::class, fn($s) => [$s->id, $s->cmid]],
            'update_topic_scorm' => [update_topic_scorm::class, fn($s) => [$s->id, $s->cmid]],
            'check_topic_snapshot' => [check_topic_snapshot::class, fn($s) => [$s->id]],
        ];
    }

    /**
     * @dataProvider module_services_provider
     * @param string $class External function class.
     * @param \Closure $args Builds the arguments from the activity.
     */
    public function test_module_services_require_the_provision_capability(string $class, \Closure $args): void {
        [$course, , $student] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $this->setUser($student);

        $this->expectException(\required_capability_exception::class);
        $class::execute(...$args($skilland));
    }

    /**
     * @dataProvider module_services_provider
     * @param string $class External function class.
     * @param \Closure $args Builds the arguments from the activity.
     */
    public function test_module_services_refuse_when_the_plugin_is_disabled(string $class, \Closure $args): void {
        global $DB;

        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $DB->set_field('modules', 'visible', 0, ['name' => 'skilland']);
        $this->setUser($teacher);

        $this->expectExceptionObject(new \moodle_exception('error_plugin_disabled', 'mod_skilland'));
        $class::execute(...$args($skilland));
    }

    public function test_provision_refuses_an_activity_that_does_not_match_the_module(): void {
        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $other = $this->create_activity($course, ['name' => 'Other topic']);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(provision_topic_scorm::execute_returns(),
            provision_topic_scorm::execute($other->id, $skilland->cmid));
        $this->take_debugging();

        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
        $this->assertSame([], $this->scorm_cmids($course->id));
    }

    public function test_update_replaces_the_topic_scorm(): void {
        global $DB;

        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $oldcmid = $this->provision($skilland);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(update_topic_scorm::execute_returns(),
            update_topic_scorm::execute($skilland->id, $skilland->cmid));

        $this->assertTrue($result['success']);
        $this->assertNotEquals($oldcmid, $result['scormcmid']);
        $this->assertEquals($result['scormcmid'], $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
        $this->assertSame([$result['scormcmid']], $this->scorm_cmids($course->id));
    }

    public function test_update_reports_a_rebuild_failure_without_throwing(): void {
        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        \core\di::set(topic_scorm_updater::class, new class extends topic_scorm_updater {
            /** @var int Calls received. */
            public int $calls = 0;

            public function update($skilland, $course, $sectionnum = 0, ?string $contenthash = null) {
                $this->calls++;
                throw new \moodle_exception('error_provision_in_progress', 'mod_skilland');
            }
        });
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(update_topic_scorm::execute_returns(),
            update_topic_scorm::execute($skilland->id, $skilland->cmid));
        $this->take_debugging();

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['scormcmid']);
        $this->assertSame(get_string('error_provision_in_progress', 'mod_skilland'), $result['error']);
        $this->assertSame(1, \core\di::get(topic_scorm_updater::class)->calls);
    }

    public function test_check_topic_snapshot_reports_current_content(): void {
        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(check_topic_snapshot::execute_returns(),
            check_topic_snapshot::execute($skilland->id));

        $this->assertSame(['isstale' => false, 'contenthash' => 'hash-v1', 'studentattemptcount' => 0, 'error' => null],
            $result);
    }

    public function test_check_topic_snapshot_detects_changed_content_and_counts_students_at_risk(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        [$course, $teacher, $student] = $this->mapped_course();
        $otherstudent = $this->enrol($course, 'student');
        $skilland = $this->create_activity($course);
        $cmid = $this->provision($skilland);
        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $scoid = (int) $DB->get_field('skilland_lesson', 'scoid',
            ['skillandid' => $skilland->id, 'skilland_lessonid' => 'lesson-1']);
        foreach ([$student, $otherstudent] as $user) {
            scorm_insert_track($user->id, $scormid, $scoid, 1, 'cmi.core.lesson_status', 'incomplete');
        }
        $this->take_debugging();
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(check_topic_snapshot::execute_returns(),
            check_topic_snapshot::execute($skilland->id));

        $this->assertTrue($result['isstale']);
        $this->assertSame('hash-v2', $result['contenthash']);
        $this->assertSame(2, $result['studentattemptcount']);
        $this->assertNull($result['error']);
    }

    public function test_check_topic_snapshot_is_not_stale_when_the_api_is_down(): void {
        [$course, $teacher] = $this->mapped_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $this->client->set_response('TopicScormHash', new \moodle_exception('error_api_unavailable', 'mod_skilland'));
        $this->setUser($teacher);

        $result = external_api::clean_returnvalue(check_topic_snapshot::execute_returns(),
            check_topic_snapshot::execute($skilland->id));
        $this->take_debugging();

        $this->assertFalse($result['isstale']);
        $this->assertSame('', $result['contenthash']);
        $this->assertSame('Could not reach Skilland API', $result['error']);
    }
}
