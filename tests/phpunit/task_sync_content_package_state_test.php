<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\task\sync_content;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * The sync cron picks up activities left without a SCORM, and never builds from a topic whose
 * package is missing or stale (SKL-654).
 */
class task_sync_content_package_state_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    /** @var array Activities skilland_update_topic_scorm() was called for. */
    private $updated = [];

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object) [
            'apikey' => 'key',
            'orgid' => 'org',
            'graphql_endpoint' => 'https://localhost/graphql',
        ]];
        unset($GLOBALS['_test_lock_available'], $GLOBALS['_test_lock_calls']);
        \mod_skilland\logger::reset_cache();
        $this->db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 1, 'course' => 1,
            'section' => 1];
        $this->updated = [];
        \core\di::reset_container();
        \fake_topic_scorm_updater::install(function ($skilland) {
            $this->updated[] = (int) $skilland->id;
            return 700 + (int) $skilland->id;
        });
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        unset($GLOBALS['_test_get_coursemodule_from_instance'], $GLOBALS['_test_lock_calls']);
        parent::tearDown();
    }

    private function activity(array $overrides = []): \stdClass {
        return (object) array_merge([
            'id' => 1,
            'autoupdate' => 1,
            'skilland_topicid' => 'topic1',
            'lastsynced' => 0,
            'scormcmid' => 100,
            'lockafterfirstaccess' => 0,
            'snapshotid' => 'oldhash',
        ], $overrides);
    }

    private function snapshot(array $overrides = []): array {
        return array_merge(['contentHash' => 'newhash', 'generatedAt' => '2026-03-01T00:00:00Z',
            'hasPackage' => true, 'isStale' => false], $overrides);
    }

    private function check(\stdClass $activity): string {
        $task = new sync_content();
        $method = new \ReflectionMethod($task, 'check_and_update');
        return $method->invoke($task, $activity);
    }

    private function assert_nothing_written(): void {
        $this->assertSame([], $this->updated, 'The SCORM is not rebuilt');
        $this->assertEmpty($this->db->get_calls_for('update_record'));
        $this->assertEmpty($this->db->get_calls_for('set_field'), 'lastsynced is not advanced, so the next run retries');
    }

    // ---------------------------------------------------------------
    // The cron's SELECT includes activities without a SCORM
    // ---------------------------------------------------------------

    public function test_execute_selects_auto_update_activities_without_requiring_a_scorm(): void {
        $this->db->seed('skilland', [$this->activity(['scormcmid' => null])]);
        \fake_api_client::topic_snapshot($this->snapshot());

        (new sync_content())->execute();

        $selects = array_values(array_filter($this->db->get_calls_for('get_records_select'),
            fn($c) => $c['table'] === 'skilland' && str_contains($c['select'], 'autoupdate')));
        $this->assertCount(1, $selects);
        $this->assertSame('autoupdate = 1 AND skilland_topicid IS NOT NULL', $selects[0]['select']);
        $this->assertStringNotContainsString('scormcmid', $selects[0]['select']);
        $this->assertSame([1], $this->updated, 'The activity without a SCORM is rebuilt');
    }

    public function test_activity_without_a_scorm_is_rebuilt_even_when_its_hash_matches(): void {
        \fake_api_client::topic_snapshot($this->snapshot(['contentHash' => 'samehash']));

        $result = $this->check($this->activity(['scormcmid' => null, 'snapshotid' => 'samehash']));

        $this->assertSame('updated', $result);
        $this->assertSame([1], $this->updated);
    }

    public function test_activity_without_a_scorm_and_no_hash_is_rebuilt_not_migrated(): void {
        \fake_api_client::topic_snapshot($this->snapshot());

        $result = $this->check($this->activity(['scormcmid' => null, 'snapshotid' => null]));

        $this->assertSame('updated', $result);
        $this->assertSame([1], $this->updated);
    }

    // ---------------------------------------------------------------
    // Topics without a ready package are skipped
    // ---------------------------------------------------------------

    public function test_topic_without_a_package_is_skipped(): void {
        \fake_api_client::topic_snapshot($this->snapshot(['hasPackage' => false]));

        $this->assertSame('skipped', $this->check($this->activity()));
        $this->assert_nothing_written();
    }

    public function test_response_missing_has_package_is_skipped(): void {
        $snapshot = $this->snapshot();
        unset($snapshot['hasPackage']);
        \fake_api_client::topic_snapshot($snapshot);

        $this->assertSame('skipped', $this->check($this->activity()));
        $this->assert_nothing_written();
    }

    public function test_stale_package_is_skipped(): void {
        \fake_api_client::topic_snapshot($this->snapshot(['isStale' => true]));

        $this->assertSame('skipped', $this->check($this->activity()));
        $this->assert_nothing_written();
    }

    public function test_activity_without_a_scorm_waits_for_a_package(): void {
        \fake_api_client::topic_snapshot($this->snapshot(['hasPackage' => false]));

        $this->assertSame('skipped', $this->check($this->activity(['scormcmid' => null])));
        $this->assert_nothing_written();
    }

    public function test_activity_without_a_scorm_waits_while_the_package_is_stale(): void {
        \fake_api_client::topic_snapshot($this->snapshot(['isStale' => true]));

        $this->assertSame('skipped', $this->check($this->activity(['scormcmid' => null])));
        $this->assert_nothing_written();
    }

    public function test_execute_counts_package_skips_as_skipped(): void {
        $this->db->seed('skilland', [
            $this->activity(['id' => 1]),
            $this->activity(['id' => 2, 'scormcmid' => null]),
        ]);
        \fake_api_client::topic_snapshot($this->snapshot(['isStale' => true]));

        (new sync_content())->execute();

        $complete = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'Sync complete')));
        $this->assertNotEmpty($complete);
        $this->assertStringContainsString('0 updated, 2 skipped, 0 errors', $complete[0]['message']);
        $this->assertSame([], $this->updated);
    }

    public function test_ready_package_with_changed_hash_still_updates(): void {
        \fake_api_client::topic_snapshot($this->snapshot());

        $this->assertSame('updated', $this->check($this->activity()));
        $this->assertSame([1], $this->updated);
    }
}
