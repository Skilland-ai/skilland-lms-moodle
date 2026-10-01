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
 * Since SKL-650 it never imports a package: it records the update and notifies the teachers.
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

    /**
     * Run the task and return the messages it sent.
     *
     * @return \stdClass[]
     */
    private function run_task_collecting_messages(): array {
        $sink = $this->redirectMessages();
        $this->run_task();
        $messages = $sink->get_messages();
        $sink->close();
        return $messages;
    }

    public function test_autoupdate_announces_a_stale_snapshot_without_importing_it(): void {
        global $DB;

        [$course, $skilland, $oldcmid] = $this->provisioned_activity();
        $teacher = $this->enrol($course, 'editingteacher');
        $this->enrol($course, 'student');
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $messages = $this->run_task_collecting_messages();

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($oldcmid, $record->scormcmid);
        $this->assertSame([$oldcmid], $this->scorm_cmids($course->id));
        $this->assertSame('hash-v1', $record->snapshotid);
        $this->assertSame('hash-v2', $record->updateavailable);
        $this->assertNotEmpty($record->lastsynced);
        $this->assertSame([], $this->client->downloads, 'The cron downloads nothing');
        $this->assertSame(0, $this->client->count_calls('GET topics/{id}/scorm'));

        // Only the course's teacher is told; the student is not.
        $this->assertCount(1, $messages);
        $this->assertSame('mod_skilland', $messages[0]->component);
        $this->assertSame('contentupdate', $messages[0]->eventtype);
        $this->assertEquals($teacher->id, $messages[0]->useridto);
        $cm = get_coursemodule_from_instance('skilland', $skilland->id, $course->id, false, MUST_EXIST);
        $this->assertStringContainsString('/mod/skilland/view.php?id=' . $cm->id, $messages[0]->contexturl);
    }

    public function test_the_same_update_is_announced_once(): void {
        global $DB;

        [$course, $skilland] = $this->provisioned_activity();
        $this->enrol($course, 'editingteacher');
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $first = $this->run_task_collecting_messages();
        $DB->set_field('skilland', 'lastsynced', 0, ['id' => $skilland->id]);
        $second = $this->run_task_collecting_messages();

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);

        // A newer version is announced again.
        $DB->set_field('skilland', 'lastsynced', 0, ['id' => $skilland->id]);
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v3']);
        $this->assertCount(1, $this->run_task_collecting_messages());
        $this->assertSame('hash-v3', $DB->get_field('skilland', 'updateavailable', ['id' => $skilland->id]));
    }

    public function test_applying_the_announced_update_imports_it_and_clears_the_notice(): void {
        global $DB;

        [$course, $skilland, $oldcmid] = $this->provisioned_activity();
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);
        $this->run_task();

        $newcmid = $this->update($DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST));

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNotEquals($oldcmid, $newcmid);
        $this->assertSame([$newcmid], $this->scorm_cmids($course->id));
        $this->assertSame('hash-v2', $record->snapshotid);
        $this->assertNull($record->updateavailable);
    }

    public function test_autoupdate_setting_is_checked(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity(['autoupdate' => 0]);
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

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

        $this->assertSame(['GET topics/{id}/scorm-hash'], $this->client->operations());
        $this->assertSame([], $this->client->downloads);
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertNotEmpty($record->lastsynced);
        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
    }

    public function test_an_api_failure_changes_nothing(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity();
        $this->client->set_response('GET topics/{id}/scorm-hash', new \moodle_exception('error_api_unavailable', 'mod_skilland'));

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertEquals($cmid, $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
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
     * A topic without a ready SCORM package is skipped by the sync task.
     *
     * @dataProvider unbuildable_snapshot_provider
     * @param array $snapshot scorm-hash fields.
     */
    public function test_a_topic_without_a_ready_package_is_skipped(array $snapshot): void {
        global $DB;

        [, $skilland, $cmid] = $this->provisioned_activity();
        $this->client->merge_response('GET topics/{id}/scorm-hash', $snapshot);

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $this->assertEquals($cmid, $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_an_activity_synced_within_the_cooldown_is_skipped(): void {
        global $DB;

        [, $skilland] = $this->provisioned_activity();
        $DB->set_field('skilland', 'lastsynced', time() - 60, ['id' => $skilland->id]);
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->operations());
    }

    public function test_lock_after_first_access_protects_student_attempts(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        [$course, $skilland, $cmid] = $this->provisioned_activity(['lockafterfirstaccess' => 1]);
        $student = $this->enrol($course, 'student');
        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $scoid = (int) $DB->get_field(
            'skilland_lesson',
            'scoid',
            ['skillandid' => $skilland->id, 'skilland_lessonid' => 'lesson-1']
        );
        scorm_insert_track($student->id, $scormid, $scoid, 1, 'cmi.core.lesson_status', 'incomplete');
        $this->take_debugging();
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->downloads);
        $this->assertEquals($cmid, $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    /**
     * Record a SCORM attempt of a user on the activity's first lesson.
     *
     * @param int $cmid The SCORM course module id.
     * @param \stdClass $skilland The activity.
     * @param \stdClass $user The user who opens the lesson.
     */
    private function track_attempt(int $cmid, \stdClass $skilland, \stdClass $user): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        $scoid = (int) $DB->get_field(
            'skilland_lesson',
            'scoid',
            ['skillandid' => $skilland->id, 'skilland_lessonid' => 'lesson-1']
        );
        scorm_insert_track($user->id, $scormid, $scoid, 1, 'cmi.core.lesson_status', 'incomplete');
    }

    /**
     * Staff roles whose attempts must never lock auto-update.
     *
     * @return array[]
     */
    public static function staff_role_provider(): array {
        return [
            'editing teacher' => ['editingteacher'],
            'manager' => ['manager'],
        ];
    }

    /**
     * An attempt by staff alone leaves the activity unlocked.
     *
     * @dataProvider staff_role_provider
     * @param string $role The staff role the user is enrolled with.
     */
    public function test_lock_after_first_access_ignores_staff_attempts(string $role): void {
        [$course, $skilland, $cmid] = $this->provisioned_activity(['lockafterfirstaccess' => 1]);
        $staff = $this->enrol($course, $role);
        $this->track_attempt($cmid, $skilland, $staff);
        $this->take_debugging();
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame(1, $this->client->count_calls('GET topics/{id}/scorm-hash'));
    }

    public function test_lock_after_first_access_ignores_a_site_admin_attempt(): void {
        [, $skilland, $cmid] = $this->provisioned_activity(['lockafterfirstaccess' => 1]);
        $this->track_attempt($cmid, $skilland, get_admin());
        $this->take_debugging();
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame(1, $this->client->count_calls('GET topics/{id}/scorm-hash'));
    }

    public function test_lock_after_first_access_holds_when_a_teacher_and_a_student_attempted(): void {
        global $DB;

        [$course, $skilland, $cmid] = $this->provisioned_activity(['lockafterfirstaccess' => 1]);
        $this->track_attempt($cmid, $skilland, $this->enrol($course, 'editingteacher'));
        $this->track_attempt($cmid, $skilland, $this->enrol($course, 'student'));
        $this->take_debugging();
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame(0, $this->client->count_calls('GET topics/{id}/scorm-hash'));
        $this->assertEquals($cmid, $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_learner_attempts_are_counted_without_staff(): void {
        [$course, $skilland, $cmid] = $this->provisioned_activity();
        $this->track_attempt($cmid, $skilland, $this->enrol($course, 'editingteacher'));
        $this->track_attempt($cmid, $skilland, $this->enrol($course, 'student'));
        $this->track_attempt($cmid, $skilland, $this->enrol($course, 'student'));
        $this->take_debugging();

        $this->assertSame(2, skilland_count_topic_student_attempts($skilland));
        $this->assertCount(1, skilland_learner_attempt_userids($skilland, true));
    }

    public function test_an_unconfigured_plugin_never_calls_the_api(): void {
        [, , $cmid] = $this->provisioned_activity();
        set_config('apikey', '', 'mod_skilland');
        $this->client->merge_response('GET topics/{id}/scorm-hash', ['contentHash' => 'hash-v2']);

        $this->run_task();

        $this->assertSame([], $this->client->operations());
    }

    public function test_an_activity_that_lost_its_scorm_is_announced_not_rebuilt(): void {
        global $DB;
        // The course_module_deleted observer is external: it only runs outside the test transaction.
        $this->preventResetByRollback();

        [$course, $skilland, $cmid] = $this->provisioned_activity();
        $this->enrol($course, 'editingteacher');
        course_delete_module($cmid);
        $this->take_debugging();
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));

        $messages = $this->run_task_collecting_messages();

        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
        $this->assertSame([], $this->scorm_cmids($course->id));
        $this->assertSame([], $this->client->downloads);
        $this->assertCount(1, $messages);
    }
}
