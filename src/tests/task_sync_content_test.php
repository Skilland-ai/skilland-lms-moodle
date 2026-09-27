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

use mod_skilland\task\sync_content;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * The scheduled content sync, run against real SCORM modules through the fixture API.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\task\sync_content
 */
final class task_sync_content_test extends skilland_testcase {

    /**
     * A provisioned activity whose API log is cleared, so assertions only see the task's calls.
     *
     * @param array $record Extra activity fields.
     * @return array [course, skilland record, SCORM cmid]
     */
    private function provisioned_activity(array $record = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, $record + ['autoupdate' => 1]);
        $cmid = $this->provision($skilland);
        $skilland = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->client->calls = [];
        $this->client->downloads = [];
        return [$course, $skilland, $cmid];
    }

    /**
     * Run the task and drop the log lines it emits through debugging().
     *
     * @return string[] The log lines.
     */
    private function run_task(): array {
        (new sync_content())->execute();
        return $this->take_debugging();
    }

    public function test_autoupdate_rebuilds_an_activity_whose_snapshot_is_stale(): void {
        global $DB;

        [$course, $skilland, $oldcmid] = $this->provisioned_activity();
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNotEquals($oldcmid, $record->scormcmid);
        $this->assertSame([(int) $record->scormcmid], $this->scorm_cmids($course->id));
        $this->assertSame('hash-v2', $record->snapshotid);
        $this->assertNotEmpty($record->lastsynced);
        $this->assertCount(1, $this->client->downloads);
        // The hash read by the task is threaded through to the build, never queried twice.
        $this->assertSame(1, $this->client->count_calls('TopicScormHash'));
    }

    public function test_autoupdate_setting_is_checked(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity(['autoupdate' => 0]);
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->operations());
        $this->assertSame([], $this->client->downloads);
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
    }

    public function test_an_unchanged_snapshot_is_not_rebuilt(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity();

        $this->run_task();

        $this->assertSame(['TopicScormHash'], $this->client->operations());
        $this->assertSame([], $this->client->downloads);
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertNotEmpty($record->lastsynced);
        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
    }

    public function test_an_api_failure_changes_nothing(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity();
        $this->client->set_response('TopicScormHash', new \moodle_exception('error_api_unavailable', 'mod_skilland'));

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
        $this->assertEmpty($record->lastsynced);
        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
    }

    public function test_a_failed_rebuild_keeps_the_old_scorm_and_retries_later(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity();
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);
        $this->client->fail_download(new \moodle_exception('error_scorm_download_failed', 'mod_skilland'));

        $log = $this->run_task();

        $this->assertNotEmpty(preg_grep('/1 errors/', $log));
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
        // No lastsynced: the cooldown must not delay the next attempt.
        $this->assertEmpty($record->lastsynced);
        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
    }

    /**
     * Snapshot states the task must never build from.
     *
     * @return array
     */
    public static function unbuildable_snapshot_provider(): array {
        return [
            'no package yet' => [['contentHash' => 'hash-v2', 'hasPackage' => false]],
            'package being regenerated' => [['contentHash' => 'hash-v2', 'isStale' => true]],
        ];
    }

    /**
     * @dataProvider unbuildable_snapshot_provider
     * @param array $snapshot TopicScormHash fields.
     */
    public function test_a_topic_without_a_ready_package_is_skipped(array $snapshot): void {
        global $DB;

        [, $skilland, $cmid] = $this->provisioned_activity();
        $this->client->merge_response('TopicScormHash', 'topicScormHash', $snapshot);

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $this->assertEquals($cmid, $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_an_activity_synced_within_the_cooldown_is_skipped(): void {
        global $DB;

        [, $skilland] = $this->provisioned_activity();
        $DB->set_field('skilland', 'lastsynced', time() - 60, ['id' => $skilland->id]);
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->operations());
    }

    public function test_lock_after_first_access_protects_student_attempts(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        [$course, $skilland, $cmid] = $this->provisioned_activity(['lockafterfirstaccess' => 1]);
        $student = $this->enrol($course, 'student');
        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $scoid = (int) $DB->get_field('skilland_lesson', 'scoid',
            ['skillandid' => $skilland->id, 'skilland_lessonid' => 'lesson-1']);
        scorm_insert_track($student->id, $scormid, $scoid, 1, 'cmi.core.lesson_status', 'incomplete');
        $this->take_debugging();
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $this->assertEquals($cmid, $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_an_unconfigured_plugin_never_calls_the_api(): void {
        [, , $cmid] = $this->provisioned_activity();
        set_config('apikey', '', 'mod_skilland');
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->operations());
    }

    public function test_an_activity_that_lost_its_scorm_is_rebuilt(): void {
        global $DB;
        // The course_module_deleted observer is external: it only runs outside the test transaction.
        $this->preventResetByRollback();

        [$course, $skilland, $cmid] = $this->provisioned_activity();
        course_delete_module($cmid);
        $this->take_debugging();
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));

        $this->run_task();

        $newcmid = (int) $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]);
        $this->assertGreaterThan(0, $newcmid);
        $this->assertSame([$newcmid], $this->scorm_cmids($course->id));
    }
}
