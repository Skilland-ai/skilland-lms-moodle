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
        $GLOBALS['PAGE'] = new \test_moodle_page();
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

    // ---------------------------------------------------------------
    // skilland_viewer_progress() and the app player links (SKL-699)
    // ---------------------------------------------------------------

    public function test_viewer_progress_reads_nothing_without_lessons(): void {
        $this->assertSame([], skilland_viewer_progress((object) ['id' => 10, 'scormcmid' => 100], [], 50));
        $this->assertEmpty($this->db->get_calls());
    }

    public function test_viewer_progress_is_what_the_lesson_list_shows(): void {
        $this->db->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => 10, 'lessonid' => 1, 'userid' => 50, 'status' => 'completed',
                'score' => '80.00000'],
            (object) ['id' => 2, 'skillandid' => 10, 'lessonid' => 3, 'userid' => 51, 'status' => 'completed',
                'score' => null],
        ]);

        $progress = skilland_viewer_progress((object) ['id' => 10, 'scormcmid' => 100], $this->lessons(), 50);

        $this->assertSame([1 => ['status' => 'completed', 'score' => '80']], $progress);
    }

    public function test_player_url_has_a_web_shape_and_an_app_shape(): void {
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100];
        $lesson = (object) ['id' => 2, 'scoid' => 12];

        $this->assertSame('/mod/scorm/player.php?scoid=12&cm=100&mode=normal&newattempt=off&display=popup',
            skilland_scorm_player_url($skilland, $lesson)->out(false));
        // The app's SCORM player link handler matches id= (or a=) and reads scoid, mode, newattempt and courseid.
        $this->assertSame('/mod/scorm/player.php?id=100&scoid=12&mode=normal&newattempt=off&courseid=3',
            skilland_scorm_player_url($skilland, $lesson, true)->out(false));
    }

    public function test_lesson_list_links_to_the_app_player_when_asked(): void {
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100, 'hidelabels' => 0];
        $renderer = $GLOBALS['PAGE']->get_renderer('mod_skilland');

        $web = (new \mod_skilland\output\lesson_list($skilland, $this->lessons(), (object) ['id' => 90]))
            ->export_for_template($renderer);
        $app = (new \mod_skilland\output\lesson_list($skilland, $this->lessons(), (object) ['id' => 90], 1, [], false, true))
            ->export_for_template($renderer);

        $this->assertSame('/mod/skilland/view.php?id=90&play=1', $web['lessons'][0]['url']);
        $this->assertSame('/mod/skilland/view.php?id=90&play=1', $web['continueurl']);
        $this->assertSame('/mod/scorm/player.php?id=100&scoid=11&mode=normal&newattempt=off&courseid=3',
            $app['lessons'][0]['url']);
        $this->assertSame('/mod/scorm/player.php?id=100&scoid=13&mode=normal&newattempt=off&courseid=3',
            $app['lessons'][2]['url']);
        $this->assertSame($app['lessons'][0]['url'], $app['continueurl'], 'Start opens the first lesson');
        // Everything but the links is the same on web and app.
        foreach ($web['lessons'] as $i => $card) {
            unset($card['url'], $app['lessons'][$i]['url']);
            $this->assertSame($card, $app['lessons'][$i]);
        }
        $this->assertSame($web['continuetext'], $app['continuetext']);
        $this->assertSame($web['continuemode'], $app['continuemode']);
    }

    public function test_app_template_text_encodes_angular_syntax(): void {
        $this->assertSame('&#123;&#123; a &#125;&#125; &#64;if (b) &#123;c&#125; &lt;p&gt;',
            skilland_app_template_text('{{ a }} @if (b) {c} &lt;p&gt;'));
        $this->assertSame('Plain &amp; title', skilland_app_template_text('Plain &amp; title'));
    }

    public function test_lesson_list_encodes_titles_for_the_app_only(): void {
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100, 'hidelabels' => 0];
        $lessons = [1 => (object) ['id' => 1, 'title' => '{{ 1 + 1 }}', 'scoid' => 11, 'updatedat' => 0]];
        $renderer = $GLOBALS['PAGE']->get_renderer('mod_skilland');

        $web = (new \mod_skilland\output\lesson_list($skilland, $lessons, (object) ['id' => 90]))
            ->export_for_template($renderer);
        $app = (new \mod_skilland\output\lesson_list($skilland, $lessons, (object) ['id' => 90], 1, [], false, true))
            ->export_for_template($renderer);

        $this->assertSame('{{ 1 + 1 }}', $web['lessons'][0]['title'], 'The web view is unchanged');
        $this->assertSame('&#123;&#123; 1 + 1 &#125;&#125;', $app['lessons'][0]['title']);
    }

    public function test_lesson_list_app_links_leave_unplayable_lessons_without_a_link(): void {
        $skilland = (object) ['id' => 10, 'course' => 3, 'scormcmid' => 100, 'hidelabels' => 0];
        $lessons = [1 => (object) ['id' => 1, 'title' => 'No SCO', 'scoid' => null, 'updatedat' => 0]];

        $app = (new \mod_skilland\output\lesson_list($skilland, $lessons, (object) ['id' => 90], 1, [], false, true))
            ->export_for_template($GLOBALS['PAGE']->get_renderer('mod_skilland'));

        $this->assertFalse($app['lessons'][0]['playable']);
        $this->assertSame('', $app['lessons'][0]['url']);
        $this->assertSame('not_yet_available', $app['lessons'][0]['statustext']);
        $this->assertFalse($app['hassummary']);
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
