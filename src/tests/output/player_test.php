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
 * The fullscreen player renderable and its template, rendered by the real Moodle output stack.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\output\player
 * @covers     \mod_skilland\output\renderer
 */
final class player_test extends \advanced_testcase {
    /**
     * Three playable lessons of one activity.
     *
     * @return \stdClass[]
     */
    private function lessons(): array {
        return [
            10 => (object) ['id' => 10, 'title' => 'First', 'scoid' => 110],
            11 => (object) ['id' => 11, 'title' => 'Second', 'scoid' => 111],
            12 => (object) ['id' => 12, 'title' => 'Third', 'scoid' => 112],
        ];
    }

    /**
     * A player of a lesson of the three.
     *
     * @param int $lessonid The lesson to play.
     * @param bool $ready Whether the lesson has a SCORM player url.
     * @param int $hidelabels The activity's hidelabels setting.
     * @return player
     */
    private function player(int $lessonid, bool $ready = true, int $hidelabels = 0): player {
        $lessons = $this->lessons();
        $playerurl = $ready ? new \moodle_url('/mod/scorm/player.php', ['scoid' => $lessons[$lessonid]->scoid, 'cm' => 40]) : null;
        return new player(
            (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => $hidelabels],
            $lessons[$lessonid],
            (object) ['id' => 2],
            $lessons,
            2,
            $playerurl
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

    public function test_export_for_template_of_a_playable_lesson(): void {
        $this->resetAfterTest();

        $data = $this->player(11)->export_for_template($this->renderer());

        $this->assertFalse($data['notready']);
        $this->assertSame((new \moodle_url('/mod/skilland/view.php', ['id' => 2]))->out(false), $data['backurl']);
        $this->assertSame('L2.2 - Second', $data['lessontitle']);
        $this->assertSame('Second', $data['iframetitle']);
        $this->assertSame(
            (new \moodle_url('/mod/scorm/player.php', ['scoid' => 111, 'cm' => 40]))->out(false),
            $data['playerurl']
        );
        $this->assertSame('L2.1 - First', $data['navigation']['prev']['text']);
        $this->assertSame('L2.3 - Third', $data['navigation']['next']['text']);
        $this->assertSame(
            get_string('aria_next_lesson', 'mod_skilland', 'L2.3 - Third'),
            $data['navigation']['next']['arialabel']
        );
    }

    public function test_export_for_template_drops_the_label_when_hidelabels(): void {
        $this->resetAfterTest();

        $data = $this->player(10, true, 1)->export_for_template($this->renderer());

        $this->assertSame('First', $data['lessontitle']);
        $this->assertFalse($data['navigation']['prev']);
        $this->assertSame('Second', $data['navigation']['next']['text']);
    }

    public function test_export_for_template_of_a_lesson_that_cannot_be_played(): void {
        $this->resetAfterTest();

        $data = $this->player(10, false)->export_for_template($this->renderer());

        $this->assertSame(
            ['notready' => true, 'backurl' => (new \moodle_url('/mod/skilland/view.php', ['id' => 2]))->out(false)],
            $data
        );
    }

    public function test_lesson_outside_the_list_has_no_navigation(): void {
        $this->resetAfterTest();
        $stray = (object) ['id' => 99, 'title' => 'Stray', 'scoid' => 199];

        $player = new player(
            (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0],
            $stray,
            (object) ['id' => 2],
            $this->lessons(),
            2,
            new \moodle_url('/mod/scorm/player.php', ['scoid' => 199])
        );
        $data = $player->export_for_template($this->renderer());

        $this->assertFalse($data['navigation']);
        $this->assertSame('L2.4 - Stray', $data['lessontitle']);
    }

    public function test_renderer_renders_the_player_template(): void {
        $this->resetAfterTest();

        $html = $this->renderer()->render($this->player(11));

        $this->assertStringContainsString('id="skilland-fullscreen-wrapper" data-fullscreen="true"', $html);
        $this->assertStringContainsString('class="skilland-fullscreen-iframe"', $html);
        $this->assertStringContainsString('<h2 class="skilland-fullscreen-title" id="skilland-fullscreen-title" tabindex="-1">' .
            'L2.2 - Second</h2>', $html);
        $this->assertStringContainsString('class="skilland-fullscreen-nav-prev"', $html);
        $this->assertStringContainsString('class="skilland-fullscreen-nav-next"', $html);
        $this->assertStringContainsString(get_string('back_to_lessons', 'mod_skilland'), $html);
    }

    public function test_renderer_renders_the_not_ready_notice(): void {
        $this->resetAfterTest();

        $html = $this->renderer()->render($this->player(10, false));

        $this->assertStringContainsString(get_string('scorm_not_ready', 'mod_skilland'), $html);
        $this->assertStringNotContainsString('skilland-fullscreen-wrapper', $html);
        $this->assertStringNotContainsString('<iframe', $html);
    }
}
