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
 * The lesson list renderable and its template, rendered by the real Moodle output stack.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\output\lesson_list
 * @covers     \mod_skilland\output\renderer
 */
final class lesson_list_test extends \advanced_testcase {

    /**
     * A list of one completed playable lesson and one lesson missing from the package.
     *
     * @return lesson_list
     */
    private function lesson_list(): lesson_list {
        $lessons = [
            10 => (object) ['id' => 10, 'title' => 'First', 'scoid' => 110, 'updatedat' => 0],
            11 => (object) ['id' => 11, 'title' => 'Second', 'scoid' => null, 'updatedat' => 0],
        ];
        return new lesson_list((object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0], $lessons,
            (object) ['id' => 2], 3, [10 => ['status' => 'passed', 'score' => 80.0]], true);
    }

    public function test_export_for_template_maps_progress_and_playability(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());

        $data = $this->lesson_list()->export_for_template($PAGE->get_renderer('mod_skilland'));

        $this->assertTrue($data['haslessons']);
        [$first, $second] = $data['lessons'];
        $this->assertTrue($first['playable']);
        $this->assertSame((new \moodle_url('/mod/skilland/view.php', ['id' => 2, 'play' => 10]))->out(false),
            $first['url']);
        $this->assertSame('L3.1', $first['label']);
        $this->assertSame('skilland-lesson-completed', $first['completionclass']);
        $this->assertSame(get_string('completed', 'mod_skilland'), $first['statustext']);
        $this->assertSame(get_string('score', 'mod_skilland') . ': 80%', $first['meta']);
        $this->assertFalse($first['scomissing']);
        $this->assertFalse($second['playable']);
        $this->assertSame('skilland-lesson-pending', $second['completionclass']);
        $this->assertTrue($second['scomissing']);
    }

    public function test_renderer_renders_the_lesson_list_template(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());

        $html = $PAGE->get_renderer('mod_skilland')->render($this->lesson_list());

        $this->assertStringContainsString('class="skilland-lesson-card skilland-lesson-completed"', $html);
        $this->assertStringContainsString('class="skilland-lesson-card skilland-lesson-disabled skilland-lesson-pending"',
            $html);
        $this->assertStringContainsString('<div class="skilland-lesson-number">L3.2</div>', $html);
        $this->assertStringContainsString(get_string('lessons', 'mod_skilland'), $html);
        $this->assertStringContainsString(get_string('lesson_sco_missing', 'mod_skilland'), $html);
    }

    public function test_empty_list_renders_the_notice(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_context(\context_system::instance());

        $html = $PAGE->get_renderer('mod_skilland')->render(
            new lesson_list((object) ['id' => 7, 'scormcmid' => 40], [], (object) ['id' => 2]));

        $this->assertStringContainsString(get_string('no_lessons_configured', 'mod_skilland'), $html);
        $this->assertStringNotContainsString('skilland-lessons-container', $html);
    }
}
