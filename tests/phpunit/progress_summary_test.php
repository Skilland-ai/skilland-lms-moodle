<?php

namespace mod_skilland\tests;

use mod_skilland\completion\custom_completion;
use mod_skilland\local\progress_summary;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * The learner progress summary and the Continue target of the lesson list (SKL-695).
 */
class progress_summary_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['DB'] = new \FakeDatabase();
        $GLOBALS['USER'] = (object) ['id' => 50];
        $GLOBALS['PAGE'] = new \test_moodle_page();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_get_coursemodule_from_id'], $GLOBALS['_test_get_coursemodule_from_instance']);
        \mod_skilland\logger::reset_cache();
    }

    private function skilland(): \stdClass {
        return (object) ['id' => 10, 'scormcmid' => 100, 'hidelabels' => 0];
    }

    private function lessons(): array {
        return [
            1 => (object) ['id' => 1, 'title' => 'One', 'scoid' => 11, 'updatedat' => 0],
            2 => (object) ['id' => 2, 'title' => 'Two', 'scoid' => 12, 'updatedat' => 0],
            3 => (object) ['id' => 3, 'title' => 'Three', 'scoid' => 13, 'updatedat' => 0],
        ];
    }

    private function progress(array $statuses): array {
        $progress = [];
        foreach ($statuses as $id => $status) {
            $progress[$id] = ['status' => $status, 'score' => null];
        }
        return $progress;
    }

    private function summary(array $statuses, ?array $lessons = null): progress_summary {
        return new progress_summary($this->skilland(), $lessons ?? $this->lessons(), $this->progress($statuses));
    }

    public function test_counts_completed_and_passed_only(): void {
        $summary = $this->summary([1 => 'completed', 2 => 'passed', 3 => 'failed']);

        $this->assertSame(3, $summary->total());
        $this->assertSame(2, $summary->completed());
        $this->assertFalse($summary->all_complete());
    }

    public function test_nothing_started_targets_the_first_lesson_with_start(): void {
        $target = $this->summary([])->target();

        $this->assertSame(1, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_START, $target['mode']);
    }

    public function test_partly_done_targets_the_first_in_progress_lesson(): void {
        $target = $this->summary([1 => 'completed', 3 => 'incomplete'])->target();

        $this->assertSame(3, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_CONTINUE, $target['mode']);
    }

    public function test_partly_done_without_one_in_progress_targets_the_first_not_started(): void {
        $target = $this->summary([1 => 'completed'])->target();

        $this->assertSame(2, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_CONTINUE, $target['mode']);
    }

    public function test_browsed_counts_as_in_progress(): void {
        $target = $this->summary([2 => 'browsed'])->target();

        $this->assertSame(2, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_CONTINUE, $target['mode']);
    }

    public function test_a_failed_lesson_is_in_progress_not_done(): void {
        $summary = $this->summary([1 => 'completed', 2 => 'failed']);
        $target = $summary->target();

        $this->assertSame(1, $summary->completed());
        $this->assertSame(2, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_CONTINUE, $target['mode']);
    }

    public function test_the_first_lesson_in_order_wins_over_a_later_one(): void {
        $target = $this->summary([2 => 'failed', 3 => 'incomplete'])->target();

        $this->assertSame(2, $target['lesson']->id);
    }

    public function test_all_complete_reviews_from_the_first_lesson(): void {
        $summary = $this->summary([1 => 'completed', 2 => 'passed', 3 => 'completed']);
        $target = $summary->target();

        $this->assertTrue($summary->all_complete());
        $this->assertSame(1, $target['lesson']->id);
        $this->assertSame(progress_summary::MODE_REVIEW, $target['mode']);
    }

    public function test_an_unplayable_lesson_is_not_counted_or_targeted(): void {
        $lessons = $this->lessons();
        $lessons[1]->scoid = null;

        $summary = $this->summary([], $lessons);

        $this->assertSame(2, $summary->total());
        $this->assertSame(2, $summary->target()['lesson']->id);
        $this->assertNull($summary->position_of($lessons[1]));
        $this->assertSame(1, $summary->position_of($lessons[2]));
        $this->assertSame(2, $summary->position_of($lessons[3]));
    }

    public function test_an_activity_without_a_scorm_package_has_no_playable_lessons(): void {
        $summary = new progress_summary((object) ['id' => 10, 'scormcmid' => null], $this->lessons(), []);

        $this->assertSame(0, $summary->total());
        $this->assertSame([], $summary->target());
        $this->assertFalse($summary->all_complete());
    }

    public function test_no_lessons_has_no_target(): void {
        $this->assertSame([], $this->summary([], [])->target());
    }

    public function test_the_list_exports_the_summary_and_marks_the_target_card(): void {
        $list = new \mod_skilland\output\lesson_list(
            $this->skilland(),
            $this->lessons(),
            (object) ['id' => 90],
            1,
            $this->progress([1 => 'completed'])
        );

        $data = $list->export_for_template($GLOBALS['PAGE']->get_renderer('mod_skilland'));

        $this->assertTrue($data['hassummary']);
        $this->assertSame(1, $data['completedcount']);
        $this->assertSame(3, $data['totalcount']);
        $this->assertSame(33, $data['percent']);
        $this->assertSame('continue_lesson', $data['continuetext']);
        $this->assertStringContainsString('id=90', $data['continueurl']);
        $this->assertStringContainsString('play=2', $data['continueurl']);
        $this->assertSame([false, true, false], array_column($data['lessons'], 'istarget'));
    }

    public function test_the_rendered_list_has_the_progress_bar_button_and_aria_current(): void {
        $html = $GLOBALS['PAGE']->get_renderer('mod_skilland')->render(new \mod_skilland\output\lesson_list(
            $this->skilland(),
            $this->lessons(),
            (object) ['id' => 90],
            1,
            $this->progress([1 => 'completed'])
        ));

        $this->assertStringContainsString('role="progressbar"', $html);
        $this->assertStringContainsString('skilland-progress-33', $html);
        $this->assertStringNotContainsString('style=', $html);
        $this->assertStringContainsString('aria-valuenow="1"', $html);
        $this->assertStringContainsString('aria-valuemax="3"', $html);
        $this->assertStringContainsString('aria-valuemin="0"', $html);
        $this->assertStringContainsString('skilland-continue-button', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="step"'));
    }

    public function test_the_summary_is_hidden_without_playable_lessons(): void {
        $lessons = $this->lessons();
        foreach ($lessons as $lesson) {
            $lesson->scoid = null;
        }
        $html = $GLOBALS['PAGE']->get_renderer('mod_skilland')->render(new \mod_skilland\output\lesson_list(
            $this->skilland(),
            $lessons,
            (object) ['id' => 90]
        ));

        $this->assertStringNotContainsString('skilland-progress-summary', $html);
        $this->assertStringNotContainsString('skilland-continue-button', $html);
        $this->assertStringNotContainsString('aria-current', $html);
    }

    public static function completion_cases(): array {
        return [
            'all completed or passed' => [['completed', 'passed', 'completed']],
            'one failed' => [['completed', 'failed', 'completed']],
            'one in progress' => [['completed', 'incomplete', 'completed']],
            'one browsed' => [['browsed', 'completed', 'completed']],
            'one not started' => [['completed', 'completed']],
        ];
    }

    /**
     * @dataProvider completion_cases
     */
    public function test_reaching_the_total_matches_custom_completion(array $statuses): void {
        global $DB;
        $lessonrows = [];
        $progressrows = [];
        foreach ([1, 2, 3] as $id) {
            $lessonrows[] = (object) ['id' => $id, 'skillandid' => 7, 'visible' => 1, 'scoid' => 10 + $id];
        }
        foreach ($statuses as $i => $status) {
            $progressrows[] = (object) ['id' => $i + 1, 'skillandid' => 7, 'lessonid' => $i + 1, 'userid' => 50,
                'status' => $status, 'score' => null];
        }
        $DB->seed('skilland_lesson', $lessonrows);
        $DB->seed('skilland_progress', $progressrows);

        $cm = new \cm_info(90, 7, 3);
        $cm->customdata = ['customcompletionrules' => ['completionlessons' => 1]];
        $state = (new custom_completion($cm, 50))->get_state('completionlessons');

        $stored = skilland_get_user_progress(7, 50);
        $summary = new progress_summary(
            (object) ['id' => 7, 'scormcmid' => 100],
            array_combine([1, 2, 3], $lessonrows),
            $stored
        );

        $this->assertSame($state === COMPLETION_COMPLETE, $summary->all_complete());
    }
}
