<?php

namespace mod_skilland\tests;

use mod_skilland\completion\custom_completion;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * The "complete all lessons" custom completion rule (SKL-668).
 */
class custom_completion_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        \mod_skilland\logger::reset_cache();

        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'visible' => 1],
            (object) ['id' => 2, 'skillandid' => 7, 'visible' => 1],
            (object) ['id' => 3, 'skillandid' => 7, 'visible' => 0],
            (object) ['id' => 4, 'skillandid' => 8, 'visible' => 1],
        ]);
    }

    private function completion(int $userid = 50): custom_completion {
        $cm = new \cm_info(90, 7, 3);
        $cm->customdata = ['customcompletionrules' => ['completionlessons' => 1]];
        return new custom_completion($cm, $userid);
    }

    private function progress(array $statuses, int $userid = 50): void {
        $rows = [];
        foreach ($statuses as $lessonid => $status) {
            $rows[] = (object) ['id' => count($rows) + 1, 'skillandid' => 7, 'lessonid' => $lessonid,
                'userid' => $userid, 'status' => $status, 'score' => null];
        }
        $this->db->seed('skilland_progress', $rows);
    }

    public function test_all_visible_lessons_completed_or_passed_is_complete(): void {
        $this->progress([1 => 'completed', 2 => 'passed']);

        $this->assertSame(COMPLETION_COMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_one_visible_lesson_missing_is_incomplete(): void {
        $this->progress([1 => 'completed']);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_a_failed_lesson_is_incomplete(): void {
        $this->progress([1 => 'completed', 2 => 'failed']);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_an_incomplete_lesson_is_incomplete(): void {
        $this->progress([1 => 'completed', 2 => 'incomplete']);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_hidden_lessons_are_ignored(): void {
        // Lesson 3 is hidden and never started.
        $this->progress([1 => 'passed', 2 => 'completed']);

        $this->assertSame(COMPLETION_COMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_zero_visible_lessons_is_incomplete(): void {
        $this->db->set_field('skilland_lesson', 'visible', 0, ['skillandid' => 7]);
        $this->progress([1 => 'completed', 2 => 'completed', 3 => 'completed']);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion()->get_state('completionlessons'));
    }

    public function test_another_users_progress_does_not_count(): void {
        $this->progress([1 => 'completed', 2 => 'completed'], 51);

        $this->assertSame(COMPLETION_INCOMPLETE, $this->completion(50)->get_state('completionlessons'));
        $this->assertSame(COMPLETION_COMPLETE, $this->completion(51)->get_state('completionlessons'));
    }

    public function test_unknown_rule_throws(): void {
        $this->expectException(\coding_exception::class);
        $this->completion()->get_state('completionunknown');
    }

    public function test_defines_the_completionlessons_rule(): void {
        $this->assertSame(['completionlessons'], custom_completion::get_defined_custom_rules());
        $this->assertSame(['completionlessons'], $this->completion()->get_available_custom_rules());
    }

    public function test_rule_description_is_present(): void {
        $descriptions = $this->completion()->get_custom_rule_descriptions();

        $this->assertSame(['completionlessons' => 'completiondetail:lessons'], $descriptions);
    }

    public function test_sort_order_lists_the_rule_after_view(): void {
        $order = $this->completion()->get_sort_order();

        $this->assertContains('completionlessons', $order);
        $this->assertLessThan(array_search('completionlessons', $order), array_search('completionview', $order));
    }
}
