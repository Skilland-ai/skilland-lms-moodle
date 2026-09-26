<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * view.php treats a deleted linked SCORM as unprovisioned (SKL-673).
 */
class view_missing_scorm_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['USER'] = (object) ['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        $GLOBALS['PAGE'] = new \stdClass();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        \mod_skilland\logger::reset_cache();

        $this->db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'deletioninprogress' => 0],
        ]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_cm_from_db']);
        parent::tearDown();
    }

    public function test_detect_leaves_live_scorm_alone(): void {
        $skilland = (object) ['id' => 7, 'scormcmid' => 40];

        $this->assertFalse(skilland_detect_missing_scorm($skilland));
        $this->assertSame(40, $skilland->scormcmid);
    }

    public function test_detect_ignores_unprovisioned_activity(): void {
        $skilland = (object) ['id' => 7, 'scormcmid' => null];

        $this->assertFalse(skilland_detect_missing_scorm($skilland));
        $this->assertNull($skilland->scormcmid);
    }

    public function test_detect_clears_deleted_scorm_in_memory_only(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'scormcmid' => 99]]);
        $skilland = (object) ['id' => 7, 'scormcmid' => 99];

        $this->assertTrue(skilland_detect_missing_scorm($skilland));
        $this->assertNull($skilland->scormcmid);
        $this->assertSame(99, $this->db->get_record('skilland', ['id' => 7])->scormcmid);
        $this->assertEmpty($this->db->get_calls_for('update_record'));
        $this->assertEmpty($this->db->get_calls_for('set_field'));
    }

    public function test_detect_treats_deletion_in_progress_as_missing(): void {
        $this->db->set_field('course_modules', 'deletioninprogress', 1, ['id' => 40]);
        $skilland = (object) ['id' => 7, 'scormcmid' => 40];

        $this->assertTrue(skilland_detect_missing_scorm($skilland));
        $this->assertNull($skilland->scormcmid);
    }

    public function test_view_calls_detect_before_play_branch_and_warns_teachers(): void {
        $source = file_get_contents(__DIR__ . '/../../src/view.php');
        $detect = strpos($source, '$scormmissing = skilland_detect_missing_scorm($skilland);');
        $play = strpos($source, 'if ($play > 0)');
        $this->assertNotFalse($detect);
        $this->assertNotFalse($play);
        $this->assertLessThan($play, $detect);
        $this->assertStringContainsString(
            "\$OUTPUT->notification(get_string('scorm_missing_reprovision', 'mod_skilland'), 'warning');", $source);
    }

    public function test_player_with_missing_scorm_module_shows_not_ready(): void {
        $skilland = (object) ['id' => 7, 'scormcmid' => 99, 'hidelabels' => 0];
        $lesson = (object) ['id' => 10, 'title' => 'Lesson', 'scoid' => 11];
        $cm = (object) ['id' => 1];

        $html = skilland_render_player_view($skilland, $lesson, $cm, [10 => $lesson], 1);

        $this->assertStringContainsString('scorm_not_ready', $html);
        $this->assertStringContainsString('back_to_lessons', $html);
        $this->assertStringNotContainsString('player.php', $html);
    }

    // ---------------------------------------------------------------
    // Lessons missing from the installed package (SKL-655)
    // ---------------------------------------------------------------

    private function render_list(\stdClass $skilland): string {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $lessons = [
            (object) ['id' => 10, 'title' => 'Playable', 'scoid' => 5, 'updatedat' => 0],
            (object) ['id' => 11, 'title' => 'Late', 'scoid' => null, 'updatedat' => 0],
        ];
        return skilland_render_lesson_list($skilland, $lessons, (object) ['id' => 90]);
    }

    public function test_teacher_sees_a_warning_on_a_lesson_without_sco(): void {
        $html = $this->render_list((object) ['id' => 7, 'scormcmid' => 40]);

        $this->assertSame(1, substr_count($html, 'lesson_sco_missing'));
        $this->assertStringContainsString('alert alert-warning skilland-lesson-sco-missing', $html);
        $late = substr($html, strpos($html, 'Late'));
        $this->assertStringContainsString('lesson_sco_missing', $late);
    }

    public function test_student_sees_no_warning(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:provision'];
        try {
            $html = $this->render_list((object) ['id' => 7, 'scormcmid' => 40]);
        } finally {
            unset($GLOBALS['_test_denied_capabilities']);
        }

        $this->assertStringNotContainsString('lesson_sco_missing', $html);
        $this->assertStringContainsString('skilland-lesson-disabled', $html);
    }

    public function test_unprovisioned_activity_shows_no_warning(): void {
        $html = $this->render_list((object) ['id' => 7, 'scormcmid' => null]);

        $this->assertStringNotContainsString('lesson_sco_missing', $html);
    }
}
