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
        // Reset configurable stubs.
        unset($GLOBALS['_test_topic_snapshot']);
        unset($GLOBALS['_test_update_topic_scorm']);
        unset($GLOBALS['_test_lock_available'], $GLOBALS['_test_lock_calls']);
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        unset($GLOBALS['_test_get_coursemodule_from_instance']);
        unset($GLOBALS['_test_get_course']);
        \mod_skilland\logger::reset_cache();

        // By default, seed the modules table so the plugin is considered enabled.
        $this->db->seed('modules', [
            (object)['id' => 1, 'name' => 'skilland', 'visible' => 1],
        ]);
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

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z'];

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

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z'];

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
            (object)['id' => 1, 'scormid' => 50],
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

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'abc123', 'generatedAt' => '2024-01-01T00:00:00Z'];

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

        $GLOBALS['_test_topic_snapshot'] = null;

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('skipped', $result);
    }

    // ---------------------------------------------------------------
    // check_and_update() — snapshotid edge cases
    // ---------------------------------------------------------------

    public function test_check_and_update_triggers_update_when_snapshotid_null(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => null,
        ];

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_update_topic_scorm'] = 200;
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        // null snapshotid → !empty('') is false → goes to update path.
        $this->assertEquals('updated', $result);

        // Verify snapshot fields updated.
        $updates = $this->db->get_calls_for('update_record');
        $this->assertNotEmpty($updates);
        $updated = $updates[0]['data'];
        $this->assertEquals('newhash', $updated->snapshotid);
    }

    public function test_check_and_update_triggers_update_when_both_hashes_empty(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => '',
        ];

        // Remote also returns empty contentHash.
        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => '', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_update_topic_scorm'] = 200;
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        // Both empty → !empty('') is false → update path (treats as stale).
        $this->assertEquals('updated', $result);
    }

    public function test_check_and_update_returns_updated_when_hash_differs(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'oldhash',
        ];

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_update_topic_scorm'] = 200;
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('updated', $result);

        // Verify snapshot was saved.
        $updates = $this->db->get_calls_for('update_record');
        $lastUpdate = end($updates);
        $this->assertEquals('newhash', $lastUpdate['data']->snapshotid);
    }

    public function test_check_and_update_skips_when_provisioning_lock_is_busy(): void {
        $activity = (object)[
            'id' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'oldhash',
        ];

        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_lock_available'] = false;
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'check_and_update', [$activity]);

        $this->assertEquals('skipped', $result);
        $this->assertSame('mod_skilland/provision_1', $GLOBALS['_test_lock_calls'][0]['key']);
        $snapshotwrites = array_filter($this->db->get_calls_for('update_record'),
            fn($c) => isset($c['data']->snapshotid));
        $this->assertEmpty($snapshotwrites, 'A skipped activity keeps its old snapshot so the next run retries');
        unset($GLOBALS['_test_lock_available'], $GLOBALS['_test_lock_calls']);
    }

    public function test_check_and_update_rethrows_other_provisioning_failures(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://api.skilland.ai/graphql',
        ];
        $activity = (object)[
            'id' => 1,
            'name' => 'Topic',
            'skilland_topicid' => 'topic1',
            'lastsynced' => time() - 600,
            'scormcmid' => null,
            'scorm_provisioned' => null,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'oldhash',
        ];
        $this->db->seed('skilland', [clone $activity]);
        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];
        $ok = fn(array $data) => ['body' => json_encode(['data' => $data]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
        $GLOBALS['_test_curl_responses'] = [
            $ok(['topic' => ['id' => 'topic1', 'name' => 'T', 'lessons' => []]]),
            $ok(['topicScorm' => ['packageUrl' => '', 'mappings' => []]]),
        ];

        $task = $this->makeTask();
        try {
            $this->invokePrivate($task, 'check_and_update', [$activity]);
            $this->fail('A non-lock provisioning failure must propagate');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_not_available', $e->errorcode);
        } finally {
            unset($GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_requests'],
                $GLOBALS['_test_curl_last']);
        }

        $releases = array_filter($GLOBALS['_test_lock_calls'], fn($c) => $c['action'] === 'release');
        $this->assertCount(1, $releases, 'The lock is released on failure');
        $snapshotwrites = array_filter($this->db->get_calls_for('update_record'),
            fn($c) => isset($c['data']->snapshotid));
        $this->assertEmpty($snapshotwrites);
        unset($GLOBALS['_test_lock_calls']);
    }

    public function test_execute_counts_a_busy_lock_as_skipped_not_error(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ];
        $this->db->seed('skilland', [
            (object)['id' => 1, 'autoupdate' => 1, 'scormcmid' => 100, 'skilland_topicid' => 'topic1',
                'lastsynced' => 0, 'lockafterfirstaccess' => 0, 'snapshotid' => 'oldhash'],
        ]);
        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_lock_available'] = false;
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object)['id' => 100, 'instance' => 1, 'course' => 1, 'section' => 1];

        $this->makeTask()->execute();

        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertNotEmpty($complete);
        $this->assertStringContainsString('1 skipped, 0 errors', $complete[0]['message']);
        unset($GLOBALS['_test_lock_available'], $GLOBALS['_test_lock_calls']);
    }

    // ---------------------------------------------------------------
    // has_student_access() — all branches
    // ---------------------------------------------------------------

    public function test_has_student_access_returns_false_without_scormcmid(): void {
        $activity = (object)[
            'id' => 1,
            'scormcmid' => null,
        ];

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'has_student_access', [$activity]);

        $this->assertFalse($result);
    }

    public function test_has_student_access_returns_false_when_cm_not_found(): void {
        $activity = (object)[
            'id' => 1,
            'scormcmid' => 999,
        ];

        $GLOBALS['_test_get_coursemodule_from_id'] = false;

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'has_student_access', [$activity]);

        $this->assertFalse($result);
    }

    public function test_has_student_access_returns_false_when_scorm_record_not_found(): void {
        $activity = (object)[
            'id' => 1,
            'scormcmid' => 100,
        ];

        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        // Don't seed scorm table — get_record will return false.

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'has_student_access', [$activity]);

        $this->assertFalse($result);
    }

    public function test_has_student_access_falls_back_to_scorm_scoes_track(): void {
        $activity = (object)[
            'id' => 1,
            'scormcmid' => 100,
        ];

        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('scorm', [(object)['id' => 50, 'course' => 1]]);
        $this->db->seed('scorm_scoes_track', [(object)['id' => 1, 'scormid' => 50]]);

        // scorm_attempt table doesn't exist → falls back to scorm_scoes_track.
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'has_student_access', [$activity]);

        $this->assertTrue($result);
    }

    public function test_has_student_access_returns_false_when_no_attempts(): void {
        $activity = (object)[
            'id' => 1,
            'scormcmid' => 100,
        ];

        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('scorm', [(object)['id' => 50, 'course' => 1]]);
        // scorm_attempt table exists but has no records for this scorm.
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);

        $task = $this->makeTask();
        $result = $this->invokePrivate($task, 'has_student_access', [$activity]);

        $this->assertFalse($result);
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
        $GLOBALS['_test_topic_snapshot'] = null;

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
        $GLOBALS['_test_topic_snapshot'] = null;

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
        $GLOBALS['_test_topic_snapshot'] = null;

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

    public function test_reprovision_path_does_not_recompute(): void {
        $this->seedTrackedActivity();
        $GLOBALS['_test_topic_snapshot'] = ['contentHash' => 'newhash', 'generatedAt' => '2024-06-01T00:00:00Z'];
        $GLOBALS['_test_update_topic_scorm'] = 200;

        $this->makeTask()->execute();

        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertStringContainsString('1 updated', $complete[0]['message']);
        $this->assertEmpty($GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_grade_updates'] ?? []);
        $this->assertEmpty($this->db->get_records('skilland_progress'));
    }

    public function test_backfill_failure_on_one_activity_does_not_stop_the_sync(): void {
        $this->seedTrackedActivity();
        $this->db->set_records_sql_handler(function () {
            throw new \RuntimeException('boom');
        });
        $GLOBALS['_test_topic_snapshot'] = null;

        $this->makeTask()->execute();

        $complete = array_filter($GLOBALS['_test_debug_messages'], fn($m) => str_contains($m['message'], 'Sync complete'));
        $this->assertNotEmpty($complete);
    }
}
