<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * The lesson list reads the progress store, refreshed from the SCORM tracks first (SKL-668).
 * The SCORM tracks reader itself moved to locallib.php (skilland_read_scorm_progress()).
 */
class view_progress_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_get_coursemodule_from_id', '_test_cm_from_db', '_test_get_coursemodule_from_instance',
        '_test_grade_updates', '_test_completion_updates',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['USER'] = (object) ['id' => 50];
        $GLOBALS['OUTPUT'] = new \stdClass();
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function lessons(): array {
        return [
            1 => (object) ['id' => 1, 'title' => 'Done', 'scoid' => 11, 'updatedat' => 0],
            2 => (object) ['id' => 2, 'title' => 'Doing', 'scoid' => 12, 'updatedat' => 0],
            3 => (object) ['id' => 3, 'title' => 'Todo', 'scoid' => 13, 'updatedat' => 0],
        ];
    }

    // ---------------------------------------------------------------
    // skilland_read_scorm_progress() — early exits (formerly skilland_get_lessons_progress())
    // ---------------------------------------------------------------

    public function test_progress_returns_empty_when_tables_missing(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);

        $this->assertEmpty(skilland_read_scorm_progress((object) ['id' => 10, 'scormcmid' => 100], [1]));
    }

    public function test_progress_returns_empty_when_no_scormcmid(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);

        $this->assertEmpty(skilland_read_scorm_progress((object) ['id' => 10, 'scormcmid' => null], [1]));
    }

    public function test_progress_returns_empty_when_scorm_cm_not_found(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_id'] = false;

        $this->assertEmpty(skilland_read_scorm_progress((object) ['id' => 10, 'scormcmid' => 100], [1]));
    }

    public function test_progress_returns_empty_when_no_lessons_have_scoid(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object) ['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 10, 'visible' => 1, 'scoid' => null, 'title' => 'No SCO'],
        ]);

        $this->assertEmpty(skilland_read_scorm_progress((object) ['id' => 10, 'scormcmid' => 100], [1]));
        $this->assertEmpty($this->db->get_calls_for('get_records_sql'));
    }

    // ---------------------------------------------------------------
    // skilland_render_lesson_list() — status from the store
    // ---------------------------------------------------------------

    public function test_lesson_list_shows_stored_status_and_score(): void {
        $this->db->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => 10, 'lessonid' => 1, 'userid' => 50, 'status' => 'completed',
                'score' => '80.00000'],
            (object) ['id' => 2, 'skillandid' => 10, 'lessonid' => 2, 'userid' => 50, 'status' => 'incomplete',
                'score' => null],
            (object) ['id' => 3, 'skillandid' => 10, 'lessonid' => 3, 'userid' => 51, 'status' => 'completed',
                'score' => null],
        ]);

        $html = skilland_render_lesson_list((object) ['id' => 10, 'scormcmid' => 100], $this->lessons(),
            (object) ['id' => 90]);

        $this->assertSame(1, substr_count($html, 'skilland-lesson-completed'));
        $this->assertSame(1, substr_count($html, 'skilland-lesson-in-progress'));
        $this->assertSame(1, substr_count($html, 'skilland-lesson-available'), "Another user's row is ignored");
        $this->assertStringContainsString('score: 80%', $html);
    }

    public function test_lesson_list_refreshes_the_store_from_tracks_and_recomputes(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object) ['id' => 100, 'instance' => 5, 'course' => 3];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 10, 'course' => 3];
        $this->db->seed('skilland_lesson', array_map(fn($l) => (object) ['id' => $l->id, 'skillandid' => 10,
            'scoid' => $l->scoid, 'visible' => 1], array_values($this->lessons())));
        $this->db->set_records_sql_handler(fn() => [
            1 => (object) ['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 12,
                'element' => 'cmi.core.lesson_status', 'value' => 'passed'],
        ]);
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100, 'grade' => 0];

        $html = skilland_render_lesson_list($skilland, $this->lessons(), (object) ['id' => 90]);

        $this->assertSame(1, substr_count($html, 'skilland-lesson-completed'));
        $this->assertSame('passed', skilland_get_user_progress(10, 50)[2]['status']);
        $this->assertCount(1, $GLOBALS['_test_completion_updates'] ?? [], 'A changed store recomputes completion');

        // Rendering again changes nothing, so nothing is recomputed.
        skilland_render_lesson_list($skilland, $this->lessons(), (object) ['id' => 90]);
        $this->assertCount(1, $GLOBALS['_test_completion_updates']);
    }

    public function test_progress_reader_issues_no_logging_only_queries(): void {
        // SKL-670: no per-view scorm_scoes listing or all-users scorm_attempt count just for a debug line.
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object) ['id' => 100, 'instance' => 5, 'course' => 3];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 10, 'course' => 3];
        $this->db->seed('skilland_lesson', array_map(fn($l) => (object) ['id' => $l->id, 'skillandid' => 10,
            'scoid' => $l->scoid, 'visible' => 1], array_values($this->lessons())));
        $this->db->set_records_sql_handler(fn() => [
            1 => (object) ['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
            2 => (object) ['id' => 2, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.score.raw', 'value' => '75'],
        ]);
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100, 'grade' => 0];

        $progress = skilland_read_scorm_progress($skilland, [50]);
        skilland_render_lesson_list($skilland, $this->lessons(), (object) ['id' => 90]);

        $this->assertSame([50 => [1 => ['status' => 'completed', 'score' => 75.0]]], $progress);
        $scormattemptcounts = array_filter($this->db->get_calls_for('count_records'),
            fn($c) => $c['table'] === 'scorm_attempt');
        $this->assertEmpty($scormattemptcounts);
        $scoesreads = array_filter($this->db->get_calls_for('get_records'), fn($c) => $c['table'] === 'scorm_scoes');
        $this->assertEmpty($scoesreads);
    }
}
