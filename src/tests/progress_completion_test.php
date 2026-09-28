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

use mod_skilland\completion\custom_completion;
use mod_skilland\task\sync_content;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/skilland_testcase.php');
require_once($CFG->dirroot . '/mod/scorm/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * Lesson progress read from real SCORM tracks, custom completion and grades.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::skilland_read_scorm_progress
 * @covers ::skilland_refresh_progress
 * @covers ::skilland_get_user_progress
 * @covers ::skilland_get_user_grades
 * @covers \mod_skilland\completion\custom_completion
 * @covers \mod_skilland\observer::scorm_tracking_submitted
 */
final class progress_completion_test extends skilland_testcase {
    /**
     * A course with completion enabled, a student and a provisioned activity.
     *
     * @param array $record Extra activity fields.
     * @return array [course, student, skilland record, scorm instance id, lesson rows by SkilLand id]
     */
    private function provisioned(array $record = []): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->enrol($course, 'student');
        $skilland = $this->create_activity($course, $record);
        $cmid = $this->provision($skilland);
        $activitycmid = $skilland->cmid;
        $skilland = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $skilland->cmid = $activitycmid;
        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
        return [$course, $student, $skilland, $scormid, $this->lessons($skilland->id)];
    }

    /**
     * Record a SCORM 1.2 track for a learner, as the SCORM player would.
     *
     * @param int $userid
     * @param int $scormid
     * @param \stdClass $lesson Lesson row with its scoid.
     * @param string $element
     * @param string $value
     * @param int $attempt
     */
    private function track(
        int $userid,
        int $scormid,
        \stdClass $lesson,
        string $element,
        string $value,
        int $attempt = 1
    ): void {
        scorm_insert_track($userid, $scormid, $lesson->scoid, $attempt, $element, $value);
        $this->take_debugging();
    }

    /**
     * Record a SCORM track that the mod_skilland observer never sees.
     *
     * Whether the external observer runs during a test depends on the database: PHPUnit wraps each
     * test in a rollback transaction on PostgreSQL only, and external observers wait for its commit,
     * while on MySQL/MariaDB they run at once. Catching the events keeps the observer out on every
     * database, so the explicit refresh or backfill under test is what writes the progress.
     *
     * @param int $userid
     * @param int $scormid
     * @param \stdClass $lesson Lesson row with its scoid.
     * @param string $element
     * @param string $value
     * @param int $attempt
     */
    private function track_unobserved(
        int $userid,
        int $scormid,
        \stdClass $lesson,
        string $element,
        string $value,
        int $attempt = 1
    ): void {
        $sink = $this->redirectEvents();
        try {
            $this->track($userid, $scormid, $lesson, $element, $value, $attempt);
        } finally {
            $sink->close();
        }
    }

    public function test_scorm_tracks_reach_the_progress_store_through_the_observer(): void {
        // The mod_scorm event observers are external: they only run outside the test transaction.
        $this->preventResetByRollback();
        [, $student, $skilland, $scormid, $lessons] = $this->provisioned();

        $this->track($student->id, $scormid, $lessons['lesson-1'], 'cmi.core.score.raw', '80');
        $this->track($student->id, $scormid, $lessons['lesson-1'], 'cmi.core.lesson_status', 'passed');
        $this->track($student->id, $scormid, $lessons['lesson-2'], 'cmi.core.lesson_status', 'failed');

        $progress = skilland_get_user_progress((int) $skilland->id, (int) $student->id);
        $this->assertSame([
            (int) $lessons['lesson-1']->id => ['status' => 'passed', 'score' => '80'],
            (int) $lessons['lesson-2']->id => ['status' => 'failed', 'score' => null],
        ], $progress);
    }

    public function test_progress_only_moves_up_and_keeps_the_best_score(): void {
        [, $student, $skilland, $scormid, $lessons] = $this->provisioned();
        $lesson = $lessons['lesson-1'];

        $this->track_unobserved($student->id, $scormid, $lesson, 'cmi.core.lesson_status', 'completed');
        $this->track_unobserved($student->id, $scormid, $lesson, 'cmi.core.score.raw', '90');
        $this->assertTrue(skilland_refresh_progress($skilland, (int) $student->id));

        // A newer, worse attempt never lowers the stored status or score.
        $this->track_unobserved($student->id, $scormid, $lesson, 'cmi.core.lesson_status', 'incomplete', 2);
        $this->track_unobserved($student->id, $scormid, $lesson, 'cmi.core.score.raw', '40', 2);
        $this->assertFalse(skilland_refresh_progress($skilland, (int) $student->id));

        $this->assertSame(
            [(int) $lesson->id => ['status' => 'completed', 'score' => '90']],
            skilland_get_user_progress((int) $skilland->id, (int) $student->id)
        );
    }

    public function test_sync_task_backfills_progress_that_missed_the_observer(): void {
        global $DB;

        [, $student, $skilland, $scormid, $lessons] = $this->provisioned();
        $this->track_unobserved($student->id, $scormid, $lessons['lesson-2'], 'cmi.core.lesson_status', 'completed');
        $this->assertFalse($DB->record_exists('skilland_progress', ['skillandid' => $skilland->id]));

        (new sync_content())->execute();
        $this->take_debugging();

        $this->assertSame(
            [(int) $lessons['lesson-2']->id => ['status' => 'completed', 'score' => null]],
            skilland_get_user_progress((int) $skilland->id, (int) $student->id)
        );
    }

    public function test_progress_survives_a_scorm_rebuild(): void {
        [, $student, $skilland, $scormid, $lessons] = $this->provisioned();
        $this->track($student->id, $scormid, $lessons['lesson-1'], 'cmi.core.lesson_status', 'completed');
        skilland_refresh_progress($skilland, (int) $student->id);

        $this->update($skilland);

        $this->assertSame(
            [(int) $lessons['lesson-1']->id => ['status' => 'completed', 'score' => null]],
            skilland_get_user_progress((int) $skilland->id, (int) $student->id)
        );
    }

    public function test_completion_needs_every_visible_lesson_completed_or_passed(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $student = $this->enrol($course, 'student');
        $skilland = $this->create_activity(
            $course,
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionlessons' => 1]
        );
        $lessons = $this->lessons($skilland->id);
        $hidden = $this->generator()->create_lesson(['skillandid' => $skilland->id, 'visible' => 0]);
        $cm = get_fast_modinfo($course)->get_cm($skilland->cmid);

        $state = fn() => (new custom_completion($cm, (int) $student->id))->get_state('completionlessons');

        $this->assertSame(COMPLETION_INCOMPLETE, $state());

        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $lessons['lesson-1']->id,
            'userid' => $student->id, 'status' => 'passed']);
        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $lessons['lesson-2']->id,
            'userid' => $student->id, 'status' => 'failed']);
        $this->assertSame(COMPLETION_INCOMPLETE, $state());

        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $hidden->id,
            'userid' => $student->id, 'status' => 'not_started']);
        $this->assertSame(COMPLETION_INCOMPLETE, $state());

        $DB->set_field(
            'skilland_progress',
            'status',
            'completed',
            ['lessonid' => $lessons['lesson-2']->id, 'userid' => $student->id]
        );
        // The hidden lesson does not count.
        $this->assertSame(COMPLETION_COMPLETE, $state());

        $this->assertSame(['completionlessons'], custom_completion::get_defined_custom_rules());
        $this->assertArrayHasKey('completionlessons', (new custom_completion($cm, (int) $student->id))
            ->get_custom_rule_descriptions());
    }

    public function test_completing_the_last_lesson_completes_the_activity(): void {
        $this->preventResetByRollback();
        [$course, $student, $skilland, $scormid, $lessons] = $this->provisioned(
            ['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionlessons' => 1]
        );
        $cm = get_fast_modinfo($course)->get_cm($skilland->cmid);
        $completion = new \completion_info($course);

        $this->track($student->id, $scormid, $lessons['lesson-1'], 'cmi.core.lesson_status', 'completed');
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);

        $this->track($student->id, $scormid, $lessons['lesson-2'], 'cmi.core.lesson_status', 'passed');
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $student->id)->completionstate);
    }

    public function test_grade_is_the_mean_lesson_score_scaled_to_the_maximum(): void {
        [$course, $student, $skilland, , $lessons] = $this->provisioned(['grade' => 50]);
        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $lessons['lesson-1']->id,
            'userid' => $student->id, 'status' => 'passed', 'score' => 80]);
        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $lessons['lesson-2']->id,
            'userid' => $student->id, 'status' => 'completed']);

        $grades = skilland_get_user_grades($skilland, (int) $student->id);
        $this->assertEqualsWithDelta(45.0, $grades[$student->id]->rawgrade, 0.00001);

        skilland_update_grades($skilland, (int) $student->id);
        $gradebook = grade_get_grades($course->id, 'mod', 'skilland', $skilland->id, $student->id);
        $this->assertEqualsWithDelta(45.0, (float) $gradebook->items[0]->grades[$student->id]->grade, 0.00001);
        $this->assertEquals(50, $gradebook->items[0]->grademax);
    }

    public function test_an_ungraded_activity_has_no_grades(): void {
        [, $student, $skilland, , $lessons] = $this->provisioned();
        $this->generator()->create_progress(['skillandid' => $skilland->id, 'lessonid' => $lessons['lesson-1']->id,
            'userid' => $student->id, 'status' => 'passed', 'score' => 80]);

        $this->assertSame([], skilland_get_user_grades($skilland, (int) $student->id));
    }
}
