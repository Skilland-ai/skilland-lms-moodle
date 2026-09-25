<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_course_mapping_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_customfield_value'] = [];
        unset($GLOBALS['_test_curl_response']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_customfield_value']);
        parent::tearDown();
    }

    private function stubTopics(string $courseid, array $topicids): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];
        $topics = array_map(fn($id) => ['id' => $id, 'name' => $id, 'code' => '', 'description' => ''], $topicids);
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => ['course' => ['id' => $courseid, 'name' => 'C', 'topics' => $topics]]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    // ---------------------------------------------------------------
    // skilland_get_course_mapping()
    // ---------------------------------------------------------------

    public function test_get_course_mapping_returns_record(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'EK-100', 'skilland_orgid' => 'org1'],
        ]);

        $result = skilland_get_course_mapping(10);

        $this->assertIsObject($result);
        $this->assertEquals('EK-100', $result->skilland_courseid);
    }

    public function test_get_course_mapping_returns_false_when_not_found(): void {
        $result = skilland_get_course_mapping(999);

        $this->assertFalse($result);
    }

    // ---------------------------------------------------------------
    // skilland_course_has_mapping()
    // ---------------------------------------------------------------

    public function test_course_has_mapping_returns_true(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'EK-100'],
        ]);

        $this->assertTrue(skilland_course_has_mapping(10));
    }

    public function test_course_has_mapping_returns_false(): void {
        $this->assertFalse(skilland_course_has_mapping(999));
    }

    // ---------------------------------------------------------------
    // skilland_set_course_mapping() — create new
    // ---------------------------------------------------------------

    public function test_set_course_mapping_creates_new(): void {
        $before = time();
        $result = skilland_set_course_mapping(10, 'EK-200', 'org1');

        $this->assertIsInt($result);
        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertCount(1, $inserts);
        $rec = $inserts[0]['data'];
        $this->assertEquals(10, $rec->course);
        $this->assertEquals('EK-200', $rec->skilland_courseid);
        $this->assertEquals('org1', $rec->skilland_orgid);
        $this->assertGreaterThanOrEqual($before, $rec->timecreated);
        $this->assertNull($rec->timesynced);
    }

    public function test_set_course_mapping_updates_existing(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 5, 'course' => 10, 'skilland_courseid' => 'OLD', 'skilland_orgid' => 'org1', 'timemodified' => 1000],
        ]);

        $result = skilland_set_course_mapping(10, 'NEW', 'org2');

        $this->assertEquals(5, $result);
        $updates = $this->db->get_calls_for('update_record');
        $this->assertCount(1, $updates);
        $this->assertEquals('NEW', $updates[0]['data']->skilland_courseid);
        $this->assertEquals('org2', $updates[0]['data']->skilland_orgid);
    }

    public function test_set_course_mapping_uses_config_orgid_when_null(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => 'config-org'];

        skilland_set_course_mapping(10, 'EK-300');

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals('config-org', $inserts[0]['data']->skilland_orgid);
    }

    public function test_set_course_mapping_uses_provided_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => 'config-org'];

        skilland_set_course_mapping(10, 'EK-300', 'explicit-org');

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals('explicit-org', $inserts[0]['data']->skilland_orgid);
    }

    // ---------------------------------------------------------------
    // skilland_update_course_sync()
    // ---------------------------------------------------------------

    public function test_update_course_sync_sets_timestamps(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'timesynced' => null, 'timemodified' => 1000],
        ]);

        $before = time();
        $result = skilland_update_course_sync(10);

        $this->assertTrue($result);
        $updates = $this->db->get_calls_for('update_record');
        $this->assertGreaterThanOrEqual($before, $updates[0]['data']->timesynced);
        $this->assertGreaterThanOrEqual($before, $updates[0]['data']->timemodified);
    }

    public function test_update_course_sync_returns_false_when_no_mapping(): void {
        $this->assertFalse(skilland_update_course_sync(999));
    }

    // ---------------------------------------------------------------
    // skilland_get_skilland_courseid()
    // ---------------------------------------------------------------

    public function test_get_skilland_courseid_returns_id(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'EK-100'],
        ]);

        $this->assertEquals('EK-100', skilland_get_skilland_courseid(10));
    }

    public function test_get_skilland_courseid_returns_false_when_unmapped(): void {
        $this->assertFalse(skilland_get_skilland_courseid(999));
    }

    // ---------------------------------------------------------------
    // skilland_delete_course_mapping()
    // ---------------------------------------------------------------

    public function test_delete_course_mapping_delegates_to_delete_records(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10],
        ]);

        $result = skilland_delete_course_mapping(10);

        $this->assertTrue($result);
        $deletes = $this->db->get_calls_for('delete_records');
        $this->assertCount(1, $deletes);
        $this->assertEquals('skilland_course', $deletes[0]['table']);
        $this->assertEquals(['course' => 10], $deletes[0]['conditions']);
    }

    // ---------------------------------------------------------------
    // skilland_get_mapped_courseid() (SKL-661)
    // ---------------------------------------------------------------

    public function test_get_mapped_courseid_prefers_custom_field(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-field';
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->assertSame('skill-field', skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_falls_back_to_table(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->assertSame('skill-table', skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_returns_null_when_unmapped(): void {
        $this->assertNull(skilland_get_mapped_courseid(999));
    }

    // ---------------------------------------------------------------
    // skilland_require_mapped_course() (SKL-661)
    // ---------------------------------------------------------------

    public function test_require_mapped_course_passes_on_match(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->assertSame('skill-a', skilland_require_mapped_course(10, 'skill-a'));
    }

    public function test_require_mapped_course_throws_on_mismatch(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, 'skill-b');
    }

    public function test_require_mapped_course_throws_when_unmapped(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        skilland_require_mapped_course(10, 'skill-a');
    }

    public function test_require_mapped_course_uses_custom_field_over_table(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-field';
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, 'skill-table');
    }

    public function test_require_mapped_course_uses_table_when_no_custom_field(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->assertSame('skill-table', skilland_require_mapped_course(10, 'skill-table'));
    }

    // ---------------------------------------------------------------
    // skilland_topic_belongs_to_course() (SKL-661)
    // ---------------------------------------------------------------

    public function test_topic_belongs_to_course_true_for_listed_topic(): void {
        $this->stubTopics('skill-a', ['topic-1', 'topic-2']);

        $this->assertTrue(skilland_topic_belongs_to_course('topic-2', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_for_foreign_topic(): void {
        $this->stubTopics('skill-a', ['topic-1', 'topic-2']);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-foreign', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_for_empty_ids(): void {
        $this->stubTopics('skill-a', ['topic-1']);

        $this->assertFalse(skilland_topic_belongs_to_course('', 'skill-a'));
        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', ''));
    }

    public function test_get_mapped_courseid_empty_custom_field_falls_back_to_table(): void {
        $GLOBALS['_test_customfield_value'][10] = '';
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->assertSame('skill-table', skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_null_custom_field_falls_back_to_table(): void {
        $GLOBALS['_test_customfield_value'][10] = null;
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);

        $this->assertSame('skill-table', skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_empty_everywhere_is_unmapped(): void {
        $GLOBALS['_test_customfield_value'][10] = '';
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => ''],
        ]);

        $this->assertNull(skilland_get_mapped_courseid(10));
    }

    public function test_require_mapped_course_rejects_empty_request_id(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, '');
    }

    public function test_require_mapped_course_is_case_sensitive(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, 'SKILL-A');
    }

    public function test_topic_belongs_to_course_false_for_empty_topic_list(): void {
        $this->stubTopics('skill-a', []);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_when_api_returns_no_course(): void {
        $this->stubTopics('skill-a', []);
        $GLOBALS['_test_curl_response']['body'] = json_encode(['data' => ['course' => null]]);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_ignores_topics_without_id(): void {
        $this->stubTopics('skill-a', []);
        $GLOBALS['_test_curl_response']['body'] = json_encode(['data' => ['course' => [
            'id' => 'skill-a', 'name' => 'C', 'topics' => [['name' => 'topic-1']],
        ]]]);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_throws_on_api_failure(): void {
        $this->stubTopics('skill-a', ['topic-1']);
        $GLOBALS['_test_curl_response']['http_code'] = 500;
        $GLOBALS['_test_curl_response']['body'] = 'boom';

        $this->expectException(\moodle_exception::class);

        skilland_topic_belongs_to_course('topic-1', 'skill-a');
    }
}
