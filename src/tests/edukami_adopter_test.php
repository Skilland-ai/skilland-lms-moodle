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

use mod_skilland\local\edukami_adopter;
use mod_skilland\local\edukami_ids;
use mod_skilland\local\testing\fixture_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/skilland_testcase.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');
require_once($CFG->libdir . '/ddllib.php');

/**
 * Adopting mod_edukami activities as mod_skilland activities (SKL-997).
 *
 * mod_edukami is not installed on the test site: its two tables are created here with the columns of
 * its install.xml, and its activities are bare course modules of a registered "edukami" module.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_skilland\local\edukami_adopter
 */
final class edukami_adopter_test extends skilland_testcase {
    /** @var string Edukami skill ObjectId of the course. */
    private const SKILL_OID = '64a1f0c2b3d4e5f601234567';

    /** @var string Edukami topic ObjectId. */
    private const TOPIC_OID = '64a1f0c2b3d4e5f601234568';

    /** @var string A second Edukami topic ObjectId. */
    private const TOPIC2_OID = '64a1f0c2b3d4e5f60123456b';

    /** @var string Edukami lesson ObjectId, migrated under its scoped id. */
    private const LESSON1_OID = '64a1f0c2b3d4e5f601234569';

    /** @var string Edukami lesson ObjectId, migrated under its unscoped id. */
    private const LESSON2_OID = '64a1f0c2b3d4e5f60123456a';

    /** @var int Number of Edukami activities created by the test. */
    private int $edukamis = 0;

    /**
     * Create mod_edukami's tables, module and course custom field.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        // The adopter runs each activity in its own transaction, never inside PostgreSQL's test one.
        $this->preventResetByRollback();

        $this->create_edukami_tables();
        $DB->insert_record('modules', (object) ['name' => 'edukami', 'cron' => 0, 'lastcron' => 0, 'search' => '',
            'visible' => 1]);

        /** @var \core_customfield_generator $customfields */
        $customfields = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $category = $customfields->create_category(['name' => 'Edukami']);
        $customfields->create_field(['categoryid' => $category->get('id'), 'type' => 'text',
            'shortname' => 'edukami_course_id', 'name' => 'Edukami course id']);
    }

    /**
     * Drop mod_edukami's tables.
     */
    protected function tearDown(): void {
        global $DB;

        $dbman = $DB->get_manager();
        foreach (['edukami_lesson', 'edukami'] as $name) {
            $table = new \xmldb_table($name);
            if ($dbman->table_exists($table)) {
                $dbman->drop_table($table);
            }
        }
        parent::tearDown();
    }

    /**
     * An Edukami activity with a learner attempt becomes a Skilland activity on the same SCORM.
     */
    public function test_adopts_an_activity_keeping_its_scorm_attempts_and_snapshot(): void {
        global $DB;

        $course = $this->edukami_course();
        $student = $this->enrol($course, 'student');
        $edukami = $this->edukami_activity($course, self::TOPIC_OID);
        $scos = $DB->get_records_menu('scorm_scoes', ['scorm' => $edukami->scormid], '', 'identifier, id');
        scorm_insert_track($student->id, $edukami->scormid, $scos['sco-lesson-1'], 1, 'cmi.core.lesson_status', 'completed');
        $this->take_debugging();
        $tracks = $DB->count_records('scorm_scoes_value');
        $scormcm = $DB->get_record('course_modules', ['id' => $edukami->scormcmid], '*', MUST_EXIST);
        $this->listing([self::topic_id()]);

        $report = $this->adopt(true);

        $this->assertSame(['adopted' => 1, 'already adopted' => 0, 'skipped' => 0, 'failed' => 0], $report['counts']);
        $this->assertSame(edukami_ids::skill_id(self::SKILL_OID), skilland_get_mapped_courseid((int) $course->id));
        $skilland = $DB->get_record('skilland', ['course' => $course->id], '*', MUST_EXIST);
        $this->assertSame((int) $skilland->id, $report['activities'][0]['skillandid']);
        $this->assertSame('Edukami topic 1', $skilland->name);
        $this->assertSame(self::topic_id(), $skilland->skilland_topicid);
        $this->assertSame($edukami->scormcmid, (int) $skilland->scormcmid);
        $this->assertSame('legacy-hash-1', $skilland->snapshotid);
        $this->assertSame(1700000000, (int) $skilland->snapshotcreatedat);
        $this->assertSame(1700000100, (int) $skilland->scorm_provisioned);
        $this->assertNull($skilland->lastsynced);
        $this->assertSame(1, (int) $skilland->autoupdate);
        $this->assertSame(2, (int) $skilland->topic_orderindex);
        $this->assertSame(0, (int) $skilland->grade);
        $this->assertSame(
            [self::lesson1_id() => 'sco-lesson-1', self::lesson2_id() => 'sco-lesson-2'],
            json_decode($skilland->scomappings, true)
        );

        $lessons = $this->lessons((int) $skilland->id);
        $this->assertSame([self::lesson1_id(), self::lesson2_id()], array_keys($lessons));
        $this->assertSame((int) $scos['sco-lesson-1'], (int) $lessons[self::lesson1_id()]->scoid);
        $this->assertSame('sco-lesson-1', $lessons[self::lesson1_id()]->sco_identifier);
        $this->assertSame((int) $scos['sco-lesson-2'], (int) $lessons[self::lesson2_id()]->scoid);
        $this->assertSame('Lesson one', $lessons[self::lesson1_id()]->title);

        // Right before the Edukami activity, in its section; the Edukami activity is hidden, not deleted.
        $cm = get_coursemodule_from_instance('skilland', $skilland->id, $course->id, false, MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
        $this->assertSame(1, (int) $section->section);
        $sequence = array_map('intval', explode(',', $section->sequence));
        $this->assertSame(array_search($edukami->cmid, $sequence) - 1, array_search((int) $cm->id, $sequence));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
        $this->assertTrue($DB->record_exists('edukami', ['id' => $edukami->id]));

        // The SCORM, its attempt and its tracks are untouched and read as the learner's progress.
        $this->assertEquals($scormcm, $DB->get_record('course_modules', ['id' => $edukami->scormcmid]));
        $this->assertSame($tracks, $DB->count_records('scorm_scoes_value'));
        $progress = skilland_read_scorm_progress($skilland, [(int) $student->id]);
        $this->assertSame('completed', $progress[$student->id][(int) $lessons[self::lesson1_id()]->id]['status']);
        $this->assertSame('completed', $DB->get_field(
            'skilland_progress',
            'status',
            ['skillandid' => $skilland->id, 'userid' => $student->id, 'lessonid' => $lessons[self::lesson1_id()]->id]
        ));

        // Nothing was provisioned or downloaded.
        $this->assertSame(0, $this->client->count_calls('GET topics/{id}/scorm'));
        $this->assertSame([], $this->client->downloads);

        // A second run is a no-op.
        $again = $this->adopt(true);
        $this->assertSame(['adopted' => 0, 'already adopted' => 1, 'skipped' => 0, 'failed' => 0], $again['counts']);
        $this->assertSame(1, $DB->count_records('skilland', ['course' => $course->id]));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
    }

    /**
     * An already adopted activity whose Edukami activity is visible again gets it hidden.
     */
    public function test_rerun_hides_an_edukami_activity_shown_again(): void {
        global $DB;

        $course = $this->edukami_course();
        $edukami = $this->edukami_activity($course, self::TOPIC_OID);
        $this->listing([self::topic_id()]);
        $this->adopt(true);
        set_coursemodule_visible($edukami->cmid, 1);

        $report = $this->adopt(true);

        $this->assertSame(1, $report['counts']['already adopted']);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
    }

    /**
     * A dry run reports what it would do and writes nothing.
     */
    public function test_dry_run_writes_nothing(): void {
        global $DB;

        $course = $this->edukami_course();
        $edukami = $this->edukami_activity($course, self::TOPIC_OID);
        $this->listing([self::topic_id()]);
        $sequence = $DB->get_field('course_sections', 'sequence', ['course' => $course->id, 'section' => 1]);

        $report = $this->adopt(false);

        $this->assertSame(1, $report['counts']['adopted']);
        $this->assertStringStartsWith('would adopt', $report['activities'][0]['reason']);
        $this->assertSame(0, $DB->count_records('skilland'));
        $this->assertNull(skilland_get_mapped_courseid((int) $course->id));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
        $this->assertSame($sequence, $DB->get_field('course_sections', 'sequence', ['course' => $course->id, 'section' => 1]));
        $lines = edukami_adopter::format([$report], false);
        $this->assertStringContainsString('Dry run, nothing written', end($lines));
    }

    /**
     * An activity whose topic Skilland does not list is skipped and nothing is hidden.
     */
    public function test_topic_not_in_the_migration_is_skipped(): void {
        global $DB;

        $course = $this->edukami_course();
        $edukami = $this->edukami_activity($course, self::TOPIC_OID);
        $this->listing([edukami_ids::mongo_uuid('topics', 'another-topic')]);

        $report = $this->adopt(true);

        $this->assertSame(['adopted' => 0, 'already adopted' => 0, 'skipped' => 1, 'failed' => 0], $report['counts']);
        $this->assertStringContainsString('not in the migration', $report['activities'][0]['reason']);
        $this->assertSame(0, $DB->count_records('skilland'));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
        $this->assertNull(skilland_get_mapped_courseid((int) $course->id));
    }

    /**
     * A course already mapped to another skill is left alone.
     */
    public function test_course_mapped_to_another_skill_is_skipped(): void {
        global $DB;

        $course = $this->edukami_course();
        $edukami = $this->edukami_activity($course, self::TOPIC_OID);
        $this->generator()->create_course_mapping((int) $course->id, 'another-skill');
        $this->listing([self::topic_id()]);

        $report = $this->adopt(true);

        $this->assertSame(1, $report['counts']['skipped']);
        $this->assertStringContainsString('another-skill', $report['activities'][0]['reason']);
        $this->assertSame(0, $this->client->count_calls('GET skills/{id}/topics'));
        $this->assertSame(0, $DB->count_records('skilland'));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $edukami->cmid]));
        $this->assertSame('another-skill', skilland_get_mapped_courseid((int) $course->id));
    }

    /**
     * A course mapped to the skill's Edukami ObjectId counts as mapped to that skill and keeps its mapping.
     */
    public function test_course_mapped_to_the_skill_object_id_is_adopted(): void {
        global $DB;

        $course = $this->edukami_course();
        $this->edukami_activity($course, self::TOPIC_OID);
        $this->generator()->create_course_mapping((int) $course->id, self::SKILL_OID);
        $this->listing([self::topic_id()]);

        $report = $this->adopt(true);

        $this->assertSame(1, $report['counts']['adopted']);
        $this->assertSame(self::SKILL_OID, skilland_get_mapped_courseid((int) $course->id));
        $this->assertSame(1, $DB->count_records('skilland'));
    }

    /**
     * A failure inside create_module() rolls that activity back whole and the next one is adopted.
     */
    public function test_a_failure_inside_create_module_is_rolled_back_and_the_run_continues(): void {
        global $DB;

        $course = $this->edukami_course();
        $first = $this->edukami_activity($course, self::TOPIC_OID);
        $second = $this->edukami_activity($course, self::TOPIC2_OID);
        $topics = ['topics' => [['id' => self::topic_id()], ['id' => self::topic_id(self::TOPIC2_OID)]]];
        $calls = 0;
        // The run lists the topics once, then skilland_add_instance() checks the topic once per activity.
        $this->client->set_response('GET skills/{id}/topics', function () use (&$calls, $topics) {
            $calls++;
            if ($calls === 2) {
                throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
            }
            return $topics;
        });
        $this->lesson_listing();

        $report = $this->adopt(true);

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(['adopted' => 1, 'already adopted' => 0, 'skipped' => 0, 'failed' => 1], $report['counts']);
        $this->assertSame(edukami_adopter::FAILED, $report['activities'][0]['outcome']);
        $this->assertStringStartsWith('rolled back', $report['activities'][0]['reason']);
        $this->assertSame(1, $DB->count_records('skilland'));
        $this->assertSame([$second->scormcmid], array_map('intval', $DB->get_fieldset_select('skilland', 'scormcmid', '1 = 1')));
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $first->cmid]));
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $second->cmid]));
        $this->assertSame(1, $DB->count_records_select(
            'course_modules',
            'course = ? AND module = ?',
            [$course->id, $DB->get_field('modules', 'id', ['name' => 'skilland'])]
        ));
        $this->assertSame(2, $DB->count_records('skilland_lesson'));
        // The course page still renders the course.
        $this->assertCount(1, get_fast_modinfo($course->id)->get_instances_of('skilland'));
    }

    /**
     * Without mod_edukami's tables the run fails with a clear message.
     */
    public function test_missing_edukami_table_fails_clearly(): void {
        global $DB;

        $DB->get_manager()->drop_table(new \xmldb_table('edukami_lesson'));
        $DB->get_manager()->drop_table(new \xmldb_table('edukami'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('the edukami table does not exist');
        (new edukami_adopter(false))->run();
    }

    /**
     * Run the adopter as the CLI does and return the only course report.
     *
     * @param bool $apply
     * @return array
     */
    private function adopt(bool $apply): array {
        $this->setAdminUser();
        $reports = (new edukami_adopter($apply))->run();
        $this->take_debugging();
        $this->assertCount(1, $reports);
        return $reports[0];
    }

    /**
     * Answer the topic listing with these topic ids and the lesson listing with the migrated lessons.
     *
     * @param string[] $topicids
     */
    private function listing(array $topicids): void {
        $this->client->set_response('GET skills/{id}/topics', function (array $variables) use ($topicids): array {
            if ($variables['skillId'] !== edukami_ids::skill_id(self::SKILL_OID) && $variables['skillId'] !== self::SKILL_OID) {
                return ['topics' => []];
            }
            return ['topics' => array_map(fn($id) => ['id' => $id, 'name' => 'Topic'], $topicids)];
        });
        $this->lesson_listing();
    }

    /**
     * Answer the lesson listing of every topic with the two migrated lessons and an exam.
     */
    private function lesson_listing(): void {
        $this->client->set_response('GET topics/{id}/contents', ['contents' => [
            ['id' => self::lesson1_id(), 'name' => 'Lesson one', 'type' => 'lesson', 'content' => '<p>1</p>',
                'updatedAt' => '2026-01-01T10:00:00Z'],
            ['id' => self::lesson2_id(), 'name' => 'Lesson two', 'type' => 'lesson', 'content' => '<p>2</p>',
                'updatedAt' => '2026-01-01T10:00:00Z'],
            ['id' => 'exam-1', 'name' => 'Exam', 'type' => 'exam', 'content' => null, 'updatedAt' => '2026-01-01T10:00:00Z'],
        ]]);
    }

    /**
     * The migrated topic id Skilland lists: the unscoped one, so the scoped candidate is tried first and missed.
     *
     * @param string $topicoid
     * @return string
     */
    private static function topic_id(string $topicoid = self::TOPIC_OID): string {
        return edukami_ids::topic_id_candidates($topicoid, self::SKILL_OID)[1];
    }

    /**
     * The migrated id of lesson one: its scoped id.
     *
     * @return string
     */
    private static function lesson1_id(): string {
        return edukami_ids::content_id_candidates(self::LESSON1_OID, self::SKILL_OID)[0];
    }

    /**
     * The migrated id of lesson two: its unscoped id.
     *
     * @return string
     */
    private static function lesson2_id(): string {
        return edukami_ids::content_id_candidates(self::LESSON2_OID, self::SKILL_OID)[1];
    }

    /**
     * A course whose Edukami course custom field names the fixture skill.
     *
     * @return \stdClass
     */
    private function edukami_course(): \stdClass {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $field = $DB->get_record('customfield_field', ['shortname' => 'edukami_course_id'], '*', MUST_EXIST);
        /** @var \core_customfield_generator $customfields */
        $customfields = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $customfields->add_instance_data(
            \core_customfield\field_controller::create((int) $field->id),
            (int) $course->id,
            strtoupper(self::SKILL_OID)
        );
        return $course;
    }

    /**
     * An Edukami activity in section 1 with its topic SCORM (built from the fixture package) and two lessons.
     *
     * @param \stdClass $course
     * @param string $topicoid Edukami topic ObjectId.
     * @return \stdClass id, cmid, scormcmid and scormid.
     */
    private function edukami_activity(\stdClass $course, string $topicoid): \stdClass {
        global $DB;

        $this->setAdminUser();
        $this->edukamis++;
        $n = $this->edukamis;
        $scormcmid = skilland_create_topic_scorm_module(
            (object) ['id' => 'edukami' . $n, 'name' => 'Edukami topic ' . $n],
            $course,
            1,
            fixture_api_client::build_package(fixture_api_client::fixtures_dir() . '/scorm')
        );
        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $scormcmid]);
        $scos = $DB->get_records_menu('scorm_scoes', ['scorm' => $scormid], '', 'identifier, id');

        $id = $DB->insert_record('edukami', (object) [
            'course' => $course->id,
            'name' => 'Edukami topic ' . $n,
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'edukami_topicid' => $topicoid,
            'snapshotid' => 'legacy-hash-' . $n,
            'snapshotcreatedat' => 1700000000,
            'lastsynced' => 1700000200,
            'autoupdate' => 1,
            'lockafterfirstaccess' => 0,
            'hidelabels' => 0,
            'topic_orderindex' => 2,
            'scormcmid' => $scormcmid,
            'scorm_provisioned' => 1700000100,
            'timecreated' => 1700000000,
            'timemodified' => 1700000000,
        ]);
        $edukamilessons = [
            [self::LESSON1_OID, 'Lesson one', 'sco-lesson-1'],
            [self::LESSON2_OID, 'Lesson two', 'sco-lesson-2'],
        ];
        foreach ($edukamilessons as $i => $l) {
            $DB->insert_record('edukami_lesson', (object) [
                'edukamiid' => $id,
                'edukami_lessonid' => $l[0],
                'title' => $l[1],
                'orderindex' => $i + 1,
                'scoid' => $scos[$l[2]],
                'sco_identifier' => $l[2],
                'snapshotid' => 'lesson-hash',
                'updatedat' => 1700000000,
                'visible' => 1,
            ]);
        }

        $cmid = add_course_module((object) [
            'course' => $course->id,
            'module' => $DB->get_field('modules', 'id', ['name' => 'edukami'], MUST_EXIST),
            'instance' => $id,
            'section' => 0,
            'visible' => 1,
            'visibleoncoursepage' => 1,
        ]);
        course_add_cm_to_section($course->id, $cmid, 1);
        \context_module::instance($cmid);
        rebuild_course_cache($course->id, true);
        $this->take_debugging();

        return (object) ['id' => $id, 'cmid' => (int) $cmid, 'scormcmid' => (int) $scormcmid, 'scormid' => $scormid];
    }

    /**
     * Create mod_edukami's edukami and edukami_lesson tables (the columns of its install.xml).
     */
    private function create_edukami_tables(): void {
        global $DB;

        $dbman = $DB->get_manager();

        $table = new \xmldb_table('edukami');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('intro', XMLDB_TYPE_TEXT);
        $table->add_field('introformat', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('edukami_topicid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('snapshotid', XMLDB_TYPE_CHAR, '64');
        $table->add_field('snapshotcreatedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('lastsynced', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('autoupdate', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('lockafterfirstaccess', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('hidelabels', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('topic_orderindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('scormcmid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('scorm_provisioned', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('course_idx', XMLDB_INDEX_NOTUNIQUE, ['course']);
        $dbman->create_table($table);

        $table = new \xmldb_table('edukami_lesson');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('edukamiid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('edukami_lessonid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL);
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL);
        $table->add_field('orderindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('scormcmid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('scoid', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('sco_identifier', XMLDB_TYPE_CHAR, '255');
        $table->add_field('snapshotid', XMLDB_TYPE_CHAR, '64');
        $table->add_field('snapshotcreatedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('updatedat', XMLDB_TYPE_INTEGER, '10');
        $table->add_field('visible', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('edukamiid_idx', XMLDB_INDEX_NOTUNIQUE, ['edukamiid']);
        $dbman->create_table($table);
    }
}
