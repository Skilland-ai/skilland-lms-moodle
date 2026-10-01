<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * The activity's opt-in grade (SKL-668): skilland_grade_item_update(), skilland_get_user_grades(),
 * skilland_update_grades() and the instance hooks that call them.
 */
class grades_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_grade_updates', '_test_curl_response', '_test_customfield_value', '_test_lock_calls',
        '_test_cm_from_db', '_test_deleted_cmids',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ]];
        // Moodle course 10 is mapped to skill-a, whose only topic is topic1.
        $GLOBALS['_test_customfield_value'] = [10 => 'skill-a'];
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['topics' => [['id' => 'topic1', 'name' => 'topic1', 'description' => '']]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
        \mod_skilland\logger::reset_cache();

        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'visible' => 1],
            (object) ['id' => 2, 'skillandid' => 7, 'visible' => 1],
            (object) ['id' => 3, 'skillandid' => 7, 'visible' => 1],
            (object) ['id' => 4, 'skillandid' => 7, 'visible' => 0],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function skilland(int $grade): \stdClass {
        return (object) ['id' => 7, 'course' => 10, 'name' => 'Topic', 'grade' => $grade];
    }

    private function progress(int $userid, int $lessonid, string $status, $score = null): void {
        $this->db->insert_record('skilland_progress', (object) ['skillandid' => 7, 'lessonid' => $lessonid,
            'userid' => $userid, 'status' => $status, 'score' => $score, 'timemodified' => 1]);
    }

    private function grade_updates(): array {
        return $GLOBALS['_test_grade_updates'] ?? [];
    }

    // ---------------------------------------------------------------
    // skilland_grade_item_update() / skilland_grade_item_delete()
    // ---------------------------------------------------------------

    public function test_grade_item_is_a_point_item_up_to_the_maximum(): void {
        skilland_grade_item_update($this->skilland(100));

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertSame('mod/skilland', $calls[0]['source']);
        $this->assertSame(10, $calls[0]['courseid']);
        $this->assertSame('mod', $calls[0]['itemtype']);
        $this->assertSame('skilland', $calls[0]['itemmodule']);
        $this->assertSame(7, $calls[0]['iteminstance']);
        $this->assertSame(0, $calls[0]['itemnumber']);
        $this->assertSame(GRADE_TYPE_VALUE, $calls[0]['itemdetails']['gradetype']);
        $this->assertSame(100, $calls[0]['itemdetails']['grademax']);
        $this->assertSame(0, $calls[0]['itemdetails']['grademin']);
        $this->assertSame('Topic', $calls[0]['itemdetails']['itemname']);
    }

    public function test_grade_item_update_with_no_grade_deletes_the_item(): void {
        skilland_grade_item_update($this->skilland(0));

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertSame(['deleted' => 1], $calls[0]['itemdetails']);
    }

    public function test_grade_item_update_reset_flag(): void {
        skilland_grade_item_update($this->skilland(50), 'reset');

        $calls = $this->grade_updates();
        $this->assertTrue($calls[0]['itemdetails']['reset']);
        $this->assertNull($calls[0]['grades']);
    }

    // ---------------------------------------------------------------
    // skilland_get_user_grades()
    // ---------------------------------------------------------------

    public function test_scored_completed_and_not_started_over_three_lessons_is_60(): void {
        $this->progress(50, 1, 'completed', 80);
        $this->progress(50, 2, 'completed');
        $this->progress(50, 3, 'not_started');

        $grades = skilland_get_user_grades($this->skilland(100), 50);

        $this->assertEquals([50 => (object) ['userid' => 50, 'rawgrade' => 60.0]], $grades);
    }

    public function test_grade_scales_with_the_maximum(): void {
        $this->progress(50, 1, 'completed', 80);
        $this->progress(50, 2, 'completed');
        $this->progress(50, 3, 'not_started');

        $grades = skilland_get_user_grades($this->skilland(10), 50);

        $this->assertEqualsWithDelta(6.0, $grades[50]->rawgrade, 0.00001);
    }

    public function test_passed_counts_as_full_and_a_lesson_with_no_row_counts_as_zero(): void {
        $this->progress(50, 1, 'passed');

        $grades = skilland_get_user_grades($this->skilland(90), 50);

        $this->assertEqualsWithDelta(30.0, $grades[50]->rawgrade, 0.00001);
    }

    public function test_score_is_clamped_to_0_100_and_wins_over_status(): void {
        $this->progress(50, 1, 'completed', 150);
        $this->progress(50, 2, 'failed', -20);
        $this->progress(50, 3, 'incomplete', 30);

        $grades = skilland_get_user_grades($this->skilland(100), 50);

        $this->assertEqualsWithDelta(130 / 3, $grades[50]->rawgrade, 0.0001);
    }

    public function test_hidden_lessons_are_left_out_of_the_mean(): void {
        $this->progress(50, 1, 'completed');
        $this->progress(50, 2, 'completed');
        $this->progress(50, 3, 'completed');
        $this->progress(50, 4, 'not_started');

        $grades = skilland_get_user_grades($this->skilland(100), 50);

        $this->assertEqualsWithDelta(100.0, $grades[50]->rawgrade, 0.00001);
    }

    public function test_user_with_no_progress_rows_gets_no_grade(): void {
        $this->progress(51, 1, 'completed');

        $this->assertSame([], skilland_get_user_grades($this->skilland(100), 50));
        $this->assertSame([51], array_keys(skilland_get_user_grades($this->skilland(100))));
    }

    public function test_user_with_only_hidden_lesson_rows_gets_no_grade(): void {
        $this->progress(50, 4, 'completed');

        $this->assertSame([], skilland_get_user_grades($this->skilland(100), 50));
    }

    public function test_ungraded_activity_has_no_grades(): void {
        $this->progress(50, 1, 'completed');

        $this->assertSame([], skilland_get_user_grades($this->skilland(0), 50));
    }

    // ---------------------------------------------------------------
    // skilland_update_grades()
    // ---------------------------------------------------------------

    public function test_update_grades_pushes_the_computed_grade(): void {
        $this->progress(50, 1, 'completed');

        skilland_update_grades($this->skilland(30), 50);

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertEqualsWithDelta(10.0, $calls[0]['grades'][50]->rawgrade, 0.00001);
    }

    public function test_update_grades_for_a_user_without_progress_writes_an_empty_grade(): void {
        skilland_update_grades($this->skilland(100), 50);

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertEquals((object) ['userid' => 50, 'rawgrade' => null], $calls[0]['grades']);
    }

    public function test_update_grades_on_an_ungraded_activity_does_nothing(): void {
        $this->progress(50, 1, 'completed');

        skilland_update_grades($this->skilland(0), 50);

        $this->assertSame([], $this->grade_updates());
    }

    // ---------------------------------------------------------------
    // Instance hooks
    // ---------------------------------------------------------------

    private function form_data(array $overrides = []): \stdClass {
        return (object) array_merge([
            'course' => 10,
            'name' => 'Topic',
            'skilland_topicid' => 'topic1',
            'topic_orderindex' => 1,
        ], $overrides);
    }

    public function test_add_instance_with_no_grade_creates_no_grade_item(): void {
        skilland_add_instance($this->form_data(['grade' => 0]));

        $this->assertSame([], $this->grade_updates());
        $inserted = $this->db->get_calls_for('insert_record')[0]['data'];
        $this->assertSame(0, $inserted->grade);
        $this->assertSame(0, $inserted->completionlessons);
    }

    public function test_add_instance_with_a_grade_creates_the_item(): void {
        $id = skilland_add_instance($this->form_data(['grade' => 100, 'completionlessons' => '1']));

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertSame($id, $calls[0]['iteminstance']);
        $this->assertSame(100, $calls[0]['itemdetails']['grademax']);
        $this->assertSame(1, $this->db->get_record('skilland', ['id' => $id])->completionlessons);
    }

    public function test_update_instance_switching_back_to_no_grade_deletes_the_item(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic',
            'skilland_topicid' => 'topic1', 'grade' => 100]]);

        skilland_update_instance($this->form_data(['instance' => 7, 'grade' => 0]));

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertSame(['deleted' => 1], $calls[0]['itemdetails']);
    }

    public function test_update_instance_with_no_grade_before_and_after_touches_nothing(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic',
            'skilland_topicid' => 'topic1', 'grade' => 0]]);

        skilland_update_instance($this->form_data(['instance' => 7, 'grade' => 0]));

        $this->assertSame([], $this->grade_updates());
    }

    public function test_update_instance_changing_the_maximum_regrades_every_user(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic',
            'skilland_topicid' => 'topic1', 'grade' => 100]]);
        $this->progress(50, 1, 'completed');
        $this->progress(51, 1, 'completed');
        $this->progress(51, 2, 'completed');
        $this->progress(51, 3, 'completed');

        skilland_update_instance($this->form_data(['instance' => 7, 'grade' => 60]));

        $calls = $this->grade_updates();
        $this->assertCount(2, $calls, 'Item update, then the regrade');
        $this->assertNull($calls[0]['grades']);
        $this->assertSame(60, $calls[0]['itemdetails']['grademax']);
        $this->assertEqualsWithDelta(20.0, $calls[1]['grades'][50]->rawgrade, 0.00001);
        $this->assertEqualsWithDelta(60.0, $calls[1]['grades'][51]->rawgrade, 0.00001);
    }

    public function test_update_instance_keeping_the_maximum_updates_only_the_item(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic',
            'skilland_topicid' => 'topic1', 'grade' => 100]]);
        $this->progress(50, 1, 'completed');

        skilland_update_instance($this->form_data(['instance' => 7, 'grade' => 100]));

        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertNull($calls[0]['grades']);
    }

    public function test_delete_instance_deletes_the_grade_item_and_progress(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic', 'grade' => 100]]);
        $this->progress(50, 1, 'completed');

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([], $this->db->get_records('skilland_progress'));
        $calls = $this->grade_updates();
        $this->assertCount(1, $calls);
        $this->assertSame(['deleted' => 1], $calls[0]['itemdetails']);
    }

    public function test_delete_ungraded_instance_touches_no_grade_item(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'course' => 10, 'name' => 'Topic', 'grade' => 0]]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([], $this->grade_updates());
    }
}
