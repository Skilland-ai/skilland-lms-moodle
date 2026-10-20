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

use mod_skilland\skilland_testcase;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../skilland_testcase.php');

/**
 * The Moodle App view of an activity, rendered by the real Moodle output stack from a provisioned
 * topic SCORM.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\output\mobile
 * @covers     \mod_skilland\output\lesson_list
 */
final class mobile_test extends skilland_testcase {
    /**
     * A mapped course with a Skilland activity and a student.
     *
     * @return array [course, skilland record with ->cmid, student]
     */
    private function activity(): array {
        $course = $this->getDataGenerator()->create_course();
        $this->generator()->create_course_mapping($course->id);
        $skilland = $this->create_activity($course);
        return [$course, $skilland, $this->enrol($course, 'student')];
    }

    /**
     * Open the activity in the app as a user.
     *
     * @param \stdClass $course
     * @param \stdClass $skilland
     * @param \stdClass $user
     * @return array The app payload.
     */
    private function view(\stdClass $course, \stdClass $skilland, \stdClass $user): array {
        $this->setUser($user);
        return mobile::mobile_course_view(['cmid' => $skilland->cmid, 'courseid' => $course->id, 'userid' => $user->id]);
    }

    public function test_a_student_sees_the_lessons_linking_to_the_app_scorm_player(): void {
        [$course, $skilland, $student] = $this->activity();
        $scormcmid = $this->provision($skilland);

        $result = $this->view($course, $skilland, $student);

        $this->assertSame('main', $result['templates'][0]['id']);
        $html = $result['templates'][0]['html'];
        $lessons = $this->lessons($skilland->id);
        $this->assertCount(2, $lessons);
        foreach ($lessons as $lesson) {
            $this->assertNotEmpty($lesson->scoid);
            $this->assertStringContainsString('<h2>' . format_string($lesson->title) . '</h2>', $html);
            $this->assertStringContainsString(
                'core-link capture="true" href="' . s(skilland_scorm_player_url($skilland, $lesson, true)->out(false)) . '"',
                $html
            );
            $this->assertStringContainsString('player.php?id=' . $scormcmid . '&amp;scoid=' . $lesson->scoid . '&amp;', $html);
        }
        $this->assertStringNotContainsString('cm=' . $scormcmid, $html);
        $this->assertStringNotContainsString('display=popup', $html);
        $this->assertStringNotContainsString('view.php?id=' . $skilland->cmid . '&amp;play=', $html);
        $this->assertStringContainsString(get_string('ready_to_start', 'mod_skilland'), $html);
        $this->assertStringContainsString(get_string('start_lesson', 'mod_skilland'), $html);
        $this->assertStringContainsString('<core-progress-bar progress="0"', $html);
        $this->assertStringNotContainsString(get_string('content_being_prepared', 'mod_skilland'), $html);
    }

    public function test_the_view_logs_through_the_web_service_and_not_here(): void {
        [$course, $skilland, $student] = $this->activity();
        $this->provision($skilland);
        $sink = $this->redirectEvents();

        $result = $this->view($course, $skilland, $student);

        $viewed = array_filter($sink->get_events(), fn($event) => $event instanceof \mod_skilland\event\course_module_viewed);
        $sink->close();
        $this->assertSame([], $viewed, 'The viewed event comes from mod_skilland_view_skilland');
        $this->assertSame(['skillandid' => (int) $skilland->id], $result['otherdata']);
        $this->assertSame([], $result['files']);
        $this->assertStringContainsString("this.CoreCourseLogHelperProvider.log(", $result['javascript']);
        $this->assertStringContainsString("'mod_skilland_view_skilland'", $result['javascript']);
        $this->assertStringContainsString('this.CONTENT_OTHERDATA.skillandid', $result['javascript']);
    }

    public function test_an_unprovisioned_activity_is_being_prepared(): void {
        [$course, $skilland, $student] = $this->activity();

        $html = $this->view($course, $skilland, $student)['templates'][0]['html'];

        $this->assertStringContainsString(get_string('content_being_prepared', 'mod_skilland'), $html);
        $this->assertStringNotContainsString('player.php', $html);
        $this->assertStringNotContainsString('<ion-list>', $html);
    }

    public function test_a_user_outside_the_course_is_refused(): void {
        [$course, $skilland] = $this->activity();
        $this->provision($skilland);
        $outsider = $this->getDataGenerator()->create_user();

        $this->expectException(\require_login_exception::class);
        $this->view($course, $skilland, $outsider);
    }

    public function test_a_user_without_the_view_capability_is_refused(): void {
        // A prohibited mod/skilland:view makes the activity not user-visible, so require_login()
        // refuses before require_capability() is reached, exactly as view.php does.
        [$course, $skilland, $student] = $this->activity();
        $this->provision($skilland);
        $studentrole = $this->getDataGenerator()->create_role();
        assign_capability(
            'mod/skilland:view',
            CAP_PROHIBIT,
            $studentrole,
            \context_module::instance($skilland->cmid)->id,
            true
        );
        role_assign($studentrole, $student->id, \context_module::instance($skilland->cmid)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\require_login_exception::class);
        $this->view($course, $skilland, $student);
    }
}
