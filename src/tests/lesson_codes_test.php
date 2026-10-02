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

use mod_skilland\local\testing\fixture_api_client;
use mod_skilland\output\lesson_list;
use mod_skilland\output\lesson_navigation;
use mod_skilland\output\player;
use mod_skilland\task\backfill_lesson_positions;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * Lesson codes (SKL-694): the activity form, the lesson list, the player header and the
 * navigation all show the lesson's position in the topic in Skilland, so a lesson left out of
 * the activity leaves a gap, and the code survives a content update, a reorder and an upgrade.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::skilland_lesson_label
 * @covers ::skilland_process_selected_lessons
 * @covers ::skilland_update_topic_scorm
 * @covers ::mod_skilland_fetch_lessons
 * @covers \mod_skilland\output\lesson_list
 * @covers \mod_skilland\output\lesson_navigation
 * @covers \mod_skilland\output\player
 * @covers \mod_skilland\task\backfill_lesson_positions
 * @covers ::xmldb_skilland_upgrade
 */
final class lesson_codes_test extends skilland_testcase {
    /**
     * Serve a topic of four lessons from the fixture API.
     */
    private function four_lesson_topic(): void {
        $contents = [];
        foreach ([1, 2, 3, 4] as $n) {
            $contents[] = ['id' => 'lesson-' . $n, 'name' => 'Lesson ' . $n, 'type' => 'lesson', 'sortOrder' => $n - 1,
                'content' => '<p>Lesson ' . $n . '</p>', 'updatedAt' => '2026-01-0' . $n . 'T10:00:00Z'];
        }
        $this->client->merge_response('GET topics/{id}/contents', ['contents' => $contents]);
    }

    /**
     * An activity with lessons 1, 3 and 4 of the four-lesson topic, posted as the form posts them.
     *
     * @param int $hidelabels The activity's hidelabels setting.
     * @return \stdClass The skilland record, with ->cmid.
     */
    private function activity_without_lesson_two(int $hidelabels = 0): \stdClass {
        $this->four_lesson_topic();
        $selected = [];
        foreach (mod_skilland_fetch_lessons('topic-1') as $lesson) {
            if ($lesson['id'] !== 'lesson-2') {
                $selected[$lesson['id']] = [
                    'name' => $lesson['name'],
                    'updatedAt' => $lesson['updatedAt'],
                    'position' => $lesson['position'],
                ];
            }
        }
        $course = $this->getDataGenerator()->create_course();
        return $this->create_activity($course, ['selected_lessons' => json_encode($selected), 'hidelabels' => $hidelabels]);
    }

    /**
     * The activity's visible lessons as view.php reads them, each given a SCO so it is playable.
     *
     * @param int $skillandid
     * @return \stdClass[]
     */
    private function visible_lessons(int $skillandid): array {
        global $DB;

        $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $skillandid, 'visible' => 1], 'orderindex ASC');
        foreach ($lessons as $lesson) {
            $lesson->scoid = 1000 + (int) $lesson->id;
        }
        return $lessons;
    }

    /**
     * The mod_skilland renderer, on a page with a context.
     *
     * @return \renderer_base
     */
    private function renderer(): \renderer_base {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('mod_skilland');
    }

    public function test_fetch_lessons_numbers_the_lessons_of_the_topic(): void {
        $this->four_lesson_topic();

        $this->assertSame([1, 2, 3, 4], array_column(mod_skilland_fetch_lessons('topic-1'), 'position'));
    }

    public function test_saving_stores_the_skilland_positions(): void {
        $skilland = $this->activity_without_lesson_two();

        $lessons = $this->lessons($skilland->id);
        $this->assertSame(['lesson-1', 'lesson-3', 'lesson-4'], array_keys($lessons));
        $this->assertSame([1, 2, 3], array_map('intval', array_column($lessons, 'orderindex')));
        $this->assertSame([1, 3, 4], array_map('intval', array_column($lessons, 'skillandposition')));
    }

    public function test_lesson_list_player_and_navigation_show_the_same_codes(): void {
        $skilland = $this->activity_without_lesson_two();
        $skilland->scormcmid = 40;
        $lessons = $this->visible_lessons($skilland->id);
        $cm = (object) ['id' => $skilland->cmid];
        $renderer = $this->renderer();

        $list = (new lesson_list($skilland, $lessons, $cm, 1))->export_for_template($renderer);
        $this->assertSame(['L1.1', 'L1.3', 'L1.4'], array_column($list['lessons'], 'label'));

        $ordered = array_values($lessons);
        $data = (new player($skilland, $ordered[1], $cm, $lessons, 1, new \moodle_url('/mod/scorm/player.php')))
            ->export_for_template($renderer);
        $this->assertSame('L1.3 - Lesson 3', $data['lessontitle']);
        $this->assertSame('L1.1 - Lesson 1', $data['navigation']['prev']['text']);
        $this->assertSame('L1.4 - Lesson 4', $data['navigation']['next']['text']);

        $navigation = (new lesson_navigation($ordered[2], $lessons, $cm, 1, $skilland, lesson_navigation::STYLE_PLAYER))
            ->export_for_template($renderer);
        $this->assertSame('L1.3 - Lesson 3', $navigation['prev']['text']);
    }

    public function test_hidelabels_still_hides_the_codes(): void {
        $skilland = $this->activity_without_lesson_two(1);
        $skilland->scormcmid = 40;
        $lessons = $this->visible_lessons($skilland->id);
        $cm = (object) ['id' => $skilland->cmid];
        $renderer = $this->renderer();

        $list = (new lesson_list($skilland, $lessons, $cm, 1))->export_for_template($renderer);
        $this->assertSame([false, false, false], array_column($list['lessons'], 'showlabel'));

        $ordered = array_values($lessons);
        $data = (new player($skilland, $ordered[1], $cm, $lessons, 1, new \moodle_url('/mod/scorm/player.php')))
            ->export_for_template($renderer);
        $this->assertSame('Lesson 3', $data['lessontitle']);
        $this->assertSame('Lesson 1', $data['navigation']['prev']['text']);
        $this->assertSame('Lesson 4', $data['navigation']['next']['text']);
    }

    public function test_an_unknown_position_falls_back_to_the_activity_order(): void {
        global $DB;

        $skilland = $this->activity_without_lesson_two();
        $DB->set_field('skilland_lesson', 'skillandposition', 0, ['skillandid' => $skilland->id]);

        $list = (new lesson_list($skilland, $this->visible_lessons($skilland->id), (object) ['id' => $skilland->cmid], 1))
            ->export_for_template($this->renderer());
        $this->assertSame(['L1.1', 'L1.2', 'L1.3'], array_column($list['lessons'], 'label'));
    }

    public function test_a_content_update_stores_a_reorder_made_in_skilland(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $this->assertSame(['lesson-1' => 1, 'lesson-2' => 2],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($skilland->id)));

        $contents = fixture_api_client::default_responses()['GET topics/{id}/contents']['contents'];
        $this->client->merge_response('GET topics/{id}/contents', ['contents' => array_reverse($contents)]);
        $this->update($skilland);

        $this->assertSame(['lesson-1' => 2, 'lesson-2' => 1],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($skilland->id)));
    }

    public function test_backfill_task_stores_positions_from_skilland(): void {
        global $DB;

        $skilland = $this->activity_without_lesson_two();
        $DB->set_field('skilland_lesson', 'skillandposition', 0, ['skillandid' => $skilland->id]);

        (new backfill_lesson_positions())->execute();

        $this->assertSame(['lesson-1' => 1, 'lesson-3' => 3, 'lesson-4' => 4],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($skilland->id)));
    }

    public function test_backfill_task_leaves_zero_when_the_api_fails_and_goes_on(): void {
        global $DB;

        $failing = $this->activity_without_lesson_two();
        $working = $this->create_activity($this->getDataGenerator()->create_course(), ['skilland_topicid' => 'topic-2']);
        $DB->set_field('skilland_lesson', 'skillandposition', 0, []);
        $this->client->set_response('GET topics/{id}/contents', function (array $variables) {
            if ($variables['topicId'] === 'topic-1') {
                throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
            }
            return fixture_api_client::default_responses()['GET topics/{id}/contents'];
        });

        $this->expectOutputRegex('/could not backfill lesson positions for activity ' . $failing->id . ' /');
        (new backfill_lesson_positions())->execute();

        $this->assertSame([0, 0, 0],
            array_map(fn($lesson) => (int) $lesson->skillandposition, array_values($this->lessons($failing->id))));
        $this->assertSame(['lesson-1' => 1, 'lesson-2' => 2],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($working->id)));
    }

    public function test_saving_again_refreshes_a_submitted_position_and_keeps_a_missing_one(): void {
        $skilland = $this->activity_without_lesson_two();

        skilland_process_selected_lessons($skilland->id, json_encode([
            'lesson-1' => ['name' => 'Lesson 1', 'position' => 2],
            'lesson-3' => ['name' => 'Lesson 3'],
            'lesson-4' => ['name' => 'Lesson 4', 'position' => 'four'],
        ]));

        $this->assertSame(['lesson-1' => 2, 'lesson-3' => 3, 'lesson-4' => 4],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($skilland->id)));
    }

    public function test_backfill_task_fetches_only_activities_with_unknown_positions(): void {
        global $DB;

        $known = $this->activity_without_lesson_two();
        $unknown = $this->create_activity($this->getDataGenerator()->create_course(), ['skilland_topicid' => 'topic-2']);
        $DB->set_field('skilland_lesson', 'skillandposition', 0, ['skillandid' => $unknown->id]);
        // A stored lesson Skilland no longer lists keeps 0; Skilland's lessons are never added.
        $DB->insert_record('skilland_lesson', (object) ['skillandid' => $unknown->id, 'skilland_lessonid' => 'lesson-gone',
            'title' => 'Gone', 'orderindex' => 9, 'visible' => 1, 'updatedat' => 0, 'skillandposition' => 0]);
        $this->client->calls = [];

        (new backfill_lesson_positions())->execute();

        $this->assertSame(['topic-2'], array_column(array_column($this->client->calls, 'variables'), 'topicId'));
        $this->assertSame(['lesson-1' => 1, 'lesson-3' => 3, 'lesson-4' => 4],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($known->id)));
        $this->assertSame(['lesson-1' => 1, 'lesson-2' => 2, 'lesson-gone' => 0],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $this->lessons($unknown->id)));
    }

    public function test_backfill_task_does_nothing_when_the_plugin_is_not_configured(): void {
        global $DB;

        $skilland = $this->activity_without_lesson_two();
        $DB->set_field('skilland_lesson', 'skillandposition', 0, ['skillandid' => $skilland->id]);
        unset_config('apikey', 'mod_skilland');
        $this->client->calls = [];

        $this->expectOutputRegex('/plugin not configured, lesson positions not backfilled/');
        (new backfill_lesson_positions())->execute();

        $this->assertSame(0, $this->client->count_calls('GET topics/{id}/contents'));
        $this->assertSame([0, 0, 0],
            array_map(fn($lesson) => (int) $lesson->skillandposition, array_values($this->lessons($skilland->id))));
    }

    public function test_upgrade_step_is_idempotent_and_queues_one_backfill_task(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/skilland/db/upgrade.php');

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('skilland_lesson');
        $field = new \xmldb_field('skillandposition', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'visible');
        $skilland = $this->activity_without_lesson_two();

        // The field already exists (a fresh install, or the step ran before): nothing to add.
        $this->assertTrue($dbman->field_exists($table, $field));
        foreach ([1, 2] as $run) {
            set_config('version', 2026100208, 'mod_skilland');
            $this->assertTrue(\xmldb_skilland_upgrade(2026100208), "run $run");
        }

        $this->assertTrue($dbman->field_exists($table, $field));
        $this->assertEquals(2026100209, get_config('mod_skilland', 'version'));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\mod_skilland\\task\\backfill_lesson_positions'));
        // Existing positions are untouched by the step itself.
        $this->assertSame([1, 3, 4],
            array_map(fn($lesson) => (int) $lesson->skillandposition, array_values($this->lessons($skilland->id))));
    }

    public function test_upgrade_step_adds_the_field_as_unknown_on_an_older_site(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/skilland/db/upgrade.php');

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('skilland_lesson');
        $field = new \xmldb_field('skillandposition', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'visible');
        $skilland = $this->activity_without_lesson_two();

        $dbman->drop_field($table, $field);
        try {
            set_config('version', 2026100208, 'mod_skilland');
            $this->assertTrue(\xmldb_skilland_upgrade(2026100208));
        } finally {
            // Never leave the shared test schema without the column.
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $this->assertSame([0, 0, 0],
            array_map(fn($lesson) => (int) $lesson->skillandposition, array_values($this->lessons($skilland->id))));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\\mod_skilland\\task\\backfill_lesson_positions'));
    }
}
