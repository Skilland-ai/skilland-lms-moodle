<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * One lesson code rule (SKL-694): skilland_lesson_label() gives L<topic>.<Skilland position>,
 * falling back to orderindex, and the lesson list, the player header and the navigation all use it,
 * so a lesson left out of the activity leaves the same gap the activity form shows.
 */
class lesson_label_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['DB'] = new \FakeDatabase();
        $GLOBALS['USER'] = (object) ['id' => 1];
        $GLOBALS['PAGE'] = new \test_moodle_page();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        \mod_skilland\logger::reset_cache();
    }

    /**
     * Lessons 1, 3 and 4 of a four-lesson topic, as view.php reads them (orderindex 1..3).
     *
     * @return \stdClass[]
     */
    private function lessons_with_a_gap(): array {
        return [
            10 => (object) ['id' => 10, 'title' => 'One', 'scoid' => 110, 'orderindex' => 1, 'skillandposition' => 1,
                'updatedat' => 0],
            11 => (object) ['id' => 11, 'title' => 'Three', 'scoid' => 111, 'orderindex' => 2, 'skillandposition' => 3,
                'updatedat' => 0],
            12 => (object) ['id' => 12, 'title' => 'Four', 'scoid' => 112, 'orderindex' => 3, 'skillandposition' => 4,
                'updatedat' => 0],
        ];
    }

    public function test_label_uses_the_skilland_position(): void {
        $this->assertSame('L2.3', skilland_lesson_label(2, (object) ['skillandposition' => 3, 'orderindex' => 2]));
    }

    public function test_label_falls_back_to_orderindex_when_the_position_is_unknown(): void {
        $this->assertSame('L1.2', skilland_lesson_label(1, (object) ['skillandposition' => 0, 'orderindex' => 2]));
        $this->assertSame('L1.2', skilland_lesson_label(1, (object) ['orderindex' => 2]));
    }

    public function test_label_falls_back_to_the_given_number_without_either(): void {
        $this->assertSame('L3.5', skilland_lesson_label(3, (object) [], 5));
        $this->assertSame('L3.4', skilland_lesson_label(3, (object) ['skillandposition' => 0, 'orderindex' => 0], 4));
    }

    public function test_label_reads_numeric_strings_from_the_database(): void {
        $this->assertSame('L1.4', skilland_lesson_label(1, (object) ['skillandposition' => '4', 'orderindex' => '3']));
    }

    public function test_lesson_list_shows_the_gap(): void {
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_scoes_value', false);
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_attempt', false);

        $html = skilland_render_lesson_list((object) ['id' => 7, 'scormcmid' => 40], $this->lessons_with_a_gap(),
            (object) ['id' => 2]);

        preg_match_all('/<div class="skilland-lesson-number">(L\d+\.\d+)<\/div>/', $html, $m);
        $this->assertSame(['L1.1', 'L1.3', 'L1.4'], $m[1]);
    }

    public function test_lesson_list_falls_back_to_orderindex_for_unknown_positions(): void {
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_scoes_value', false);
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_attempt', false);
        $lessons = $this->lessons_with_a_gap();
        foreach ($lessons as $lesson) {
            $lesson->skillandposition = 0;
        }

        $html = skilland_render_lesson_list((object) ['id' => 7, 'scormcmid' => 40], $lessons, (object) ['id' => 2]);

        preg_match_all('/<div class="skilland-lesson-number">(L\d+\.\d+)<\/div>/', $html, $m);
        $this->assertSame(['L1.1', 'L1.2', 'L1.3'], $m[1]);
    }

    public function test_navigation_links_show_the_gap(): void {
        $lessons = $this->lessons_with_a_gap();

        $html = skilland_render_player_navigation($lessons[11], $lessons, (object) ['id' => 2], 1,
            (object) ['scormcmid' => 40, 'hidelabels' => 0]);

        $this->assertStringContainsString('L1.1 - One', $html);
        $this->assertStringContainsString('L1.4 - Four', $html);
        $this->assertStringNotContainsString('L1.2', $html);
    }

    public function test_fullscreen_navigation_shows_the_gap(): void {
        $lessons = $this->lessons_with_a_gap();

        $html = skilland_render_fullscreen_navigation($lessons[10], $lessons, (object) ['id' => 2], 1,
            (object) ['scormcmid' => 40, 'hidelabels' => 0]);

        $this->assertStringContainsString('L1.3 - Three', $html);
    }

    public function test_player_header_shows_the_skilland_position(): void {
        $lessons = $this->lessons_with_a_gap();
        $player = new \mod_skilland\output\player((object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0],
            $lessons[12], (object) ['id' => 2], $lessons, 1, new \moodle_url('/mod/scorm/player.php', ['scoid' => 112]));

        $data = $player->export_for_template($GLOBALS['PAGE']->get_renderer('mod_skilland'));

        $this->assertSame('L1.4 - Four', $data['lessontitle']);
        $this->assertSame('L1.3 - Three', $data['navigation']['prev']['text']);
    }

    public function test_hidelabels_still_hides_the_codes(): void {
        $lessons = $this->lessons_with_a_gap();
        $player = new \mod_skilland\output\player((object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 1],
            $lessons[11], (object) ['id' => 2], $lessons, 1, new \moodle_url('/mod/scorm/player.php', ['scoid' => 111]));

        $data = $player->export_for_template($GLOBALS['PAGE']->get_renderer('mod_skilland'));

        $this->assertSame('Three', $data['lessontitle']);
        $this->assertSame('One', $data['navigation']['prev']['text']);
        $this->assertSame('Four', $data['navigation']['next']['text']);
    }

    public function test_no_renderer_builds_its_own_code(): void {
        foreach (['lesson_list', 'player', 'lesson_navigation'] as $class) {
            $source = file_get_contents(__DIR__ . '/../../src/classes/output/' . $class . '.php');
            $this->assertStringContainsString('skilland_lesson_label(', $source, $class);
            $this->assertDoesNotMatchRegularExpression("/'L'\s*\./", $source, $class);
        }
    }

    public function test_activity_form_uses_the_skilland_position(): void {
        $js = file_get_contents(__DIR__ . '/../../src/amd/src/mod_form.js');

        $this->assertStringContainsString("var lessonLabel = 'L' + currentTopicOrderIndex + '.' + lessonPosition;", $js);
        $this->assertStringContainsString('checkbox.dataset.position = String(lessonPosition);', $js);
        $this->assertStringContainsString('position: parseInt(cb.dataset.position, 10) || 0', $js);
        $this->assertStringContainsString('position: storedLesson.position || 0', $js);
    }
}
