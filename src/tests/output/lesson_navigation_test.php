<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_skilland\output;

/**
 * The previous / next lesson renderable and its two templates, rendered by the real Moodle output stack.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\output\lesson_navigation
 * @covers     \mod_skilland\output\renderer
 */
final class lesson_navigation_test extends \advanced_testcase {
    /**
     * Three lessons; the middle one has no SCO in the package.
     *
     * @return \stdClass[]
     */
    private function lessons(): array {
        return [
            10 => (object) ['id' => 10, 'title' => 'First', 'scoid' => 110],
            11 => (object) ['id' => 11, 'title' => 'Second', 'scoid' => null],
            12 => (object) ['id' => 12, 'title' => 'Third', 'scoid' => 112],
        ];
    }

    /**
     * The navigation around one of the three lessons.
     *
     * @param \stdClass $current The lesson being played.
     * @param string $style One of the lesson_navigation STYLE_ constants.
     * @return lesson_navigation
     */
    private function navigation(\stdClass $current, string $style = lesson_navigation::STYLE_FULLSCREEN): lesson_navigation {
        return new lesson_navigation(
            $current,
            $this->lessons(),
            (object) ['id' => 2],
            1,
            (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0],
            $style
        );
    }

    /**
     * The mod_skilland renderer, on a page with a context.
     *
     * @return renderer
     */
    private function renderer(): renderer {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('mod_skilland');
    }

    public function test_position_of_finds_a_lesson_by_id(): void {
        $lessons = $this->lessons();

        $this->assertSame(0, lesson_navigation::position_of($lessons[10], $lessons));
        $this->assertSame(2, lesson_navigation::position_of((object) ['id' => '12'], $lessons));
        $this->assertNull(lesson_navigation::position_of((object) ['id' => 99], $lessons));
    }

    public function test_export_links_only_playable_neighbours(): void {
        $this->resetAfterTest();
        $lessons = $this->lessons();

        $first = $this->navigation($lessons[10])->export_for_template($this->renderer());
        $this->assertFalse($first['prev']);
        // The next lesson has no SCO in the package: nothing to link.
        $this->assertFalse($first['next']);

        $last = $this->navigation($lessons[12])->export_for_template($this->renderer());
        $this->assertFalse($last['prev']);
        $this->assertFalse($last['next']);
    }

    public function test_export_link_carries_url_text_and_accessible_name(): void {
        $this->resetAfterTest();
        $lessons = $this->lessons();
        $lessons[11]->scoid = 111;

        $navigation = new lesson_navigation(
            $lessons[11],
            $lessons,
            (object) ['id' => 2],
            4,
            (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0]
        );
        $data = $navigation->export_for_template($this->renderer());

        $this->assertSame([
            'url' => (new \moodle_url('/mod/skilland/view.php', ['id' => 2, 'play' => 10]))->out(false),
            'text' => 'L4.1 - First',
            'arialabel' => get_string('aria_previous_lesson', 'mod_skilland', 'L4.1 - First'),
        ], $data['prev']);
        $this->assertSame('L4.3 - Third', $data['next']['text']);
    }

    public function test_no_scorm_means_no_links(): void {
        $this->resetAfterTest();
        $lessons = $this->lessons();

        $navigation = new lesson_navigation(
            $lessons[10],
            [$lessons[10], $lessons[12]],
            (object) ['id' => 2],
            1,
            (object) ['id' => 7, 'scormcmid' => 0, 'hidelabels' => 0]
        );

        $data = $navigation->export_for_template($this->renderer());
        $this->assertFalse($data['prev']);
        $this->assertFalse($data['next']);
        $this->assertSame(get_string('back_to_lessons', 'mod_skilland'), $data['end']['text']);
    }

    public function test_unknown_current_lesson_renders_nothing(): void {
        $this->resetAfterTest();

        $navigation = $this->navigation((object) ['id' => 99]);

        $this->assertFalse($navigation->has_current());
        $this->assertSame(
            ['prev' => false, 'next' => false, 'end' => false],
            $navigation->export_for_template($this->renderer())
        );
        $this->assertSame('', $this->renderer()->render($navigation));
    }

    public function test_renderer_uses_the_fullscreen_template(): void {
        $this->resetAfterTest();
        $lessons = $this->lessons();

        $fullscreen = $this->renderer()->render($this->navigation($lessons[12]));
        $this->assertStringContainsString('class="skilland-fullscreen-nav"', $fullscreen);
        $this->assertStringContainsString(get_string('no_previous_lesson', 'mod_skilland'), $fullscreen);
        $this->assertStringNotContainsString(get_string('no_next_lesson', 'mod_skilland'), $fullscreen);
        $this->assertStringContainsString('skilland-fullscreen-nav-end', $fullscreen);
        $this->assertStringContainsString(get_string('back_to_lessons', 'mod_skilland'), $fullscreen);
    }

    public function test_end_link_finishes_the_topic_once_every_lesson_is_complete(): void {
        $this->resetAfterTest();
        $lessons = $this->lessons();
        $lessons[11]->scoid = 111;
        $done = ['status' => 'completed', 'score' => null];

        $navigation = new lesson_navigation(
            $lessons[12],
            $lessons,
            (object) ['id' => 2, 'course' => 5],
            1,
            (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0],
            lesson_navigation::STYLE_FULLSCREEN,
            [10 => $done, 11 => $done, 12 => $done]
        );
        $data = $navigation->export_for_template($this->renderer());

        $this->assertFalse($data['next']);
        $this->assertSame(get_string('finish_topic', 'mod_skilland'), $data['end']['text']);
        $this->assertSame((new \moodle_url('/course/view.php', ['id' => 5]))->out(false), $data['end']['url']);
    }
}
