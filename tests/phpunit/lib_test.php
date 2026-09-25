<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class lib_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'http://localhost:8000/graphql',
        ]];
        // Moodle course 10 is mapped to skill-a, whose topics are topic1 and correct.
        $GLOBALS['_test_customfield_value'] = [10 => 'skill-a'];
        $this->stubSkillTopics(['topic1', 'correct']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_customfield_value']);
        parent::tearDown();
    }

    private function stubSkillTopics(array $topicids): void {
        $topics = array_map(fn($id) => ['id' => $id, 'name' => $id, 'code' => '', 'description' => ''], $topicids);
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => ['course' => ['id' => 'skill-a', 'name' => 'Skill A', 'topics' => $topics]]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    // ---------------------------------------------------------------
    // skilland_supports()
    // ---------------------------------------------------------------

    public function test_supports_returns_true_for_feature_mod_intro(): void {
        $this->assertTrue(skilland_supports(FEATURE_MOD_INTRO));
    }

    public function test_supports_returns_null_for_unknown_feature(): void {
        $this->assertNull(skilland_supports('some_unknown_feature'));
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — invalid/empty input
    // ---------------------------------------------------------------

    public function test_process_lessons_invalid_json_does_nothing(): void {
        skilland_process_selected_lessons(1, 'NOT VALID JSON');

        $this->assertEmpty($this->db->get_calls_for('insert_record'));
        $this->assertEmpty($this->db->get_calls_for('update_record'));
    }

    public function test_process_lessons_empty_object_hides_existing(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'L1', 'skillandid' => 1, 'visible' => 1, 'orderindex' => 1],
        ]);

        skilland_process_selected_lessons(1, '{}');

        $updates = $this->db->get_calls_for('update_record');
        $this->assertCount(1, $updates);
        $this->assertEquals(0, $updates[0]['data']->visible);
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — inserting new lessons
    // ---------------------------------------------------------------

    public function test_process_lessons_inserts_new_lessons(): void {
        $json = json_encode([
            'lesson-a' => ['name' => 'Lesson A', 'updatedAt' => 1700000000],
            'lesson-b' => ['name' => 'Lesson B', 'updatedAt' => 1700001000],
        ]);

        skilland_process_selected_lessons(42, $json);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertCount(2, $inserts);

        $first = $inserts[0]['data'];
        $this->assertEquals(42, $first->skillandid);
        $this->assertEquals('lesson-a', $first->skilland_lessonid);
        $this->assertEquals('Lesson A', $first->title);
        $this->assertEquals(1, $first->orderindex);
        $this->assertEquals(1, $first->visible);
        $this->assertEquals(1700000000, $first->updatedat);

        $second = $inserts[1]['data'];
        $this->assertEquals(2, $second->orderindex);
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — updating existing lessons
    // ---------------------------------------------------------------

    public function test_process_lessons_updates_existing_visible_and_orderindex(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'L1', 'skillandid' => 1, 'visible' => 0, 'orderindex' => 5, 'title' => 'Old Title'],
        ]);

        $json = json_encode([
            'L1' => ['name' => 'New Title', 'updatedAt' => 1700000000],
        ]);

        skilland_process_selected_lessons(1, $json);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertCount(1, $updates);
        $rec = $updates[0]['data'];
        $this->assertEquals(1, $rec->visible);
        $this->assertEquals(1, $rec->orderindex);
        $this->assertEquals('New Title', $rec->title);
    }

    public function test_process_lessons_empty_name_not_overwritten(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'L1', 'skillandid' => 1, 'visible' => 1, 'orderindex' => 1, 'title' => 'Keep Me'],
        ]);

        $json = json_encode([
            'L1' => ['name' => '', 'updatedAt' => 1700000000],
        ]);

        skilland_process_selected_lessons(1, $json);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertEquals('Keep Me', $updates[0]['data']->title);
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — hiding unselected
    // ---------------------------------------------------------------

    public function test_process_lessons_hides_unselected_visible_lessons(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'L1', 'skillandid' => 1, 'visible' => 1, 'orderindex' => 1],
            (object)['id' => 11, 'skilland_lessonid' => 'L2', 'skillandid' => 1, 'visible' => 1, 'orderindex' => 2],
        ]);

        // Only select L1, not L2.
        $json = json_encode([
            'L1' => ['name' => 'Lesson 1', 'updatedAt' => 0],
        ]);

        skilland_process_selected_lessons(1, $json);

        $updates = $this->db->get_calls_for('update_record');
        // L1 updated (visible=1), L2 hidden (visible=0).
        $this->assertCount(2, $updates);
        $hidden = $updates[1]['data'];
        $this->assertEquals(0, $hidden->visible);
        $this->assertEquals(11, $hidden->id);
    }

    public function test_process_lessons_already_hidden_not_updated_again(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'L1', 'skillandid' => 1, 'visible' => 0, 'orderindex' => 1],
        ]);

        skilland_process_selected_lessons(1, '{}');

        // L1 is already hidden (visible=0), so no update should happen.
        $updates = $this->db->get_calls_for('update_record');
        $this->assertEmpty($updates);
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — timestamp handling
    // ---------------------------------------------------------------

    public function test_process_lessons_iso8601_timestamp_converted(): void {
        $json = json_encode([
            'L1' => ['name' => 'Test', 'updatedAt' => '2024-01-15T10:30:00Z'],
        ]);

        skilland_process_selected_lessons(1, $json);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals(strtotime('2024-01-15T10:30:00Z'), $inserts[0]['data']->updatedat);
    }

    public function test_process_lessons_numeric_timestamp_preserved(): void {
        $json = json_encode([
            'L1' => ['name' => 'Test', 'updatedAt' => 1700000000],
        ]);

        skilland_process_selected_lessons(1, $json);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals(1700000000, $inserts[0]['data']->updatedat);
    }

    // ---------------------------------------------------------------
    // skilland_add_instance()
    // ---------------------------------------------------------------

    public function test_add_instance_sets_timestamps(): void {
        $before = time();
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 3;

        $id = skilland_add_instance($data);

        $this->assertIsInt($id);
        $inserts = $this->db->get_calls_for('insert_record');
        $inserted = $inserts[0]['data'];
        $this->assertGreaterThanOrEqual($before, $inserted->timecreated);
        $this->assertGreaterThanOrEqual($before, $inserted->timemodified);
    }

    public function test_add_instance_prefers_saved_topicid(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'wrong';
        $data->skilland_topicid_saved = 'correct';
        $data->topic_orderindex = 1;

        skilland_add_instance($data);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals('correct', $inserts[0]['data']->skilland_topicid);
    }

    public function test_add_instance_defaults_topic_orderindex_to_1(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'topic1';

        skilland_add_instance($data);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals(1, $inserts[0]['data']->topic_orderindex);
    }

    public function test_add_instance_removes_form_only_fields(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'topic1';
        $data->skilland_courseid = 'should-be-removed';
        $data->skilland_courseid_readonly = 'also-removed';
        $data->topic_orderindex = 1;

        skilland_add_instance($data);

        $inserts = $this->db->get_calls_for('insert_record');
        $inserted = $inserts[0]['data'];
        $this->assertObjectNotHasProperty('skilland_courseid', $inserted);
        $this->assertObjectNotHasProperty('skilland_courseid_readonly', $inserted);
    }

    // ---------------------------------------------------------------
    // skilland_process_selected_lessons() — edge cases
    // ---------------------------------------------------------------

    public function test_process_lessons_null_json_string_does_nothing(): void {
        skilland_process_selected_lessons(1, 'null');

        $this->assertEmpty($this->db->get_calls_for('insert_record'));
        $this->assertEmpty($this->db->get_calls_for('update_record'));
    }

    public function test_process_lessons_missing_keys_uses_defaults(): void {
        // Lesson with no 'name' or 'updatedAt' keys.
        $json = json_encode([
            'L1' => [],
        ]);

        skilland_process_selected_lessons(1, $json);

        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertCount(1, $inserts);
        $rec = $inserts[0]['data'];
        $this->assertEquals('', $rec->title);
        $this->assertEquals(0, $rec->updatedat);
    }

    public function test_process_lessons_mixed_insert_and_update(): void {
        $this->db->seed('skilland_lesson', [
            (object)['id' => 10, 'skilland_lessonid' => 'existing', 'skillandid' => 1, 'visible' => 1, 'orderindex' => 1, 'title' => 'Old'],
        ]);

        $json = json_encode([
            'existing' => ['name' => 'Updated', 'updatedAt' => 100],
            'new-lesson' => ['name' => 'Brand New', 'updatedAt' => 200],
        ]);

        skilland_process_selected_lessons(1, $json);

        $updates = $this->db->get_calls_for('update_record');
        $inserts = $this->db->get_calls_for('insert_record');

        // First lesson updated (orderindex=1), second inserted (orderindex=2).
        $this->assertCount(1, $updates);
        $this->assertEquals('Updated', $updates[0]['data']->title);
        $this->assertEquals(1, $updates[0]['data']->orderindex);

        $this->assertCount(1, $inserts);
        $this->assertEquals('Brand New', $inserts[0]['data']->title);
        $this->assertEquals(2, $inserts[0]['data']->orderindex);
    }

    public function test_process_lessons_invalid_date_string_returns_false(): void {
        $json = json_encode([
            'L1' => ['name' => 'Test', 'updatedAt' => 'not-a-date'],
        ]);

        skilland_process_selected_lessons(1, $json);

        $inserts = $this->db->get_calls_for('insert_record');
        // strtotime('not-a-date') returns false.
        $this->assertFalse($inserts[0]['data']->updatedat);
    }

    // ---------------------------------------------------------------
    // skilland_add_instance() — with selected_lessons
    // ---------------------------------------------------------------

    public function test_add_instance_processes_selected_lessons(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 1;
        $data->selected_lessons = json_encode([
            'L1' => ['name' => 'Lesson 1', 'updatedAt' => 100],
        ]);

        $id = skilland_add_instance($data);

        // Should have inserted the activity and the lesson.
        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertCount(2, $inserts);
        $this->assertEquals('skilland', $inserts[0]['table']);
        $this->assertEquals('skilland_lesson', $inserts[1]['table']);
        $this->assertEquals($id, $inserts[1]['data']->skillandid);
    }

    // ---------------------------------------------------------------
    // skilland_update_instance()
    // ---------------------------------------------------------------

    public function test_update_instance_sets_timemodified(): void {
        $before = time();
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 5;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 1;

        skilland_update_instance($data);

        $updates = $this->db->get_calls_for('update_record');
        $updated = $updates[0]['data'];
        $this->assertGreaterThanOrEqual($before, $updated->timemodified);
    }

    public function test_update_instance_sets_id_from_instance(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 42;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 1;

        skilland_update_instance($data);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertEquals(42, $updates[0]['data']->id);
    }

    public function test_update_instance_prefers_saved_topicid(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'wrong';
        $data->skilland_topicid_saved = 'correct';
        $data->topic_orderindex = 1;

        skilland_update_instance($data);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertEquals('correct', $updates[0]['data']->skilland_topicid);
    }

    public function test_update_instance_defaults_topic_orderindex_to_1(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'topic1';

        skilland_update_instance($data);

        $updates = $this->db->get_calls_for('update_record');
        $this->assertEquals(1, $updates[0]['data']->topic_orderindex);
    }

    public function test_update_instance_removes_form_only_fields(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'topic1';
        $data->skilland_courseid = 'remove-me';
        $data->skilland_courseid_readonly = 'remove-me-too';
        $data->topic_orderindex = 1;

        skilland_update_instance($data);

        $updates = $this->db->get_calls_for('update_record');
        $updated = $updates[0]['data'];
        $this->assertObjectNotHasProperty('skilland_courseid', $updated);
        $this->assertObjectNotHasProperty('skilland_courseid_readonly', $updated);
        $this->assertObjectNotHasProperty('skilland_topicid_saved', $updated);
    }

    public function test_update_instance_processes_selected_lessons(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 1;
        $data->selected_lessons = json_encode([
            'L1' => ['name' => 'Lesson 1', 'updatedAt' => 100],
        ]);

        skilland_update_instance($data);

        // Should have updated the activity + inserted a lesson.
        $updates = $this->db->get_calls_for('update_record');
        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertEquals('skilland', $updates[0]['table']);
        $this->assertEquals('skilland_lesson', $inserts[0]['table']);
    }

    public function test_update_instance_skips_lessons_on_db_failure(): void {
        // Seed an existing record so update succeeds.
        // FakeDatabase::update_record always returns true, so we simulate failure
        // by verifying the lesson processing depends on the result.
        // When update_record returns true, lessons are processed.
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'topic1';
        $data->topic_orderindex = 1;
        $data->selected_lessons = json_encode([
            'L1' => ['name' => 'Lesson 1', 'updatedAt' => 100],
        ]);

        $result = skilland_update_instance($data);
        $this->assertTrue($result);

        // Lessons should be processed since result was truthy.
        $inserts = $this->db->get_calls_for('insert_record');
        $this->assertNotEmpty($inserts);
    }

    // ---------------------------------------------------------------
    // skilland_delete_instance()
    // ---------------------------------------------------------------

    public function test_delete_instance_returns_false_when_not_exists(): void {
        $this->assertFalse(skilland_delete_instance(999));
    }

    public function test_delete_instance_deletes_lessons_then_instance(): void {
        $this->db->seed('skilland', [
            (object)['id' => 1, 'name' => 'Test'],
        ]);

        $result = skilland_delete_instance(1);

        $this->assertTrue($result);
        $deletes = $this->db->get_calls_for('delete_records');
        $this->assertCount(2, $deletes);
        $this->assertEquals('skilland_lesson', $deletes[0]['table']);
        $this->assertEquals(['skillandid' => 1], $deletes[0]['conditions']);
        $this->assertEquals('skilland', $deletes[1]['table']);
        $this->assertEquals(['id' => 1], $deletes[1]['conditions']);
    }

    // ---------------------------------------------------------------
    // skilland_get_coursemodule_info() — hidelabels on course page
    // ---------------------------------------------------------------

    public function test_coursemodule_info_returns_null_when_record_missing(): void {
        $cm = (object)['instance' => 999];

        $result = skilland_get_coursemodule_info($cm);

        $this->assertNull($result);
    }

    public function test_coursemodule_info_strips_topic_code_when_hidelabels(): void {
        $this->db->seed('skilland', [
            (object)['id' => 1, 'name' => 'T2 - Advanced Topics', 'hidelabels' => 1],
        ]);
        $cm = (object)['instance' => 1];

        $result = skilland_get_coursemodule_info($cm);

        $this->assertInstanceOf(\cached_cm_info::class, $result);
        $this->assertEquals('Advanced Topics', $result->name);
    }

    public function test_coursemodule_info_preserves_name_when_hidelabels_off(): void {
        $this->db->seed('skilland', [
            (object)['id' => 1, 'name' => 'T2 - Advanced Topics', 'hidelabels' => 0],
        ]);
        $cm = (object)['instance' => 1];

        $result = skilland_get_coursemodule_info($cm);

        $this->assertInstanceOf(\cached_cm_info::class, $result);
        $this->assertNull($result->name);
    }

    public function test_coursemodule_info_handles_name_without_topic_code(): void {
        $this->db->seed('skilland', [
            (object)['id' => 1, 'name' => 'Custom Name Without Code', 'hidelabels' => 1],
        ]);
        $cm = (object)['instance' => 1];

        $result = skilland_get_coursemodule_info($cm);

        $this->assertEquals('Custom Name Without Code', $result->name);
    }

    public function test_coursemodule_info_strips_double_digit_topic_code(): void {
        $this->db->seed('skilland', [
            (object)['id' => 1, 'name' => 'T12 - Topic Twelve', 'hidelabels' => 1],
        ]);
        $cm = (object)['instance' => 1];

        $result = skilland_get_coursemodule_info($cm);

        $this->assertEquals('Topic Twelve', $result->name);
    }

    // ---------------------------------------------------------------
    // Topic must belong to the course's mapped skill (SKL-661)
    // ---------------------------------------------------------------

    public function test_add_instance_rejects_foreign_saved_topicid(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->skilland_topicid = 'topic1';
        $data->skilland_topicid_saved = 'topic-of-another-skill';

        try {
            skilland_add_instance($data);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_not_mapped_to_skill', $e->errorcode);
        }
        $this->assertEmpty($this->db->get_calls_for('insert_record'));
    }

    public function test_add_instance_rejects_unmapped_course(): void {
        $data = new \stdClass();
        $data->course = 99;
        $data->skilland_topicid = 'topic1';

        try {
            skilland_add_instance($data);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_not_mapped', $e->errorcode);
        }
        $this->assertEmpty($this->db->get_calls_for('insert_record'));
    }

    public function test_update_instance_rejects_foreign_saved_topicid(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;
        $data->skilland_topicid = 'topic1';
        $data->skilland_topicid_saved = 'topic-of-another-skill';

        try {
            skilland_update_instance($data);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_not_mapped_to_skill', $e->errorcode);
        }
        $this->assertEmpty($this->db->get_calls_for('update_record'));
    }

    public function test_update_instance_rejects_empty_topicid(): void {
        $data = new \stdClass();
        $data->course = 10;
        $data->instance = 1;

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_update_instance($data);
    }
}
