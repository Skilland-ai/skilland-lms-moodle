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
require_once(__DIR__ . '/skilland_testcase.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Backup and restore of a provisioned activity through the real backup and restore controllers.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_skilland_activity_structure_step
 * @covers     \restore_skilland_activity_structure_step
 * @covers     \restore_skilland_activity_task
 */
final class backup_restore_test extends skilland_testcase {
    /**
     * Back a course up and restore it into a new course.
     *
     * @param \stdClass $course
     * @param bool $withscorm Whether the hidden SCORM activity is part of the backup.
     * @return int The new course id.
     */
    private function backup_and_restore(\stdClass $course, bool $withscorm = true): int {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        if (!$withscorm) {
            foreach ($this->scorm_cmids($course->id) as $cmid) {
                $bc->get_plan()->get_setting('scorm_' . $cmid . '_included')->set_value(false);
            }
        }
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course('Restored', 'RESTORED', $course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return (int) $newcourseid;
    }

    /**
     * Import a course's activities into an existing course, as Moodle's course import does.
     *
     * @param \stdClass $course The source course.
     * @param int $targetcourseid The existing course the activities are added to.
     */
    private function import_into(\stdClass $course, int $targetcourseid): void {
        global $USER;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $rc = new \restore_controller(
            $backupid,
            $targetcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id,
            \backup::TARGET_EXISTING_ADDING
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
    }

    public function test_restore_keeps_the_settings_lessons_and_linked_scorm(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, [
            'name' => 'T1 - Fixture topic one',
            'autoupdate' => 1,
            'hidelabels' => 1,
            'topic_orderindex' => 3,
            'grade' => 20,
        ]);
        $this->provision($skilland);
        $original = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $downloads = count($this->client->downloads);

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('skilland', ['course' => $newcourseid], '*', MUST_EXIST);
        foreach (
            ['name', 'skilland_topicid', 'autoupdate', 'hidelabels', 'topic_orderindex', 'grade',
                'snapshotid', 'scomappings', 'completionlessons'] as $field
        ) {
            $this->assertEquals($original->$field, $restored->$field, $field);
        }

        // The restored activity points at the restored SCORM, not the original one.
        $scormcmids = $this->scorm_cmids($newcourseid);
        $this->assertCount(1, $scormcmids);
        $this->assertSame($scormcmids[0], (int) $restored->scormcmid);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visibleoncoursepage', ['id' => $restored->scormcmid]));
        $this->assertSame($downloads, count($this->client->downloads), 'The linked SCORM is restored, not rebuilt');

        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $restored->scormcmid]);
        $lessons = $this->lessons($restored->id);
        $this->assertSame(['lesson-1', 'lesson-2'], array_keys($lessons));
        // The Skilland position (the lesson code, SKL-694) travels with the lesson.
        $this->assertSame(
            ['lesson-1' => 1, 'lesson-2' => 2],
            array_map(fn($lesson) => (int) $lesson->skillandposition, $lessons)
        );
        foreach ($lessons as $lessonid => $lesson) {
            $this->assertEquals(
                $DB->get_field('scorm_scoes', 'id', ['scorm' => $scormid, 'identifier' => 'sco-' . $lessonid]),
                $lesson->scoid
            );
        }

        // The mapping travels with the course, in its custom field.
        $this->assertSame('skill-1', skilland_get_mapped_courseid($newcourseid));
    }

    public function test_restore_without_the_scorm_provisions_a_new_one(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $downloads = count($this->client->downloads);

        $newcourseid = $this->backup_and_restore($course, false);
        $this->take_debugging();

        $restored = $DB->get_record('skilland', ['course' => $newcourseid], '*', MUST_EXIST);
        $scormcmids = $this->scorm_cmids($newcourseid);
        $this->assertCount(1, $scormcmids);
        $this->assertSame($scormcmids[0], (int) $restored->scormcmid);
        $this->assertSame($downloads + 1, count($this->client->downloads));
        foreach ($this->lessons($restored->id) as $lesson) {
            $this->assertNotEmpty($lesson->scoid);
        }
    }

    /**
     * A course whose custom field holds no value backs up and restores; the new course stays unmapped.
     */
    public function test_backup_of_an_unmapped_course_restores_without_a_mapping(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $DB->delete_records('customfield_data', ['id' => $this->course_mapping_row((int) $course->id)->id]);
        $this->assertNull(skilland_get_mapped_courseid((int) $course->id));

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('skilland', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertSame($this->scorm_cmids($newcourseid)[0], (int) $restored->scormcmid);
        $this->assertNull(skilland_get_mapped_courseid($newcourseid));
        $this->assertNull($this->course_mapping_row($newcourseid));
    }

    /**
     * Importing into a course mapped to another skill keeps that course's own mapping.
     */
    public function test_import_into_a_course_mapped_elsewhere_keeps_its_mapping(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $target = $this->getDataGenerator()->create_course();
        $this->generator()->create_course_mapping((int) $target->id, 'skill-target');

        $this->import_into($course, (int) $target->id);
        $this->take_debugging();

        $this->assertTrue($DB->record_exists('skilland', ['course' => $target->id]));
        $this->assertSame('skill-target', skilland_get_mapped_courseid((int) $target->id));
        $this->assertSame('skill-1', skilland_get_mapped_courseid((int) $course->id), 'The source course is untouched');
    }

    /**
     * Importing into a course whose field holds only whitespace maps it to the backup's skill.
     */
    public function test_import_into_a_blank_course_takes_the_backup_mapping(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $target = $this->getDataGenerator()->create_course();
        $this->generator()->create_course_mapping((int) $target->id, '   ');

        $this->import_into($course, (int) $target->id);
        $this->take_debugging();

        $this->assertSame('skill-1', skilland_get_mapped_courseid((int) $target->id));
        $this->assertSame('skill-1', $this->course_mapping_row((int) $target->id)->charvalue);
    }
}
