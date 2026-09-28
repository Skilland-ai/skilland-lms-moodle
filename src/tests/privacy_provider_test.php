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

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_skilland\local\api_client;
use mod_skilland\local\testing\fixture_api_client;
use mod_skilland\privacy\provider;

/**
 * The privacy provider: metadata, and finding, exporting and deleting learners' lesson progress.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass[] Two activities, each with ->cmid and ->lessons (rows by SkilLand id). */
    private array $activities = [];

    /** @var \stdClass First learner, with progress in both activities. */
    private \stdClass $alice;

    /** @var \stdClass Second learner, with progress in the first activity only. */
    private \stdClass $bob;

    /** @var \stdClass Learner without progress. */
    private \stdClass $carol;

    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        \core\di::set(api_client::class, new fixture_api_client());

        /** @var \mod_skilland_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_skilland');
        $this->course = $this->getDataGenerator()->create_course();
        foreach (['First', 'Second'] as $name) {
            $instance = $generator->create_instance(['course' => $this->course->id, 'name' => $name]);
            $activity = $DB->get_record('skilland', ['id' => $instance->id], '*', MUST_EXIST);
            $activity->cmid = (int) $instance->cmid;
            $activity->lessons = [];
            foreach ($DB->get_records('skilland_lesson', ['skillandid' => $activity->id]) as $lesson) {
                $activity->lessons[$lesson->skilland_lessonid] = $lesson;
            }
            $this->activities[] = $activity;
        }

        $this->alice = $this->getDataGenerator()->create_user();
        $this->bob = $this->getDataGenerator()->create_user();
        $this->carol = $this->getDataGenerator()->create_user();
        $progress = [
            [$this->alice, 0, 'lesson-1', 'passed', 75.5],
            [$this->alice, 0, 'lesson-2', 'incomplete', null],
            [$this->alice, 1, 'lesson-1', 'completed', null],
            [$this->bob, 0, 'lesson-1', 'failed', 20],
        ];
        foreach ($progress as [$user, $index, $lessonid, $status, $score]) {
            $activity = $this->activities[$index];
            $generator->create_progress(['skillandid' => $activity->id, 'lessonid' => $activity->lessons[$lessonid]->id,
                'userid' => $user->id, 'status' => $status, 'score' => $score]);
        }
    }

    /**
     * Module context of an activity.
     *
     * @param int $index
     * @return \context_module
     */
    private function context(int $index): \context_module {
        return \context_module::instance($this->activities[$index]->cmid);
    }

    /**
     * Number of progress rows of a user in an activity.
     *
     * @param \stdClass $user
     * @param int $index
     * @return int
     */
    private function rows(\stdClass $user, int $index): int {
        global $DB;
        return $DB->count_records('skilland_progress', ['userid' => $user->id, 'skillandid' => $this->activities[$index]->id]);
    }

    public function test_metadata_describes_the_progress_table_and_the_skilland_service(): void {
        $items = provider::get_metadata(new collection('mod_skilland'))->get_collection();

        $names = array_map(fn($item) => $item->get_name(), $items);
        $this->assertSame(['skilland_progress', 'skilland'], $names);
        $this->assertSame(
            ['userid', 'lessonid', 'status', 'score', 'timemodified'],
            array_keys($items[0]->get_privacy_fields())
        );
        $this->assertArrayHasKey('email', $items[1]->get_privacy_fields());
    }

    public function test_contexts_for_a_user_are_the_activities_with_their_progress(): void {
        $this->assertEqualsCanonicalizing(
            [$this->context(0)->id, $this->context(1)->id],
            provider::get_contexts_for_userid($this->alice->id)->get_contextids()
        );
        $this->assertEquals([$this->context(0)->id], provider::get_contexts_for_userid($this->bob->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($this->carol->id)->get_contextids());
    }

    public function test_users_in_context_are_the_learners_with_progress(): void {
        $userlist = new userlist($this->context(0), 'mod_skilland');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->alice->id, $this->bob->id], $userlist->get_userids());

        $courselist = new userlist(\context_course::instance($this->course->id), 'mod_skilland');
        provider::get_users_in_context($courselist);
        $this->assertEmpty($courselist->get_userids());
    }

    public function test_export_writes_each_lesson_status_and_score(): void {
        $this->export_context_data_for_user($this->alice->id, $this->context(0), 'mod_skilland');

        $data = writer::with_context($this->context(0))->get_data([get_string('privacy:path:progress', 'mod_skilland')]);
        $this->assertNotEmpty($data);
        $bylesson = [];
        foreach ($data->lessons as $lesson) {
            $bylesson[$lesson->lesson] = $lesson;
        }
        $this->assertEqualsCanonicalizing(['Lesson one', 'Lesson two'], array_keys($bylesson));
        $this->assertSame('passed', $bylesson['Lesson one']->status);
        $this->assertEquals(75.5, $bylesson['Lesson one']->score);
        $this->assertSame('incomplete', $bylesson['Lesson two']->status);

        // Bob's progress is not part of Alice's export.
        $this->assertFalse(writer::with_context($this->context(1))->has_any_data());
    }

    public function test_delete_all_users_in_context_only_touches_that_activity(): void {
        provider::delete_data_for_all_users_in_context($this->context(0));

        $this->assertSame(0, $this->rows($this->alice, 0));
        $this->assertSame(0, $this->rows($this->bob, 0));
        $this->assertSame(1, $this->rows($this->alice, 1));
    }

    public function test_delete_for_a_user_only_touches_that_user_in_the_approved_contexts(): void {
        provider::delete_data_for_user(new approved_contextlist($this->alice, 'mod_skilland', [$this->context(0)->id]));

        $this->assertSame(0, $this->rows($this->alice, 0));
        $this->assertSame(1, $this->rows($this->alice, 1));
        $this->assertSame(1, $this->rows($this->bob, 0));
    }

    public function test_delete_for_users_only_touches_the_approved_users(): void {
        provider::delete_data_for_users(new approved_userlist($this->context(0), 'mod_skilland', [$this->bob->id]));

        $this->assertSame(0, $this->rows($this->bob, 0));
        $this->assertSame(2, $this->rows($this->alice, 0));
    }
}
