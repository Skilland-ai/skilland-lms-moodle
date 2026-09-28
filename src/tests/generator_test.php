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

use mod_skilland\local\api_client;
use mod_skilland\local\testing\fixture_api_client;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/skilland/locallib.php');

/**
 * The mod_skilland data generator, which PHPUnit and Behat both build on.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland_generator
 */
final class generator_test extends \advanced_testcase {
    public function test_create_instance_maps_the_course_and_selects_the_fixture_lessons(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module('skilland', ['course' => $course->id]);

        $this->assertInstanceOf(fixture_api_client::class, \core\di::get(api_client::class));
        $this->assertSame('skill-1', skilland_get_mapped_courseid((int) $course->id));
        $record = $DB->get_record('skilland', ['id' => $instance->id], '*', MUST_EXIST);
        $this->assertSame('topic-1', $record->skilland_topicid);
        $this->assertSame(0, (int) $record->autoupdate);
        $this->assertSame(1, (int) $record->topic_orderindex);
        $this->assertSame(0, (int) $record->grade);
        $this->assertNull($record->scormcmid);
        $this->assertEquals(
            ['lesson-1' => 'Lesson one', 'lesson-2' => 'Lesson two'],
            $DB->get_records_menu(
                'skilland_lesson',
                ['skillandid' => $instance->id, 'visible' => 1],
                'orderindex',
                'skilland_lessonid, title'
            )
        );
    }

    public function test_create_instance_can_provision_the_topic_scorm(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->create_module(
            'skilland',
            ['course' => $course->id, 'provisioned' => 1, 'selected_lessons' => '']
        );

        $scormcmid = (int) $DB->get_field('skilland', 'scormcmid', ['id' => $instance->id]);
        $this->assertNotEmpty(get_coursemodule_from_id('scorm', $scormcmid, $course->id));
        $this->assertSame(0, $DB->count_records('skilland_lesson', ['skillandid' => $instance->id]));
    }

    public function test_lesson_and_progress_helpers_fill_their_tables(): void {
        $this->resetAfterTest();

        /** @var \mod_skilland_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_skilland');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $instance = $generator->create_instance(['course' => $course->id, 'selected_lessons' => '']);

        $lesson = $generator->create_lesson(['skillandid' => $instance->id, 'visible' => 0]);
        $progress = $generator->create_progress(['skillandid' => $instance->id, 'lessonid' => $lesson->id,
            'userid' => $user->id, 'score' => 42]);

        $this->assertSame('generated-lesson-1', $lesson->skilland_lessonid);
        $this->assertSame(0, (int) $lesson->visible);
        $this->assertSame('completed', $progress->status);
        $this->assertEquals(42, $progress->score);
    }
}
