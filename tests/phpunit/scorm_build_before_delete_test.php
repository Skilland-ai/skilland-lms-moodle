<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SCORM updates build the new module before deleting the old one (SKL-654).
 *
 * Every failure before the new module is linked must leave the old SCORM, its attempts and the
 * lesson->SCO mapping exactly as they were; only a half-built new module is cleaned up.
 */
class scorm_build_before_delete_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_curl_response', '_test_curl_responses', '_test_curl_last', '_test_curl_requests',
        '_test_lock_available', '_test_lock_calls', '_test_create_module_calls', '_test_create_module_throw',
        '_test_create_module_throw_after_insert', '_test_scorm_scoes', '_test_events', '_test_deleted_cmids',
        '_test_course_delete_throw', '_test_stored_files', '_test_cm_from_db',
        '_test_get_coursemodule_from_instance',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        \core\di::reset_container();
        $this->use_db(new \FakeDatabase());
        $GLOBALS['USER'] = (object) ['id' => 2];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        \fake_api_client::topic_snapshot(null);
        $GLOBALS['_test_scorm_scoes'] = [
            ['identifier' => 'org', 'launch' => ''],
            ['identifier' => 'sco_1', 'launch' => 'l1.html'],
            ['identifier' => 'sco_2', 'launch' => 'l2.html'],
        ];
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        \mod_skilland\logger::reset_cache();
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://api.skilland.ai/graphql',
        ];
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        \core\di::reset_container();
        parent::tearDown();
    }

    /** Install a fake DB and seed an activity already linked to SCORM cmid 50 with learner attempts. */
    private function use_db(\FakeDatabase $db): void {
        $this->db = $db;
        $GLOBALS['DB'] = $db;
        $db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic one', 'skilland_topicid' => 'topic1',
                'autoupdate' => 1, 'lastsynced' => 0, 'lockafterfirstaccess' => 0,
                'scormcmid' => 50, 'scorm_provisioned' => 111,
                'scomappings' => json_encode(['L1' => 'sco_1', 'L2' => 'sco_2']),
                'snapshotid' => 'oldhash', 'snapshotcreatedat' => 222],
        ]);
        $db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3, 'section' => 1]]);
        $db->seed('scorm', [(object) ['id' => 60, 'course' => 3, 'name' => 'Old']]);
        $db->seed('scorm_scoes', [
            (object) ['id' => 500, 'scorm' => 60, 'identifier' => 'sco_1', 'launch' => 'l1.html'],
            (object) ['id' => 501, 'scorm' => 60, 'identifier' => 'sco_2', 'launch' => 'l2.html'],
        ]);
        $db->seed('scorm_attempt', [
            (object) ['id' => 1, 'scormid' => 60, 'userid' => 40, 'attempt' => 1],
            (object) ['id' => 2, 'scormid' => 60, 'userid' => 41, 'attempt' => 1],
        ]);
        $db->seed('scorm_scoes_track', [
            (object) ['id' => 1, 'scormid' => 60, 'scoid' => 500, 'userid' => 40, 'element' => 'cmi.core.lesson_status',
                'value' => 'completed'],
        ]);
        $db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'skilland_lessonid' => 'L1', 'visible' => 1,
                'scoid' => 500, 'sco_identifier' => 'sco_1', 'updatedat' => 5],
            (object) ['id' => 2, 'skillandid' => 7, 'skilland_lessonid' => 'L2', 'visible' => 1,
                'scoid' => 501, 'sco_identifier' => 'sco_2', 'updatedat' => 5],
        ]);
    }

    /** A 200 answer of a REST route carrying $body as JSON. */
    private function rest_response(array $body): array {
        return ['body' => json_encode($body), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    private function zipbytes(): string {
        return base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
    }

    private function queue_lessons(): void {
        $GLOBALS['_test_curl_responses'][] = $this->rest_response(['contents' => [['id' => 'L1', 'updatedAt' => '2026-03-01T00:00:00Z']]]);
    }

    private function queue_scorm_answer(array $mappings): void {
        $list = [];
        foreach ($mappings as $lessonid => $scoid) {
            $list[] = ['lessonId' => $lessonid, 'scoId' => $scoid];
        }
        $GLOBALS['_test_curl_responses'][] = $this->rest_response(\test_package_signer::sign('topic1', [
            'packageUrl' => 'https://cdn.skilland.ai/topic1.zip',
            'packageSize' => strlen($this->zipbytes()),
            'packageHash' => '',
            'generatedAt' => '2026-03-01T00:00:00Z',
            'expiresAt' => '',
            'mappings' => $list,
        ], $this->zipbytes()));
    }

    private function queue_zip(): void {
        $GLOBALS['_test_curl_responses'][] = ['body' => $this->zipbytes(), 'http_code' => 200, 'errno' => 0,
            'error' => ''];
    }

    private function queue_package(array $mappings = ['L1' => 'sco_1', 'L2' => 'sco_2']): void {
        $this->queue_scorm_answer($mappings);
        $this->queue_zip();
    }

    /** Everything the old SCORM consists of, for a before/after equality check. */
    private function old_state(): array {
        return [
            'skilland' => $this->db->get_record('skilland', ['id' => 7]),
            'lessons' => $this->db->get_records('skilland_lesson', ['skillandid' => 7]),
            'cm' => $this->db->get_record('course_modules', ['id' => 50]),
            'scorm' => $this->db->get_record('scorm', ['id' => 60]),
            'scoes' => $this->db->get_records('scorm_scoes', ['scorm' => 60]),
            'attempts' => $this->db->get_records('scorm_attempt', ['scormid' => 60]),
            'tracks' => $this->db->get_records('scorm_scoes_track', ['scormid' => 60]),
        ];
    }

    private function update(?string $hash = null): int {
        $skilland = $this->db->get_record('skilland', ['id' => 7]);
        return skilland_update_topic_scorm($skilland, (object) ['id' => 3], 1, $hash);
    }

    private function expect_failure(?string $code = null): void {
        try {
            $this->update('newhash');
            $this->fail('Expected the update to fail');
        } catch (\moodle_exception $e) {
            if ($code !== null) {
                $this->assertSame($code, $e->errorcode);
            }
        }
    }

    private function lockcalls(string $action): array {
        return array_values(array_filter($GLOBALS['_test_lock_calls'] ?? [], fn($c) => $c['action'] === $action));
    }

    private function assert_old_scorm_untouched(array $before): void {
        $this->assertEquals($before, $this->old_state(), 'Old SCORM, attempts and lesson->SCO map are unchanged');
        $this->assertNotContains(50, $GLOBALS['_test_deleted_cmids'] ?? [], 'The old module is never deleted');
        $this->assertSame([50], array_keys($this->db->get_records('course_modules')),
            'No half-built module is left behind');
        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    // ---------------------------------------------------------------
    // Failures before the new module is linked leave the old one intact
    // ---------------------------------------------------------------

    public function test_api_failure_keeps_the_old_scorm_untouched(): void {
        $before = $this->old_state();
        $this->queue_lessons();
        $GLOBALS['_test_curl_responses'][] = ['body' => 'down', 'http_code' => 500, 'errno' => 0, 'error' => ''];

        $this->expect_failure();

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assert_old_scorm_untouched($before);
    }

    public function test_package_download_failure_keeps_the_old_scorm_untouched(): void {
        $before = $this->old_state();
        $this->queue_lessons();
        $this->queue_scorm_answer(['L1' => 'sco_1', 'L2' => 'sco_2']);
        $GLOBALS['_test_curl_responses'][] = ['body' => '', 'http_code' => 0, 'errno' => 28,
            'error' => 'Operation timed out'];

        $this->expect_failure();

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assert_old_scorm_untouched($before);
    }

    public function test_sco_resolution_failure_deletes_only_the_new_module(): void {
        $before = $this->old_state();
        $this->queue_lessons();
        $this->queue_package(['L1' => 'sco_missing']);

        $this->expect_failure('error_scorm_parse_failed');

        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertCount(1, $GLOBALS['_test_deleted_cmids']);
        $this->assertNotSame(50, $GLOBALS['_test_deleted_cmids'][0], 'Only the half-built module is deleted');
        $this->assertEmpty(array_filter($this->db->get_records('scorm'), fn($s) => (int) $s->id !== 60));
        $this->assert_old_scorm_untouched($before);
    }

    public function test_create_module_failing_half_way_deletes_only_the_new_module(): void {
        $before = $this->old_state();
        $GLOBALS['_test_create_module_throw_after_insert'] = new \moodle_exception('half_way', 'mod_skilland');
        $this->queue_lessons();
        $this->queue_package();

        $this->expect_failure('half_way');

        $this->assertCount(1, $GLOBALS['_test_deleted_cmids']);
        $this->assertNotSame(50, $GLOBALS['_test_deleted_cmids'][0]);
        $this->assert_old_scorm_untouched($before);
    }

    public function test_link_failure_deletes_the_new_module_and_restores_the_old_links(): void {
        $db = new class extends \FakeDatabase {
            public function update_record(string $table, $dataobject): bool {
                if ($table === 'skilland' && !empty($dataobject->scormcmid) && (int) $dataobject->scormcmid !== 50) {
                    throw new \moodle_exception('dmlwriteexception');
                }
                return parent::update_record($table, $dataobject);
            }
        };
        $this->use_db($db);
        $before = $this->old_state();
        $this->queue_lessons();
        $this->queue_package();

        $this->expect_failure('dmlwriteexception');

        $this->assertCount(1, $GLOBALS['_test_deleted_cmids']);
        $this->assertNotSame(50, $GLOBALS['_test_deleted_cmids'][0]);
        $this->assert_old_scorm_untouched($before);
    }

    // ---------------------------------------------------------------
    // Success: new module linked first, old one deleted last, under one lock
    // ---------------------------------------------------------------

    public function test_success_links_the_new_module_before_deleting_the_old_one_under_the_lock(): void {
        $db = new class extends \FakeDatabase {
            /** @var array Ordered steps, each with whether the provisioning lock was held then. */
            public $steps = [];

            private function held(): bool {
                $calls = $GLOBALS['_test_lock_calls'] ?? [];
                $acquired = count(array_filter($calls, fn($c) => $c['action'] === 'acquire' && $c['acquired']));
                $released = count(array_filter($calls, fn($c) => $c['action'] === 'release'));
                return $acquired - $released === 1;
            }

            public function insert_record(string $table, $dataobject, bool $returnid = true): int {
                if ($table === 'course_modules') {
                    $this->steps[] = ['build', $this->held()];
                }
                return parent::insert_record($table, $dataobject, $returnid);
            }

            public function update_record(string $table, $dataobject): bool {
                if ($table === 'skilland' && !empty($dataobject->scormcmid)) {
                    $this->steps[] = ['link', $this->held()];
                }
                return parent::update_record($table, $dataobject);
            }

            public function delete_records(string $table, array $conditions = []): bool {
                if ($table === 'course_modules' && (int) ($conditions['id'] ?? 0) === 50) {
                    $linked = (int) $this->get_field('skilland', 'scormcmid', ['id' => 7]);
                    $this->steps[] = ['delete-old', $this->held(), $linked];
                }
                return parent::delete_records($table, $conditions);
            }
        };
        $this->use_db($db);
        $this->queue_lessons();
        $this->queue_package();

        $newcmid = $this->update('newhash');

        $this->assertNotSame(50, $newcmid);
        $this->assertSame(['build', 'link', 'delete-old'], array_column($db->steps, 0),
            'Build, then link, then delete the old module');
        foreach ($db->steps as $step) {
            $this->assertTrue($step[1], 'The provisioning lock is held during ' . $step[0]);
        }
        $this->assertSame($newcmid, $db->steps[2][2], 'The activity already points at the new module');
        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));

        $row = $this->db->get_record('skilland', ['id' => 7]);
        $this->assertSame($newcmid, (int) $row->scormcmid);
        $newscoes = $this->db->get_records('scorm_scoes', ['scorm' => $this->db->get_field('course_modules',
            'instance', ['id' => $newcmid])], '', 'identifier');
        $this->assertEquals($newscoes['sco_1']->id, $this->db->get_record('skilland_lesson', ['id' => 1])->scoid);
        $this->assertEquals($newscoes['sco_2']->id, $this->db->get_record('skilland_lesson', ['id' => 2])->scoid);
    }

    public function test_success_writes_the_snapshot_hash_exactly_once(): void {
        $this->queue_lessons();
        $this->queue_package();

        $this->update('newhash');

        $writes = array_values(array_filter($this->db->get_calls_for('update_record'),
            fn($c) => $c['table'] === 'skilland' && property_exists($c['data'], 'snapshotid')));
        $this->assertCount(1, $writes);
        $this->assertSame('newhash', $writes[0]['data']->snapshotid);
        $this->assertSame(strtotime('2026-03-01T00:00:00Z'), $writes[0]['data']->snapshotcreatedat);
        $this->assertSame('newhash', $this->db->get_record('skilland', ['id' => 7])->snapshotid);
    }

    public function test_cron_only_announces_and_the_teacher_apply_links_the_new_module(): void {
        \fake_api_client::topic_snapshot(['contentHash' => 'newhash', 'generatedAt' => '2026-03-01T00:00:00Z',
            'hasPackage' => true, 'isStale' => false]);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 7, 'course' => 3,
            'section' => 1];
        $notifier = \fake_update_notifier::install();
        $before = $this->old_state();

        $task = new \mod_skilland\task\sync_content();
        $method = new \ReflectionMethod($task, 'check_and_update');
        $result = $method->invoke($task, $this->db->get_record('skilland', ['id' => 7]));

        // SKL-650: the cron records the update and tells the teachers; the old SCORM is untouched.
        $this->assertSame('notified', $result);
        $this->assertCount(1, $notifier->calls);
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? [], 'The cron downloads nothing');
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $row = $this->db->get_record('skilland', ['id' => 7]);
        $this->assertSame('newhash', $row->updateavailable);
        $this->assertSame(50, (int) $row->scormcmid);
        $this->assertSame($before['skilland']->snapshotid, $row->snapshotid);

        // The teacher applies it through the verified import path, which clears the notice.
        $this->queue_lessons();
        $this->queue_package();
        $this->update('newhash');

        $row = $this->db->get_record('skilland', ['id' => 7]);
        $this->assertNotEquals(50, (int) $row->scormcmid);
        $this->assertSame('newhash', $row->snapshotid);
        $this->assertNull($row->updateavailable);
        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
    }
}
