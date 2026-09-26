<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * view.php plays only visible lessons of this activity through ?play= (SKL-675).
 */
class view_play_lesson_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['DB'] = new \FakeDatabase();
        $GLOBALS['USER'] = (object) ['id' => 1];
        $GLOBALS['OUTPUT'] = new \stdClass();
        $GLOBALS['PAGE'] = new \stdClass();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        parent::tearDown();
    }

    /**
     * Visible lessons keyed by id, as view.php loads them.
     *
     * @param int[] $ids
     * @return array
     */
    private function visible_lessons(array $ids): array {
        $lessons = [];
        foreach ($ids as $id) {
            $lessons[$id] = (object) ['id' => $id, 'title' => 'Lesson ' . $id, 'scoid' => $id + 100];
        }
        return $lessons;
    }

    public function test_find_returns_a_visible_lesson(): void {
        $lessons = $this->visible_lessons([10, 20, 30]);

        $this->assertSame($lessons[20], skilland_find_visible_lesson($lessons, 20));
    }

    public function test_find_returns_null_for_a_hidden_lesson(): void {
        // Lesson 20 is hidden, so view.php never loaded it into the visible set.
        $lessons = $this->visible_lessons([10, 30]);

        $this->assertNull(skilland_find_visible_lesson($lessons, 20));
    }

    public function test_find_returns_null_for_another_instances_lesson(): void {
        $lessons = $this->visible_lessons([10, 20, 30]);

        $this->assertNull(skilland_find_visible_lesson($lessons, 999));
    }

    public function test_find_returns_null_for_zero(): void {
        $this->assertNull(skilland_find_visible_lesson($this->visible_lessons([10]), 0));
    }

    public function test_find_returns_null_for_no_lessons(): void {
        $this->assertNull(skilland_find_visible_lesson([], 10));
    }

    public function test_player_with_a_lesson_outside_the_visible_set_shows_not_ready(): void {
        $skilland = (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0];
        $hidden = (object) ['id' => 20, 'title' => 'Hidden', 'scoid' => 120];
        // A live SCORM module: only the visibility guard can stop the player from rendering.
        $GLOBALS['_test_get_coursemodule_from_id'] = (object) ['id' => 40, 'instance' => 5, 'course' => 3];

        $html = skilland_render_player_view($skilland, $hidden, (object) ['id' => 1],
            $this->visible_lessons([10, 30]), 1);

        $this->assertStringContainsString('scorm_not_ready', $html);
        $this->assertStringContainsString('back_to_lessons', $html);
        $this->assertStringNotContainsString('L1.', $html);
        $this->assertStringNotContainsString('player.php', $html);
    }

    public function test_view_play_branch_uses_visible_lookup_and_warns(): void {
        $source = file_get_contents(__DIR__ . '/../../src/view.php');
        $detect = strpos($source, '$scormmissing = skilland_detect_missing_scorm($skilland);');
        $play = strpos($source, 'if ($play > 0)');
        $this->assertNotFalse($play);
        $this->assertLessThan($play, $detect);
        $branch = substr($source, $play, 800);
        $this->assertStringContainsString('skilland_find_visible_lesson($lessons, $play)', $branch);
        $this->assertStringContainsString("get_string('lesson_not_available', 'mod_skilland')", $branch);
        $this->assertStringContainsString('NOTIFY_WARNING', $branch);
        $this->assertStringNotContainsString("get_record('skilland_lesson'", $branch);
    }
}
