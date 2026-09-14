<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class view_navigation_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        \mod_skilland\logger::reset_cache();
    }

    private function makeLessons(array $specs): array {
        $lessons = [];
        foreach ($specs as $i => $spec) {
            $lesson = new \stdClass();
            $lesson->id = $spec['id'];
            $lesson->title = $spec['title'];
            $lesson->scoid = $spec['scoid'] ?? null;
            $lessons[$lesson->id] = $lesson;
        }
        return $lessons;
    }

    private function makeCm(int $id = 1): \stdClass {
        $cm = new \stdClass();
        $cm->id = $id;
        return $cm;
    }

    private function makeSkilland(int $scormcmid = 100, int $hidelabels = 0): \stdClass {
        $skilland = new \stdClass();
        $skilland->scormcmid = $scormcmid;
        $skilland->hidelabels = $hidelabels;
        return $skilland;
    }

    // ---------------------------------------------------------------
    // skilland_render_player_navigation() — unknown lesson
    // ---------------------------------------------------------------

    public function test_unknown_lesson_returns_empty(): void {
        $unknown = new \stdClass();
        $unknown->id = 999;

        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'A', 'scoid' => 10],
        ]);

        $html = skilland_render_player_navigation($unknown, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        $this->assertEquals('', $html);
    }

    // ---------------------------------------------------------------
    // First / Last / Middle lesson navigation
    // ---------------------------------------------------------------

    public function test_first_lesson_no_prev_has_next(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Prev should be empty span, next should be a link.
        $this->assertStringContainsString('skilland-nav-prev', $html);
        $this->assertStringContainsString('skilland-nav-next', $html);
        // Should not contain prev link (no href in the prev area).
        $this->assertStringNotContainsString('← L1.', $html);
        // Should contain next link with L1.2.
        $this->assertStringContainsString('L1.2', $html);
    }

    public function test_last_lesson_has_prev_no_next(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Should contain prev link with L1.1.
        $this->assertStringContainsString('L1.1', $html);
        $this->assertStringContainsString('← L1.', $html);
        // Next should be empty span.
        $this->assertDoesNotMatchRegularExpression('/L1\.3/', $html);
    }

    public function test_middle_lesson_has_both_prev_and_next(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Middle', 'scoid' => 20],
            ['id' => 3, 'title' => 'Last', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Should contain both prev (L1.1) and next (L1.3).
        $this->assertStringContainsString('L1.1', $html);
        $this->assertStringContainsString('L1.3', $html);
    }

    // ---------------------------------------------------------------
    // Label format
    // ---------------------------------------------------------------

    public function test_label_format_uses_topic_orderindex(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 1];

        // Topic order index = 3.
        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 3, $this->makeSkilland());

        // Next label should be L3.2 (topicorderindex.lessonindex).
        $this->assertStringContainsString('L3.2', $html);
    }

    // ---------------------------------------------------------------
    // Lesson without scoid
    // ---------------------------------------------------------------

    public function test_lesson_without_scoid_shows_empty_span(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'No SCO', 'scoid' => null],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Next lesson has no scoid, so should be empty span.
        $this->assertStringNotContainsString('No SCO', $html);
        $this->assertStringContainsString('<span', $html);
    }

    // ---------------------------------------------------------------
    // Missing scormcmid
    // ---------------------------------------------------------------

    public function test_no_scormcmid_means_no_links(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland(0));

        // With scormcmid=0 (empty), next should be empty span, not a link.
        $this->assertStringNotContainsString('<a ', $html);
    }

    // ---------------------------------------------------------------
    // Single lesson
    // ---------------------------------------------------------------

    public function test_single_lesson_no_prev_no_next(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'Only One', 'scoid' => 10],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Should have nav wrapper but no link elements.
        $this->assertStringContainsString('skilland-player-nav', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    // ===============================================================
    // skilland_render_fullscreen_navigation()
    // ===============================================================

    public function test_fullscreen_unknown_lesson_returns_empty(): void {
        $unknown = (object)['id' => 999];
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'A', 'scoid' => 10],
        ]);

        $html = skilland_render_fullscreen_navigation($unknown, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        $this->assertEquals('', $html);
    }

    public function test_fullscreen_first_lesson_prev_disabled(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Prev should be disabled, next should be a link.
        $this->assertStringContainsString('skilland-fullscreen-nav-disabled', $html);
        $this->assertStringContainsString('L1.2', $html);
        $this->assertStringContainsString('Second', $html);
    }

    public function test_fullscreen_last_lesson_next_disabled(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Prev label uses currentindex (0-based), so L1.1 for first lesson.
        $this->assertStringContainsString('L1.1', $html);
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('skilland-fullscreen-nav-disabled', $html);
    }

    public function test_fullscreen_middle_lesson_both_links(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Middle', 'scoid' => 20],
            ['id' => 3, 'title' => 'Third', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Third', $html);
        $this->assertStringContainsString('←', $html);
        $this->assertStringContainsString('→', $html);
    }

    public function test_fullscreen_prev_without_scoid_disabled(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'No SCO', 'scoid' => null],
            ['id' => 2, 'title' => 'Current', 'scoid' => 20],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Prev has no scoid, should be disabled span.
        $this->assertStringNotContainsString('No SCO', $html);
        $this->assertStringContainsString('skilland-fullscreen-nav-disabled', $html);
    }

    public function test_fullscreen_single_lesson_both_disabled(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'Only', 'scoid' => 10],
        ]);
        $current = (object)['id' => 1];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland());

        // Both prev and next should be disabled.
        $this->assertStringContainsString('skilland-fullscreen-nav', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    // ===============================================================
    // skilland_render_lesson_list()
    // ===============================================================

    public function test_lesson_list_empty_shows_alert(): void {
        $GLOBALS['USER'] = (object)['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();

        $skilland = (object)['id' => 1, 'scormcmid' => 100];
        $html = skilland_render_lesson_list($skilland, [], $this->makeCm());

        $this->assertStringContainsString('alert', $html);
        $this->assertStringContainsString('no_lessons_configured', $html);
    }

    public function test_lesson_list_renders_playable_lesson_as_link(): void {
        $db = new \FakeDatabase();
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object)['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        // Stub: no scorm tracking tables.
        $db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $db->get_manager()->set_table_exists('scorm_attempt', false);

        $skilland = (object)['id' => 1, 'scormcmid' => 100];
        $lessons = [
            (object)['id' => 10, 'title' => 'Test Lesson', 'scoid' => 5, 'updatedat' => 0],
        ];

        $html = skilland_render_lesson_list($skilland, $lessons, $this->makeCm(), 2);

        // Should render as <a> link (playable).
        $this->assertStringContainsString('<a ', $html);
        $this->assertStringContainsString('Test Lesson', $html);
        $this->assertStringContainsString('L2.1', $html);
    }

    public function test_lesson_list_renders_non_playable_lesson_as_div(): void {
        $db = new \FakeDatabase();
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object)['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        $db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $db->get_manager()->set_table_exists('scorm_attempt', false);

        $skilland = (object)['id' => 1, 'scormcmid' => 100];
        $lessons = [
            (object)['id' => 10, 'title' => 'Pending', 'scoid' => null, 'updatedat' => 0],
        ];

        $html = skilland_render_lesson_list($skilland, $lessons, $this->makeCm());

        // Should render as div (not playable — no scoid).
        $this->assertStringContainsString('skilland-lesson-disabled', $html);
        $this->assertStringContainsString('Pending', $html);
    }

    // ===============================================================
    // hidelabels — codes hidden when setting is enabled
    // ===============================================================

    public function test_lesson_list_hides_number_badge_when_hidelabels(): void {
        $db = new \FakeDatabase();
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object)['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        $db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $db->get_manager()->set_table_exists('scorm_attempt', false);

        $skilland = (object)['id' => 1, 'scormcmid' => 100, 'hidelabels' => 1];
        $lessons = [
            (object)['id' => 10, 'title' => 'Test Lesson', 'scoid' => 5, 'updatedat' => 0],
        ];

        $html = skilland_render_lesson_list($skilland, $lessons, $this->makeCm(), 2);

        // Title should still appear, but L2.1 badge should not.
        $this->assertStringContainsString('Test Lesson', $html);
        $this->assertStringNotContainsString('L2.1', $html);
        $this->assertStringNotContainsString('skilland-lesson-number', $html);
    }

    public function test_lesson_list_shows_number_badge_when_hidelabels_off(): void {
        $db = new \FakeDatabase();
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object)['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        $db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $db->get_manager()->set_table_exists('scorm_attempt', false);

        $skilland = (object)['id' => 1, 'scormcmid' => 100, 'hidelabels' => 0];
        $lessons = [
            (object)['id' => 10, 'title' => 'Test Lesson', 'scoid' => 5, 'updatedat' => 0],
        ];

        $html = skilland_render_lesson_list($skilland, $lessons, $this->makeCm(), 2);

        // Both badge and title should appear.
        $this->assertStringContainsString('L2.1', $html);
        $this->assertStringContainsString('skilland-lesson-number', $html);
        $this->assertStringContainsString('Test Lesson', $html);
    }

    public function test_player_nav_hides_labels_when_hidelabels(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
            ['id' => 3, 'title' => 'Third', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland(100, 1));

        // Titles should appear, but L1.x labels should not.
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Third', $html);
        $this->assertDoesNotMatchRegularExpression('/L\d+\.\d+/', $html);
    }

    public function test_player_nav_shows_labels_when_hidelabels_off(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Second', 'scoid' => 20],
            ['id' => 3, 'title' => 'Third', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_player_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland(100, 0));

        // Labels should appear alongside titles.
        $this->assertStringContainsString('L1.1', $html);
        $this->assertStringContainsString('L1.3', $html);
    }

    public function test_fullscreen_nav_hides_labels_when_hidelabels(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Middle', 'scoid' => 20],
            ['id' => 3, 'title' => 'Last', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland(100, 1));

        // Titles should appear, labels should not.
        $this->assertStringContainsString('First', $html);
        $this->assertStringContainsString('Last', $html);
        $this->assertDoesNotMatchRegularExpression('/L\d+\.\d+/', $html);
    }

    public function test_fullscreen_nav_shows_labels_when_hidelabels_off(): void {
        $lessons = $this->makeLessons([
            ['id' => 1, 'title' => 'First', 'scoid' => 10],
            ['id' => 2, 'title' => 'Middle', 'scoid' => 20],
            ['id' => 3, 'title' => 'Last', 'scoid' => 30],
        ]);
        $current = (object)['id' => 2];

        $html = skilland_render_fullscreen_navigation($current, $lessons, $this->makeCm(), 1, $this->makeSkilland(100, 0));

        // Labels should appear.
        $this->assertStringContainsString('L1.1', $html);
        $this->assertStringContainsString('L1.3', $html);
    }
}
