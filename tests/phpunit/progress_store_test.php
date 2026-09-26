<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * The per-learner progress store (SKL-668): skilland_read_scorm_progress(),
 * skilland_refresh_progress() and skilland_get_user_progress().
 */
class progress_store_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    /** @var array Track rows the fake get_records_sql() answers with. */
    private $tracks = [];

    private const GLOBALS_TO_RESET = ['_test_cm_from_db', '_test_get_coursemodule_from_id', '_test_lock_calls'];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        \mod_skilland\logger::reset_cache();

        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic', 'scormcmid' => 40, 'grade' => 0],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'deletioninprogress' => 0],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'scoid' => 11, 'visible' => 1],
            (object) ['id' => 2, 'skillandid' => 7, 'scoid' => 12, 'visible' => 1],
            (object) ['id' => 3, 'skillandid' => 7, 'scoid' => null, 'visible' => 1],
        ]);
        $this->db->set_records_sql_handler(function (string $sql, array $params): array {
            $users = [];
            $scos = [];
            foreach ($params as $key => $value) {
                if (str_starts_with($key, 'usr')) {
                    $users[] = (int) $value;
                }
                if (str_starts_with($key, 'sco')) {
                    $scos[] = (int) $value;
                }
            }
            $rows = [];
            foreach ($this->tracks as $row) {
                if ($users && !in_array((int) $row->userid, $users, true)) {
                    continue;
                }
                if (!in_array((int) $row->scoid, $scos, true)) {
                    continue;
                }
                $rows[$row->id] = $row;
            }
            return $rows;
        });
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    /**
     * Tracks in the order the real query returns them: latest attempt first.
     */
    private function track(int $userid, int $scoid, string $element, string $value, int $attempt = 1): void {
        $this->tracks[] = (object) [
            'id' => count($this->tracks) + 1,
            'userid' => $userid,
            'attempt' => $attempt,
            'scoid' => $scoid,
            'element' => $element,
            'value' => $value,
        ];
    }

    private function skilland(): \stdClass {
        return $this->db->get_record('skilland', ['id' => 7]);
    }

    private function rows(int $userid = 50): array {
        $rows = [];
        foreach ($this->db->get_records('skilland_progress', ['userid' => $userid]) as $row) {
            $rows[(int) $row->lessonid] = $row;
        }
        ksort($rows);
        return $rows;
    }

    // ---------------------------------------------------------------
    // skilland_read_scorm_progress()
    // ---------------------------------------------------------------

    public function test_read_returns_empty_without_the_43_track_tables(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');

        $this->assertSame([], skilland_read_scorm_progress($this->skilland()));
        $this->assertEmpty($this->db->get_calls_for('get_records_sql'));
    }

    public function test_read_returns_empty_when_the_scorm_is_gone(): void {
        $this->db->delete_records('course_modules', ['id' => 40]);
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');

        $this->assertSame([], skilland_read_scorm_progress($this->skilland()));
    }

    public function test_read_returns_empty_for_an_empty_user_list(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');

        $this->assertSame([], skilland_read_scorm_progress($this->skilland(), []));
    }

    public function test_read_maps_scos_to_lesson_rows_and_keeps_the_latest_attempt(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'incomplete', 2);
        $this->track(50, 11, 'cmi.core.score.raw', '75', 2);
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed', 1);
        $this->track(50, 12, 'cmi.completion_status', 'Not Attempted');
        $this->track(51, 12, 'cmi.core.lesson_status', 'passed');
        $this->track(51, 99, 'cmi.core.lesson_status', 'passed');

        $progress = skilland_read_scorm_progress($this->skilland());

        $this->assertSame([
            50 => [
                1 => ['status' => 'incomplete', 'score' => 75.0],
                2 => ['status' => 'not_started', 'score' => null],
            ],
            51 => [
                2 => ['status' => 'passed', 'score' => null],
            ],
        ], $progress);

        $call = $this->db->get_calls_for('get_records_sql')[0];
        $this->assertSame(5, $call['params']['scormid']);
        $this->assertStringContainsString('{scorm_attempt}', $call['sql']);
        $this->assertStringContainsString('{scorm_scoes_value}', $call['sql']);
    }

    public function test_read_filters_on_the_requested_users(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');
        $this->track(51, 11, 'cmi.core.lesson_status', 'completed');

        $progress = skilland_read_scorm_progress($this->skilland(), [51]);

        $this->assertSame([51], array_keys($progress));
        $this->assertCount(1, $this->db->get_calls_for('get_records_sql'), 'One batched query per SCORM');
    }

    // ---------------------------------------------------------------
    // skilland_refresh_progress()
    // ---------------------------------------------------------------

    public function test_refresh_stores_one_row_per_lesson_row(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');
        $this->track(50, 11, 'cmi.core.score.raw', '80');
        $this->track(50, 12, 'cmi.core.lesson_status', 'incomplete');

        $this->assertTrue(skilland_refresh_progress($this->skilland(), 50));

        $rows = $this->rows();
        $this->assertSame([1, 2], array_keys($rows));
        $this->assertSame('completed', $rows[1]->status);
        $this->assertEquals(80, $rows[1]->score);
        $this->assertSame(7, $rows[1]->skillandid);
        $this->assertSame('incomplete', $rows[2]->status);
        $this->assertNull($rows[2]->score);
    }

    public function test_refresh_without_tracks_changes_nothing(): void {
        $this->assertFalse(skilland_refresh_progress($this->skilland(), 50));
        $this->assertEmpty($this->db->get_calls_for('insert_record'));
    }

    public function test_refresh_twice_with_the_same_tracks_reports_no_change(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');

        $this->assertTrue(skilland_refresh_progress($this->skilland(), 50));
        $this->assertFalse(skilland_refresh_progress($this->skilland(), 50));
        $this->assertCount(1, $this->rows());
    }

    public function test_completed_then_later_incomplete_keeps_completed(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');
        skilland_refresh_progress($this->skilland(), 50);

        $this->tracks = [];
        $this->track(50, 11, 'cmi.core.lesson_status', 'incomplete', 2);

        $this->assertFalse(skilland_refresh_progress($this->skilland(), 50));
        $this->assertSame('completed', $this->rows()[1]->status);
    }

    public function test_failed_moves_up_to_passed(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'failed');
        skilland_refresh_progress($this->skilland(), 50);

        $this->tracks = [];
        $this->track(50, 11, 'cmi.core.lesson_status', 'passed', 2);

        $this->assertTrue(skilland_refresh_progress($this->skilland(), 50));
        $this->assertSame('passed', $this->rows()[1]->status);
    }

    public function test_score_keeps_its_maximum(): void {
        $this->track(50, 11, 'cmi.core.score.raw', '90');
        skilland_refresh_progress($this->skilland(), 50);

        $this->tracks = [];
        $this->track(50, 11, 'cmi.core.score.raw', '40', 2);
        $this->assertFalse(skilland_refresh_progress($this->skilland(), 50));
        $this->assertEquals(90, $this->rows()[1]->score);

        $this->tracks = [];
        $this->track(50, 11, 'cmi.core.score.raw', '95', 3);
        $this->assertTrue(skilland_refresh_progress($this->skilland(), 50));
        $this->assertEquals(95, $this->rows()[1]->score);
    }

    public function test_reprovision_with_new_scoids_and_empty_tracks_keeps_every_row(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');
        $this->track(50, 11, 'cmi.core.score.raw', '70');
        $this->track(50, 12, 'cmi.core.lesson_status', 'incomplete');
        skilland_refresh_progress($this->skilland(), 50);
        $before = $this->rows();

        // Re-provisioning: a new SCORM (new instance, new SCO ids) with no tracks yet.
        $this->db->seed('course_modules', [
            (object) ['id' => 41, 'course' => 3, 'instance' => 6, 'deletioninprogress' => 0],
        ]);
        $this->db->set_field('skilland', 'scormcmid', 41, ['id' => 7]);
        $this->db->set_field('skilland_lesson', 'scoid', 21, ['id' => 1]);
        $this->db->set_field('skilland_lesson', 'scoid', 22, ['id' => 2]);
        $this->tracks = [];

        $this->assertFalse(skilland_refresh_progress($this->skilland(), 50));
        $this->assertEquals($before, $this->rows());

        // A later track on the new SCO lands on the same lesson row.
        $this->track(50, 22, 'cmi.core.lesson_status', 'completed');
        $this->assertTrue(skilland_refresh_progress($this->skilland(), 50));
        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame('completed', $rows[1]->status);
        $this->assertSame('completed', $rows[2]->status);
        $this->assertSame($before[2]->id, $rows[2]->id);
    }

    public function test_refresh_accepts_preread_tracks_without_querying(): void {
        $changed = skilland_refresh_progress($this->skilland(), 50,
            [1 => ['status' => 'passed', 'score' => 88.5]]);

        $this->assertTrue($changed);
        $this->assertEmpty($this->db->get_calls_for('get_records_sql'));
        $this->assertSame('passed', $this->rows()[1]->status);
    }

    public function test_refresh_takes_no_provisioning_lock(): void {
        $this->track(50, 11, 'cmi.core.lesson_status', 'completed');

        skilland_refresh_progress($this->skilland(), 50);

        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
    }

    // ---------------------------------------------------------------
    // skilland_get_user_progress()
    // ---------------------------------------------------------------

    public function test_get_user_progress_is_keyed_by_lesson_row_with_a_trimmed_score(): void {
        $this->db->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => 7, 'lessonid' => 1, 'userid' => 50, 'status' => 'completed',
                'score' => '80.00000'],
            (object) ['id' => 2, 'skillandid' => 7, 'lessonid' => 2, 'userid' => 50, 'status' => 'incomplete',
                'score' => '85.50000'],
            (object) ['id' => 3, 'skillandid' => 7, 'lessonid' => 3, 'userid' => 50, 'status' => 'browsed',
                'score' => null],
            (object) ['id' => 4, 'skillandid' => 7, 'lessonid' => 1, 'userid' => 51, 'status' => 'passed',
                'score' => null],
        ]);

        $this->assertSame([
            1 => ['status' => 'completed', 'score' => '80'],
            2 => ['status' => 'incomplete', 'score' => '85.5'],
            3 => ['status' => 'browsed', 'score' => null],
        ], skilland_get_user_progress(7, 50));
        $this->assertSame([], skilland_get_user_progress(7, 99));
    }

    public function test_status_rank_orders_the_ladder(): void {
        $this->assertSame(0, skilland_progress_status_rank('not_started'));
        $this->assertSame(0, skilland_progress_status_rank(null));
        $this->assertSame(1, skilland_progress_status_rank('incomplete'));
        $this->assertSame(1, skilland_progress_status_rank('browsed'));
        $this->assertSame(2, skilland_progress_status_rank('failed'));
        $this->assertSame(3, skilland_progress_status_rank('completed'));
        $this->assertSame(3, skilland_progress_status_rank('passed'));
    }
}
