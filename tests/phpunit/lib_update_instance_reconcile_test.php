<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * skilland_update_instance() reconciles the topic SCORM with the saved settings (SKL-655).
 */
class lib_update_instance_reconcile_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_curl_response', '_test_curl_responses', '_test_curl_last', '_test_curl_requests',
        '_test_lock_available', '_test_lock_calls', '_test_create_module_calls', '_test_create_module_throw',
        '_test_scorm_scoes', '_test_events', '_test_deleted_cmids', '_test_course_delete_throw',
        '_test_set_visible_calls', '_test_stored_files', '_test_cm_from_db', '_test_get_coursemodule_from_id',
        '_test_get_coursemodule_from_instance', '_test_update_topic_scorm', '_test_notifications',
        '_test_customfield_value', '_test_dispatch_observers',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['USER'] = (object) ['id' => 2];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        $GLOBALS['_test_customfield_value'] = [3 => 'skill-a'];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 7, 'course' => 3,
            'section' => 11];
        $GLOBALS['_test_scorm_scoes'] = [
            ['identifier' => 'org', 'launch' => ''],
            ['identifier' => 'sco_m1', 'launch' => 'm1.html'],
            ['identifier' => 'sco_m2', 'launch' => 'm2.html'],
        ];
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        \mod_skilland\logger::reset_cache();

        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://api.skilland.ai/graphql',
        ];

        $this->db->seed('course_sections', [(object) ['id' => 11, 'course' => 3, 'section' => 2]]);
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->seed('scorm', [(object) ['id' => 60, 'course' => 3]]);
        $this->db->seed('scorm_scoes', [
            (object) ['id' => 101, 'scorm' => 60, 'identifier' => 'sco_1', 'launch' => 'l1.html'],
            (object) ['id' => 102, 'scorm' => 60, 'identifier' => 'sco_2', 'launch' => 'l2.html'],
            (object) ['id' => 103, 'scorm' => 60, 'identifier' => 'sco_3', 'launch' => 'l3.html'],
        ]);
        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic one', 'skilland_topicid' => 'topic1',
                'topic_orderindex' => 1, 'scormcmid' => 50, 'scorm_provisioned' => 1000,
                'scomappings' => json_encode(['L1' => 'sco_1', 'L2' => 'sco_2', 'L3' => 'sco_3']),
                'snapshotid' => 'snap1', 'snapshotcreatedat' => 900],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'skilland_lessonid' => 'L1', 'visible' => 1,
                'scoid' => 101, 'sco_identifier' => 'sco_1'],
            (object) ['id' => 2, 'skillandid' => 7, 'skilland_lessonid' => 'L2', 'visible' => 1,
                'scoid' => 102, 'sco_identifier' => 'sco_2'],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['_test_plugin_config'] = [];
        parent::tearDown();
    }

    private function response(array $data): array {
        return ['body' => json_encode(['data' => $data]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    private function zipbytes(): string {
        return base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
    }

    /** The course's topics, answered to the topic-in-mapped-course check. */
    private function queue_topics(): void {
        $topics = array_map(fn($id) => ['id' => $id, 'name' => $id, 'code' => '', 'description' => ''],
            ['topic1', 'topic2']);
        $GLOBALS['_test_curl_responses'][] = $this->response(['course' => ['id' => 'skill-a', 'name' => 'Skill A',
            'topics' => $topics]]);
    }

    /** The new topic's lessons, its topicScorm answer and the package download. */
    private function queue_reprovision(): void {
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic2', 'name' => 'T2',
            'lessons' => [
                ['id' => 'M1', 'name' => 'M one', 'updatedAt' => '2026-01-03T00:00:00Z'],
                ['id' => 'M2', 'name' => 'M two', 'updatedAt' => '2026-01-03T00:00:00Z'],
            ]]]);
        $GLOBALS['_test_curl_responses'][] = $this->response(['topicScorm' => [
            'packageUrl' => 'https://cdn.skilland.ai/topic2.zip',
            'packageSize' => 100,
            'packageHash' => '',
            'generatedAt' => '2026-01-02T00:00:00Z',
            'expiresAt' => '',
            'mappings' => [['lessonId' => 'M1', 'scoId' => 'sco_m1'], ['lessonId' => 'M2', 'scoId' => 'sco_m2']],
        ]]);
        $GLOBALS['_test_curl_responses'][] = ['body' => $this->zipbytes(), 'http_code' => 200, 'errno' => 0,
            'error' => ''];
    }

    private function formdata(string $topicid, array $lessonids): \stdClass {
        $selected = [];
        foreach ($lessonids as $id) {
            $selected[$id] = ['name' => "Lesson $id", 'updatedAt' => 100];
        }
        return (object) [
            'course' => 3,
            'instance' => 7,
            'name' => 'Topic one',
            'skilland_topicid' => $topicid,
            'topic_orderindex' => 1,
            'selected_lessons' => json_encode($selected),
        ];
    }

    private function row(): \stdClass {
        return $this->db->get_record('skilland', ['id' => 7]);
    }

    private function lesson(string $lessonid): \stdClass {
        return $this->db->get_record('skilland_lesson', ['skillandid' => 7, 'skilland_lessonid' => $lessonid]);
    }

    private function lockacquires(): array {
        return array_values(array_filter($GLOBALS['_test_lock_calls'] ?? [], fn($c) => $c['action'] === 'acquire'));
    }

    // ---------------------------------------------------------------
    // Topic unchanged: lessons ticked later resolve from the installed package
    // ---------------------------------------------------------------

    public function test_lesson_added_after_provisioning_gets_its_sco_without_touching_the_scorm(): void {
        $this->queue_topics();

        $this->assertTrue(skilland_update_instance($this->formdata('topic1', ['L1', 'L2', 'L3'])));

        $this->assertSame(103, $this->lesson('L3')->scoid);
        $this->assertSame('sco_3', $this->lesson('L3')->sco_identifier);
        $this->assertSame(101, $this->lesson('L1')->scoid);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertSame(50, $this->row()->scormcmid);
        $this->assertSame('snap1', $this->row()->snapshotid);
    }

    public function test_lesson_absent_from_the_package_stays_unresolved_and_save_succeeds(): void {
        $this->queue_topics();
        $this->db->set_field('skilland', 'scomappings', json_encode(['L1' => 'sco_1']), ['id' => 7]);

        $this->assertTrue(skilland_update_instance($this->formdata('topic1', ['L1', 'L4'])));

        $this->assertNull($this->lesson('L4')->scoid ?? null);
        $this->assertEmpty($GLOBALS['_test_notifications'] ?? []);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
    }

    public function test_resolution_is_skipped_when_the_lock_is_busy(): void {
        $this->queue_topics();
        $GLOBALS['_test_lock_available'] = false;

        $this->assertTrue(skilland_update_instance($this->formdata('topic1', ['L1', 'L2', 'L3'])));

        $this->assertNull($this->lesson('L3')->scoid ?? null);
        $this->assertSame(2, $this->lockacquires()[0]['timeout']);
    }

    public function test_never_provisioned_activity_makes_no_scorm_call(): void {
        $this->queue_topics();
        $this->db->set_field('skilland', 'scormcmid', null, ['id' => 7]);

        $this->assertTrue(skilland_update_instance($this->formdata('topic1', ['L1', 'L2', 'L3'])));

        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
        $this->assertNull($this->lesson('L3')->scoid ?? null);
    }

    // ---------------------------------------------------------------
    // Topic changed
    // ---------------------------------------------------------------

    public function test_topic_change_reprovisions_once_for_the_new_topic(): void {
        $this->queue_topics();
        $this->queue_reprovision();

        $this->assertTrue(skilland_update_instance($this->formdata('topic2', ['M1', 'M2'])));

        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertSame(2, $GLOBALS['_test_create_module_calls'][0]->section);
        $this->assertCount(1, $this->lockacquires());
        $row = $this->row();
        $this->assertSame('topic2', $row->skilland_topicid);
        $this->assertNotSame(50, (int) $row->scormcmid);
        $this->assertNotEmpty($row->scormcmid);
        $this->assertNull($row->snapshotid);
        $this->assertSame(['M1' => 'sco_m1', 'M2' => 'sco_m2'], json_decode($row->scomappings, true));

        $newscoids = array_keys($this->db->get_records('scorm_scoes', ['scorm' => $this->db->get_field(
            'course_modules', 'instance', ['id' => $row->scormcmid])]));
        foreach (['M1', 'M2'] as $id) {
            $this->assertContains((int) $this->lesson($id)->scoid, array_map('intval', $newscoids));
        }
        foreach (['L1', 'L2'] as $id) {
            $this->assertSame(0, $this->lesson($id)->visible);
            $this->assertNull($this->lesson($id)->scoid);
            $this->assertNull($this->lesson($id)->sco_identifier);
        }
        $this->assertEmpty($GLOBALS['_test_notifications'] ?? []);
    }

    public function test_topic_change_with_a_failing_reprovision_resets_and_warns(): void {
        $this->queue_topics();
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic2', 'name' => 'T2',
            'lessons' => []]]);
        $GLOBALS['_test_curl_responses'][] = ['body' => 'down', 'http_code' => 500, 'errno' => 0, 'error' => ''];

        $this->assertTrue(skilland_update_instance($this->formdata('topic2', ['M1', 'M2'])));

        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertFalse($this->db->get_record('course_modules', ['id' => 50]));
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $row = $this->row();
        foreach (['scormcmid', 'scorm_provisioned', 'scomappings', 'snapshotid'] as $field) {
            $this->assertNull($row->$field, $field);
        }
        foreach (['L1', 'L2', 'M1', 'M2'] as $id) {
            $this->assertNull($this->lesson($id)->scoid ?? null, $id);
        }
        $this->assertSame([['type' => 'warning', 'message' => 'topic_changed_reprovision_failed']],
            $GLOBALS['_test_notifications']);
    }

    public function test_topic_change_with_a_busy_lock_still_saves_and_warns(): void {
        $this->queue_topics();
        $GLOBALS['_test_lock_available'] = false;

        $this->assertTrue(skilland_update_instance($this->formdata('topic2', ['M1'])));

        $this->assertSame('topic2', $this->row()->skilland_topicid);
        $this->assertNull($this->row()->snapshotid);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertSame('warning', $GLOBALS['_test_notifications'][0]['type']);
    }

    public function test_topic_change_on_a_never_provisioned_activity_makes_no_scorm_call(): void {
        $this->queue_topics();
        $this->db->set_field('skilland', 'scormcmid', null, ['id' => 7]);

        $this->assertTrue(skilland_update_instance($this->formdata('topic2', ['M1'])));

        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertNull($this->row()->snapshotid);
    }

    public function test_reconcile_never_throws_when_the_activity_row_vanished(): void {
        $this->db->delete_records('skilland', ['id' => 7]);

        skilland_reconcile_scorm_after_update(7, true);

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_notifications'] ?? []);
    }
}
