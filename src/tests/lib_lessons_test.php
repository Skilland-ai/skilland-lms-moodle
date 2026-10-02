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

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/skilland/tests/skilland_testcase.php');

/**
 * Guards for lesson timestamps and titles received from Skilland (SKL-684).
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::skilland_process_selected_lessons
 * @covers     ::skilland_add_instance
 */
final class lib_lessons_test extends skilland_testcase {
    public function test_unparseable_updatedat_is_stored_as_zero(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['selected_lessons' => '']);
        skilland_process_selected_lessons($skilland->id, json_encode([
            'bad' => ['name' => 'Bad date', 'updatedAt' => 'not a date'],
        ]));

        $this->assertSame(0, (int) $DB->get_field('skilland_lesson', 'updatedat', [
            'skillandid' => $skilland->id, 'skilland_lessonid' => 'bad',
        ]));
    }

    public function test_overlong_multibyte_title_is_cut_on_a_character_boundary(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['selected_lessons' => '']);
        skilland_process_selected_lessons($skilland->id, json_encode([
            'long' => ['name' => str_repeat('á', 300), 'updatedAt' => '2026-01-01T10:00:00Z'],
        ]));
        // A second pass updates the existing row through the other write path.
        skilland_process_selected_lessons($skilland->id, json_encode([
            'long' => ['name' => str_repeat('é', 300)],
        ]));

        $title = $DB->get_field('skilland_lesson', 'title', ['skillandid' => $skilland->id, 'skilland_lessonid' => 'long']);
        $this->assertSame(255, \core_text::strlen($title));
        $this->assertSame(str_repeat('é', 255), $title);
    }

    public function test_malformed_lesson_entries_are_skipped(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['selected_lessons' => '']);
        skilland_process_selected_lessons($skilland->id, json_encode([
            'a' => ['name' => ['x'], 'updatedAt' => '2026-01-01T10:00:00Z'],
            'b' => ['name' => 'B', 'updatedAt' => ['x']],
            'c' => ['name' => 'C', 'updatedAt' => '2026-01-01T10:00:00Z'],
        ]));

        $this->assertSame(['c'], array_keys($this->lessons($skilland->id)));
        $this->assertCount(2, $this->take_debugging());
    }

    public function test_failure_while_saving_lessons_rolls_back_the_new_activity(): void {
        global $DB;
        $this->resetAfterTest();
        // The default per-test transaction would swallow the rollback under test.
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $lessons = json_encode([
            'ok' => ['name' => 'Fine', 'updatedAt' => '2026-01-01T10:00:00Z'],
            str_repeat('x', 400) => ['name' => 'Id too long for the column', 'updatedAt' => 0],
        ]);

        $this->create_activity($course, ['selected_lessons' => '']);
        $before = $DB->count_records('skilland');
        $record = (object) [
            'course' => $course->id,
            'name' => 'Rolled back',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'skilland_topicid' => 'topic-1',
            'topic_orderindex' => 1,
            'selected_lessons' => $lessons,
        ];

        try {
            skilland_add_instance($record);
            $this->fail('Saving the lessons should have failed.');
        } catch (\dml_exception $e) {
            $this->assertFalse($DB->is_transaction_started());
        }

        $this->assertSame($before, $DB->count_records('skilland'));
        $this->assertSame(0, $DB->count_records('skilland_lesson', ['skilland_lessonid' => 'ok']));
    }
}
