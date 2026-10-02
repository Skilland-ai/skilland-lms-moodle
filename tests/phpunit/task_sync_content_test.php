<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\task\sync_content;

require_once __DIR__ . '/stubs/completionlib.php';

class task_sync_content_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        // Reset configurable stubs and the \core\di seams (api_client, topic_scorm_updater).
        \core\di::reset_container();
        unset($GLOBALS['_test_lock_available'], $GLOBALS['_test_lock_calls']);
        unset($GLOBALS['_test_get_coursemodule_from_id'], $GLOBALS['_test_staff_userids']);
        unset($GLOBALS['_test_get_coursemodule_from_instance']);
        unset($GLOBALS['_test_get_course']);
        \mod_skilland\logger::reset_cache();

        // By default, seed the modules table so the plugin is considered enabled.
        $this->db->seed('modules', [
            (object)['id' => 1, 'name' => 'skilland', 'visible' => 1],
        ]);
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    private function makeTask(): sync_content {
        return new sync_content();
    }

    private function invokePrivate(object $object, string $method, array $args = []) {
        $ref = new \ReflectionMethod($object, $method);
        return $ref->invoke($object, ...$args);
    }

    // ---------------------------------------------------------------
    // get_name()
    // ---------------------------------------------------------------

    public function test_get_name_returns_string(): void {
        $task = $this->makeTask();
        $name = $task->get_name();
        $this->assertIsString($name);
        $this->assertEquals('task_sync_content', $name);
    }

    // ---------------------------------------------------------------
    // execute() — skip when plugin disabled
    // ---------------------------------------------------------------

    public function test_execute_skips_when_plugin_disabled(): void {
        // Override the default setUp seed — mark module as hidden (disabled).
        $this->db->seed('modules', [
            (object)['id' => 1, 'name' => 'skilland', 'visible' => 0],
        ]);

        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];

        $task = $this->makeTask();
        $task->execute();

        $disabled = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'Plugin is disabled'));
        $this->assertNotEmpty($disabled);
    }

    // ---------------------------------------------------------------
    // execute() — skip when config incomplete
    // ---------------------------------------------------------------

    public function test_execute_skips_when_config_incomplete(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => '',
            'orgid' => '',
            'graphql_endpoint' => '',
        ];

        $task = $this->makeTask();
        $task->execute();

        $warns = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'not fully configured'));
        $this->assertNotEmpty($warns);
    }

    public function test_execute_skips_when_no_activities(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];

        // get_records_select will return empty by default.
        $task = $this->makeTask();
        $task->execute();

        $infos = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'No auto-update activities'));
        $this->assertNotEmpty($infos);
    }

    // ---------------------------------------------------------------
    // check_and_update() — cooldown
    // ---------------------------------------------------------------

    public function test_check_and_update_skips_recent_sync(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 60,  // 60 seconds ago (< 300s threshold).
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'hash1',
        ];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('skipped', $result);
    }

    public function test_check_and_update_bypasses_cooldown_when_lastsynced_null(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => null,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'abc123',
        ];

        \fake_api_client::topic_snapshot(['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        // Should reach hash comparison (not be skipped by cooldown).
        $this->assertEquals('current', $result);
    }

    public function test_check_and_update_bypasses_cooldown_when_lastsynced_zero(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => 0,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'abc123',
        ];

        \fake_api_client::topic_snapshot(['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('current', $result);
    }

    // ---------------------------------------------------------------
    // check_and_update() — lock after first access
    // ---------------------------------------------------------------

    public function test_check_and_update_skips_when_locked_and_students_accessed(): void {
        $this->db->seed('scorm', [
            (object)['id' => 50, 'course' => 1],
        ]);
        $this->db->seed('scorm_attempt', [
            (object)['id' => 1, 'scormid' => 50, 'userid' => 7],
        ]);

        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 1,
            'snapshotid' => 'hash1',
        ];

        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('skipped', $result);
    }

    // ---------------------------------------------------------------
    // check_and_update() — hash comparison
    // ---------------------------------------------------------------

    public function test_check_and_update_returns_current_when_hash_matches(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'abc123',
        ];

        \fake_api_client::topic_snapshot(['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('current', $result);

        // Verify lastsynced was updated.
        $setFields = $this->db->get_calls_for('set_field');
        $this->assertNotEmpty($setFields);
        $this->assertEquals('lastsynced', $setFields[0]['field']);
    }

    public function test_check_and_update_skips_when_api_returns_null(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'abc123',
        ];

        \fake_api_client::topic_snapshot(null);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('skipped', $result);
    }

    // ---------------------------------------------------------------
    // check_and_update() — snapshotid edge cases
    // ---------------------------------------------------------------

    /**
     * SKL-649: an activity that already has a SCORM but no stored hash (provisioned before this
     * fix) must have the current remote hash recorded once, without re-provisioning — the missing
     * hash means "unknown", not "changed", so it must never trigger a destructive rebuild.
     */
    public function test_check_and_update_records_hash_without_reprovisioning_when_snapshotid_null(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => null,
        ];

        \fake_api_client::topic_snapshot(['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);
        \fake_topic_scorm_updater::install(200);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        // Migration path: the hash is recorded, but the SCORM (and student progress) is untouched.
        $this->assertEquals('current', $result);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertNotEmpty($updates);
        $updated = $updates[0]['data'];
        $this->assertEquals('newhash', $updated->snapshotid);
    }

    /** Same migration as above, when both the stored and remote hashes happen to be empty. */
    public function test_check_and_update_records_hash_without_reprovisioning_when_both_hashes_empty(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => '',
        ];

        // Remote also returns empty contentHash.
        \fake_api_client::topic_snapshot(['contentHash' => '', 'generatedAt' => '2024-06-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);
        \fake_topic_scorm_updater::install(200);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('current', $result);
    }

    /** A provisioned activity whose stored snapshot is older than Skilland's. */
    private function changedActivity(array $fields = []): \stdClass {
        return (object) ($fields + [
            'id' => 1,
            'name' => 'Topic',
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'oldhash',
            'updateavailable' => null,
        ]);
    }

    private function snapshot(string $hash): void {
        \fake_api_client::topic_snapshot(['contentHash' => $hash, 'generatedAt' => '2024-06-01T00:00:00Z',
            'hasPackage' => true, 'isStale' => false]);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];
    }

    /** SKL-650: changed content is recorded and announced, never imported by the cron. */
    public function test_check_and_update_notifies_and_never_imports_when_hash_differs(): void {
        $this->snapshot('newhash');
        $updater = \fake_topic_scorm_updater::install(200);
        $notifier = \fake_update_notifier::install(2);

        $result = $this->invokePrivate($this->makeTask(), 'check_and_update', [$this->changedActivity()]);

        $this->assertEquals('notified', $result);
        $this->assertSame([], $updater->calls, 'The cron never rebuilds the SCORM');
        $this->assertCount(1, $notifier->calls);
        $this->assertSame(1, (int) $notifier->calls[0][0]->id);
        $this->assertSame(100, (int) $notifier->calls[0][1]->id);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertCount(1, $updates);
        $this->assertSame('newhash', $updates[0]['data']->updateavailable);
        $this->assertNotEmpty($updates[0]['data']->lastsynced);
        $this->assertObjectNotHasProperty('snapshotid', $updates[0]['data'], 'The snapshot only moves when a teacher applies it');
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? [], 'Nothing is downloaded');
        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? [], 'No provisioning lock is taken');
    }

    public function test_check_and_update_does_not_notify_twice_for_the_same_hash(): void {
        $this->snapshot('newhash');
        $notifier = \fake_update_notifier::install();

        $result = $this->invokePrivate($this->makeTask(), 'check_and_update',
            [$this->changedActivity(['updateavailable' => 'newhash'])]);

        $this->assertEquals('pending', $result);
        $this->assertSame([], $notifier->calls);
        $this->assertSame([], $this->db->get_calls_for('update_record'));
        $lastsynced = array_filter($this->db->get_calls_for('set_field'), fn($c) => $c['field'] === 'lastsynced');
        $this->assertCount(1, $lastsynced);
    }

    public function test_check_and_update_notifies_again_for_a_newer_hash(): void {
        $this->snapshot('newerhash');
        $notifier = \fake_update_notifier::install();

        $result = $this->invokePrivate($this->makeTask(), 'check_and_update',
            [$this->changedActivity(['updateavailable' => 'newhash'])]);

        $this->assertEquals('notified', $result);
        $this->assertCount(1, $notifier->calls);
        $this->assertSame('newerhash', $this->db->get_calls_for('update_record')[0]['data']->updateavailable);
    }

    public function test_an_activity_without_a_scorm_is_announced_not_provisioned(): void {
        $this->snapshot('newhash');
        $updater = \fake_topic_scorm_updater::install(200);
        $notifier = \fake_update_notifier::install();

        $result = $this->invokePrivate($this->makeTask(), 'check_and_update',
            [$this->changedActivity(['scormcmid' => null, 'snapshotid' => null])]);

        $this->assertEquals('notified', $result);
        $this->assertSame([], $updater->calls);
        $this->assertCount(1, $notifier->calls);
    }

    public function test_execute_counts_notified_activities(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];
        $this->db->seed('skilland', [
            (object)['id' => 1, 'autoupdate' => 1, 'scormcmid' => 100, 'skilland_topicid' => 'topic1',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash'],
        ]);
        $this->snapshot('newhash');
        \fake_update_notifier::install();

        $this->makeTask()->execute();

        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertNotEmpty($complete);
        $this->assertStringContainsString('1 notified, 0 skipped, 0 errors', $complete[0]['message']);
        $this->assertSame('newhash', $this->db->get_record('skilland', ['id' => 1])->updateavailable);
    }

    // ---------------------------------------------------------------
    // skilland_learner_attempt_userids() — all branches (SKL-677)
    // ---------------------------------------------------------------

    private function learner_ids(object $activity, bool $stopatfirst = false): array {
        return skilland_learner_attempt_userids($activity, $stopatfirst);
    }

    public function test_learner_attempts_are_empty_without_scormcmid(): void {
        $this->assertSame([], $this->learner_ids((object)['id' => 1, 'scormcmid' => null]));
    }

    public function test_learner_attempts_are_empty_when_cm_not_found(): void {
        $GLOBALS['_test_get_coursemodule_from_id'] = false;

        $this->assertSame([], $this->learner_ids((object)['id' => 1, 'scormcmid' => 999]));
    }

    public function test_learner_attempts_fall_back_to_scorm_scoes_track(): void {
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('scorm_scoes_track', [(object)['id' => 1, 'scormid' => 50, 'userid' => 7]]);
        // scorm_attempt table doesn't exist → falls back to scorm_scoes_track.
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);

        $this->assertSame([7], $this->learner_ids((object)['id' => 1, 'scormcmid' => 100]));
    }

    public function test_learner_attempts_are_empty_when_no_attempts(): void {
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);

        $this->assertSame([], $this->learner_ids((object)['id' => 1, 'scormcmid' => 100]));
    }

    public function test_learner_attempts_leave_out_staff(): void {
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('scorm_attempt', [
            (object)['id' => 1, 'scormid' => 50, 'userid' => 3],
            (object)['id' => 2, 'scormid' => 50, 'userid' => 7],
            (object)['id' => 3, 'scormid' => 50, 'userid' => 8],
            (object)['id' => 4, 'scormid' => 51, 'userid' => 9],
        ]);
        $GLOBALS['_test_staff_userids'] = [3];
        $activity = (object)['id' => 1, 'scormcmid' => 100];

        $this->assertSame([7, 8], $this->learner_ids($activity));
        $this->assertSame([7], $this->learner_ids($activity, true));
    }

    public function test_a_staff_only_attempt_does_not_lock_the_activity(): void {
        $this->db->seed('scorm_attempt', [(object)['id' => 1, 'scormid' => 50, 'userid' => 3]]);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $GLOBALS['_test_staff_userids'] = [3];

        $this->assertSame([], $this->learner_ids((object)['id' => 1, 'scormcmid' => 100], true));
    }

    // ---------------------------------------------------------------
    // execute() — exception in one activity doesn't stop others
    // ---------------------------------------------------------------

    public function test_execute_continues_after_exception(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];

        // Seed two activities — the task iterates both even if one fails.
        $this->db->seed('skilland', [
            (object)['id' => 1, 'autoupdate' => 1, 'scormcmid' => 100, 'skilland_topicid' => 'topic1'],
            (object)['id' => 2, 'autoupdate' => 1, 'scormcmid' => 200, 'skilland_topicid' => 'topic2'],
        ]);

        $task = $this->makeTask();
        // Even if check_and_update throws for one, the task logs error and continues.
        // With our stubs, both will fail (no snapshot stub) but the task itself won't throw.
        $task->execute();

        // Should see "Sync complete" log with error count.
        $completeLog = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'Sync complete'));
        $this->assertNotEmpty($completeLog);
    }

    // ---------------------------------------------------------------
    // Progress backfill (SKL-668)
    // ---------------------------------------------------------------

    private function seedTrackedActivity(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->seed('skilland', [
            (object)['id' => 1, 'course' => 3, 'autoupdate' => 1, 'scormcmid' => 100, 'skilland_topicid' => 'topic1',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash', 'grade' => 100],
        ]);
        $this->db->seed('skilland_lesson', [
            (object)['id' => 5, 'skillandid' => 1, 'scoid' => 11, 'visible' => 1],
        ]);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 9, 'course' => 3];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 90, 'instance' => 1, 'course' => 3, 'section' => 1];
        unset($GLOBALS['_test_grade_updates'], $GLOBALS['_test_completion_updates']);
    }

    public function test_backfill_runs_even_when_the_api_call_fails(): void {
        $this->seedTrackedActivity();
        $this->db->set_records_sql_handler(fn() => [
            1 => (object)['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
            2 => (object)['id' => 2, 'userid' => 51, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'incomplete'],
        ]);
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $rows = $this->db->get_records('skilland_progress');
        $this->assertCount(2, $rows);
        $this->assertSame(['completed', 'incomplete'], array_values(array_map(fn($r) => $r->status, $rows)));
        $this->assertCount(2, $GLOBALS['_test_completion_updates'] ?? []);
        $this->assertCount(2, $GLOBALS['_test_grade_updates'] ?? []);
        $skipped = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'Could not get hash info'));
        $this->assertNotEmpty($skipped, 'The API check still ran and failed');
    }

    public function test_backfill_reads_progress_rows_once_per_activity_whatever_the_learner_count(): void {
        $this->seedTrackedActivity();
        $this->db->seed('skilland_progress', [
            (object)['id' => 1, 'skillandid' => 1, 'lessonid' => 5, 'userid' => 50, 'status' => 'incomplete',
                'score' => null, 'timemodified' => 1],
        ]);
        $tracks = [];
        foreach (range(50, 59) as $i => $userid) {
            $tracks[$i + 1] = (object)['id' => $i + 1, 'userid' => $userid, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'];
        }
        $this->db->set_records_sql_handler(fn() => $tracks);
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $progressreads = array_filter($this->db->get_calls_for('get_records'),
            fn($c) => $c['table'] === 'skilland_progress' && !isset($c['conditions']['userid']));
        // The merge's own per-learner read (grading reads per learner separately, after a change).
        $peruserreads = array_filter($this->db->get_calls_for('get_records'),
            fn($c) => $c['table'] === 'skilland_progress' && $c['fields'] === 'lessonid, id, status, score');
        $this->assertCount(1, $progressreads);
        $this->assertCount(0, $peruserreads, 'No per-learner read of the store in the merge');
        $rows = $this->db->get_records('skilland_progress');
        $this->assertCount(10, $rows);
        $this->assertSame(['completed'], array_values(array_unique(array_map(fn($r) => $r->status, $rows))));
        $this->assertCount(1, array_filter($rows, fn($r) => $r->userid == 50), 'Existing row merged, not duplicated');
    }

    public function test_backfill_recomputes_only_learners_whose_progress_changed(): void {
        $this->seedTrackedActivity();
        $this->db->seed('skilland_progress', [
            (object)['id' => 1, 'skillandid' => 1, 'lessonid' => 5, 'userid' => 50, 'status' => 'completed',
                'score' => null, 'timemodified' => 1],
        ]);
        $this->db->set_records_sql_handler(fn() => [
            1 => (object)['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
        ]);
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $this->assertEmpty($GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_grade_updates'] ?? []);
    }

    public function test_backfill_runs_when_config_is_incomplete(): void {
        $this->seedTrackedActivity();
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['apikey' => '', 'orgid' => '', 'graphql_endpoint' => ''];
        $this->db->set_records_sql_handler(fn() => [
            1 => (object)['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'passed'],
        ]);

        $this->makeTask()->execute();

        $this->assertCount(1, $this->db->get_records('skilland_progress'));
    }

    public function test_update_notice_path_does_not_recompute(): void {
        $this->seedTrackedActivity();
        \fake_api_client::topic_snapshot(['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);
        \fake_update_notifier::install();

        $this->makeTask()->execute();

        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertStringContainsString('1 notified', $complete[0]['message']);
        $this->assertEmpty($GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_grade_updates'] ?? []);
        $this->assertEmpty($this->db->get_records('skilland_progress'));
    }

    public function test_backfill_failure_on_one_activity_does_not_stop_the_sync(): void {
        $this->seedTrackedActivity();
        $this->db->set_records_sql_handler(function () {
            throw new \RuntimeException('boom');
        });
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $complete = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'Sync complete'));
        $this->assertNotEmpty($complete);
    }

    // ---------------------------------------------------------------
    // execute() — a \Throwable from one activity never aborts the run (SKL-659)
    // ---------------------------------------------------------------

    public function test_execute_isolates_a_type_error_to_its_activity(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];
        $this->db->seed('skilland', [
            (object)['id' => 1, 'autoupdate' => 1, 'scormcmid' => 100, 'skilland_topicid' => 'topic1',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash'],
            (object)['id' => 2, 'autoupdate' => 1, 'scormcmid' => 200, 'skilland_topicid' => 'topic2',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash'],
            (object)['id' => 3, 'autoupdate' => 1, 'scormcmid' => 300, 'skilland_topicid' => 'topic3',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash'],
        ]);
        \fake_api_client::topic_snapshot(['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z', 'hasPackage' => true, 'isStale' => false]);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];
        $processed = [];
        \fake_update_notifier::install(function ($skilland) use (&$processed) {
            if ((int) $skilland->id === 2) {
                throw new \TypeError('Cannot access offset of type array');
            }
            $processed[] = (int) $skilland->id;
            return 1;
        });

        $this->makeTask()->execute();

        $this->assertSame([1, 3], $processed);
        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertNotEmpty($complete);
        $this->assertStringContainsString('2 notified, 0 skipped, 1 errors', $complete[0]['message']);
        $errors = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Error checking activity 2')));
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('TypeError', $errors[0]['message']);
    }

    public function test_a_type_error_in_the_backfill_still_lets_the_api_check_run(): void {
        $this->db = new class extends \FakeDatabase {
            public function get_records(string $table, array $conditions = [], string $sort = '', string $fields = '*') {
                if ($table === 'skilland_progress') {
                    throw new \TypeError('backfill blew up');
                }
                return parent::get_records($table, $conditions, $sort, $fields);
            }
        };
        $GLOBALS['DB'] = $this->db;
        $this->db->seed('modules', [(object)['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $this->seedTrackedActivity();
        $this->db->set_records_sql_handler(fn() => [
            1 => (object)['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
        ]);
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('Progress backfill failed for activity 1: TypeError: backfill blew up', $messages);
        $this->assertStringContainsString('Could not get hash info', $messages, 'The API check still ran');
    }

    public function test_a_failing_backfill_query_still_lets_the_api_check_run(): void {
        $this->seedTrackedActivity();
        $db = new class extends \FakeDatabase {
            public function get_records_select(string $table, string $select, ?array $params = null, string $sort = '',
                    string $fields = '*') {
                if ($select === 'scormcmid IS NOT NULL') {
                    throw new \Error('backfill query failed');
                }
                return parent::get_records_select($table, $select, $params, $sort, $fields);
            }
        };
        $db->seed('modules', [(object)['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $db->seed('skilland', $this->db->get_records('skilland'));
        $GLOBALS['DB'] = $db;
        \fake_api_client::topic_snapshot(null);

        $this->makeTask()->execute();

        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('Progress backfill failed: Error: backfill query failed', $messages);
        $this->assertStringContainsString('Could not get hash info', $messages);
        $this->assertStringContainsString('Sync complete', $messages);
    }
}
