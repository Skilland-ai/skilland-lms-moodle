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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * Provisioning and rebuilding the topic SCORM against real mod_scorm, through the fixture API.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::skilland_provision_topic_scorm
 * @covers ::skilland_update_topic_scorm
 * @covers ::skilland_build_topic_scorm
 * @covers ::skilland_link_topic_scorm
 */
final class topic_scorm_provisioning_test extends skilland_testcase {

    public function test_provision_creates_a_stealth_scorm_and_maps_every_lesson(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);

        $cmid = $this->provision($skilland);

        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
        $cm = get_coursemodule_from_id('scorm', $cmid, $course->id, false, MUST_EXIST);
        $this->assertSame(1, (int) $cm->visible);
        $this->assertSame(0, (int) $cm->visibleoncoursepage);
        $this->assertSame('skilland_topic_' . $skilland->id, $cm->idnumber);

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertSame($cmid, (int) $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
        $this->assertNotEmpty($record->scorm_provisioned);
        $this->assertSame(['lesson-1' => 'sco-lesson-1', 'lesson-2' => 'sco-lesson-2'],
            json_decode($record->scomappings, true));

        $scoes = $DB->get_records_menu('scorm_scoes', ['scorm' => $cm->instance], '', 'identifier, id');
        foreach ($this->lessons($skilland->id) as $lessonid => $lesson) {
            $this->assertSame('sco-' . $lessonid, $lesson->sco_identifier);
            $this->assertEquals($scoes['sco-' . $lessonid], $lesson->scoid);
        }

        $this->assertSame(1, $this->client->count_calls('GetTopicScorm'));
        $this->assertSame(1, $this->client->count_calls('TopicScormHash'));
        $this->assertCount(1, $this->client->downloads);
        $this->assertSame('https://packages.skilland.test/topic-1.zip', $this->client->downloads[0]['url']);
    }

    public function test_provision_is_idempotent(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);

        $first = $this->provision($skilland);
        $second = $this->provision($skilland);

        $this->assertSame($first, $second);
        $this->assertSame([$first], $this->scorm_cmids($course->id));
        $this->assertCount(1, $this->client->downloads);
    }

    public function test_provision_rebuilds_when_the_linked_scorm_is_gone(): void {
        global $DB;
        // The course_module_deleted observer is external: it only runs outside the test transaction.
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $first = $this->provision($skilland);

        // Delete behind the plugin's back: the observer unlinks, and a new provision builds again.
        course_delete_module($first);
        $this->take_debugging();
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));

        $second = $this->provision($skilland);

        $this->assertNotSame($first, $second);
        $this->assertSame([$second], $this->scorm_cmids($course->id));
    }

    public function test_provision_rolls_back_the_module_when_a_mapped_sco_is_missing(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['mappings' => [
            ['lessonId' => 'lesson-1', 'scoId' => 'sco-lesson-1'],
            ['lessonId' => 'lesson-2', 'scoId' => 'sco-not-in-package'],
        ]]);

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail when the package lacks a mapped SCO');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_parse_failed', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNull($record->scormcmid);
        $this->assertNull($record->snapshotid);
        foreach ($this->lessons($skilland->id) as $lesson) {
            $this->assertNull($lesson->scoid);
        }
    }

    public function test_provision_creates_nothing_when_the_download_fails(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->fail_download(new \moodle_exception('error_scorm_download_failed', 'mod_skilland'));

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail when the download fails');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_download_failed', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_provision_rejects_a_package_whose_hash_does_not_match(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['packageHash' => 'sha256:' . str_repeat('0', 64)]);

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail on a hash mismatch');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_hash_mismatch', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
    }

    public function test_update_builds_the_new_scorm_before_deleting_the_old_one(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $oldcmid = $this->provision($skilland);
        $oldscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $oldcmid]);
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $sink = $this->redirectEvents();
        $newcmid = $this->update($skilland);
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotSame($oldcmid, $newcmid);
        $order = [];
        foreach ($events as $event) {
            if ($event instanceof \core\event\course_module_created && (int) $event->objectid === $newcmid) {
                $order[] = 'created new';
            }
            if ($event instanceof \core\event\course_module_deleted && (int) $event->objectid === $oldcmid) {
                $order[] = 'deleted old';
            }
        }
        $this->assertSame(['created new', 'deleted old'], $order);

        $this->assertSame([$newcmid], $this->scorm_cmids($course->id));
        $this->assertFalse($DB->record_exists('scorm', ['id' => $oldscormid]));
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertSame($newcmid, (int) $record->scormcmid);
        $this->assertSame('hash-v2', $record->snapshotid);

        $newscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $newcmid]);
        foreach ($this->lessons($skilland->id) as $lesson) {
            $this->assertTrue($DB->record_exists('scorm_scoes', ['id' => $lesson->scoid, 'scorm' => $newscormid]));
        }
    }

    public function test_update_stamps_each_lesson_with_the_api_version(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $DB->set_field('skilland_lesson', 'updatedat', 1, ['skillandid' => $skilland->id]);

        $this->update($skilland);

        $lessons = $this->lessons($skilland->id);
        $this->assertEquals(strtotime('2026-01-01T10:00:00Z'), $lessons['lesson-1']->updatedat);
        $this->assertEquals(strtotime('2026-01-02T10:00:00Z'), $lessons['lesson-2']->updatedat);
    }

    /**
     * Failures of a rebuild that must leave the installed SCORM as it was.
     *
     * @return array
     */
    public static function failed_update_provider(): array {
        return [
            'download fails' => ['download', 'error_scorm_download_failed'],
            'package lacks a mapped SCO' => ['mapping', 'error_scorm_parse_failed'],
            'package info unavailable' => ['info', 'error_scorm_not_available'],
        ];
    }

    /**
     * @dataProvider failed_update_provider
     * @param string $failure Which step fails.
     * @param string $errorcode Expected error code.
     */
    public function test_failed_update_keeps_the_old_scorm_and_its_attempts(string $failure, string $errorcode): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->enrol($course, 'student');
        $skilland = $this->create_activity($course);
        $oldcmid = $this->provision($skilland);
        $before = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $lessonsbefore = $this->lessons($skilland->id);

        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $oldcmid]);
        scorm_insert_track($student->id, $scormid, $lessonsbefore['lesson-1']->scoid, 1,
            'cmi.core.lesson_status', 'incomplete');
        $this->take_debugging();

        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);
        if ($failure === 'download') {
            $this->client->fail_download(new \moodle_exception('error_scorm_download_failed', 'mod_skilland'));
        } else if ($failure === 'mapping') {
            $this->client->merge_response('GetTopicScorm', 'topicScorm', ['mappings' => [
                ['lessonId' => 'lesson-1', 'scoId' => 'sco-gone'],
            ]]);
        } else {
            $this->client->set_response('GetTopicScorm', ['topicScorm' => null]);
        }

        try {
            $this->update($skilland);
            $this->fail('The update must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([$oldcmid], $this->scorm_cmids($course->id));
        $after = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        foreach (['scormcmid', 'snapshotid', 'scomappings', 'scorm_provisioned'] as $field) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        foreach ($this->lessons($skilland->id) as $lessonid => $lesson) {
            $this->assertEquals($lessonsbefore[$lessonid]->scoid, $lesson->scoid);
            $this->assertEquals($lessonsbefore[$lessonid]->updatedat, $lesson->updatedat);
        }
        $this->assertTrue($DB->record_exists('scorm_attempt', ['scormid' => $scormid, 'userid' => $student->id]));
    }
}
