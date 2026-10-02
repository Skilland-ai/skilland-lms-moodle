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

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * The hidden topic SCORM is named after the activity and honours Hide Skilland labels, its
 * gradebook item follows, and the activity can show its description on the course page (SKL-689).
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::skilland_update_instance
 * @covers ::skilland_sync_scorm_module_name
 * @covers ::skilland_scorm_module_name
 * @covers ::skilland_get_coursemodule_info
 */
final class scorm_name_test extends skilland_testcase {
    /**
     * Save the activity's settings as the edit form does.
     *
     * @param \stdClass $skilland The skilland record.
     * @param array $changes Fields to change.
     */
    private function save(\stdClass $skilland, array $changes): void {
        global $DB;

        $current = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $data = (object) ($changes + [
            'instance' => $current->id,
            'course' => $current->course,
            'name' => $current->name,
            'hidelabels' => $current->hidelabels,
            'skilland_topicid' => $current->skilland_topicid,
        ]);
        $this->assertTrue(skilland_update_instance($data));
    }

    /**
     * The SCORM record linked to an activity.
     *
     * @param int $skillandid
     * @return \stdClass
     */
    private function scorm(int $skillandid): \stdClass {
        global $DB;

        $scormcmid = (int) $DB->get_field('skilland', 'scormcmid', ['id' => $skillandid], MUST_EXIST);
        $instance = (int) $DB->get_field('course_modules', 'instance', ['id' => $scormcmid], MUST_EXIST);
        return $DB->get_record('scorm', ['id' => $instance], '*', MUST_EXIST);
    }

    /**
     * Give the hidden SCORM a grade item, so the test can watch the gradebook follow its name.
     *
     * @param int $skillandid
     * @return \grade_item The SCORM's grade item.
     */
    private function graded_scorm(int $skillandid): \grade_item {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->libdir . '/gradelib.php');

        $scorm = $this->scorm($skillandid);
        $scorm->maxgrade = 100;
        $DB->update_record('scorm', $scorm);
        $scorm->cmidnumber = '';
        scorm_grade_item_update($scorm);
        return $this->grade_item($scorm);
    }

    /**
     * The grade item of a SCORM.
     *
     * @param \stdClass $scorm
     * @return \grade_item
     */
    private function grade_item(\stdClass $scorm): \grade_item {
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'scorm', 'iteminstance' => $scorm->id,
            'courseid' => $scorm->course]);
        $this->assertNotFalse($item);
        return $item;
    }

    public function test_hidelabels_names_a_new_scorm_without_the_topic_code(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T1 - Fixture topic', 'hidelabels' => 1]);
        $this->provision($skilland);

        $this->assertSame('Fixture topic (SCORM)', $this->scorm($skilland->id)->name);
    }

    public function test_toggling_hidelabels_renames_the_scorm_and_its_grade_item(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T1 - Fixture topic']);
        $this->provision($skilland);
        $this->assertSame('T1 - Fixture topic (SCORM)', $this->scorm($skilland->id)->name);
        $this->graded_scorm($skilland->id);

        $this->save($skilland, ['hidelabels' => 1]);
        $scorm = $this->scorm($skilland->id);
        $this->assertSame('Fixture topic (SCORM)', $scorm->name);
        $this->assertSame('Fixture topic (SCORM)', $this->grade_item($scorm)->itemname);
        $scormcmid = (int) $DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]);
        $this->assertSame('Fixture topic (SCORM)', get_fast_modinfo($course->id)->get_cm($scormcmid)->name);

        $this->save($skilland, ['hidelabels' => 0]);
        $scorm = $this->scorm($skilland->id);
        $this->assertSame('T1 - Fixture topic (SCORM)', $scorm->name);
        $this->assertSame('T1 - Fixture topic (SCORM)', $this->grade_item($scorm)->itemname);
    }

    public function test_renaming_the_activity_renames_the_scorm(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T1 - Fixture topic']);
        $this->provision($skilland);

        $this->save($skilland, ['name' => 'T1 - Renamed topic']);

        $this->assertSame('T1 - Renamed topic (SCORM)', $this->scorm($skilland->id)->name);
    }

    public function test_the_course_page_shows_the_description_only_when_asked(): void {
        $course = $this->getDataGenerator()->create_course();
        $shown = $this->create_activity($course, ['intro' => '<p>About the fixture topic</p>', 'showdescription' => 1]);
        $hidden = $this->create_activity($course, ['intro' => '<p>Not on the course page</p>', 'showdescription' => 0]);

        $modinfo = get_fast_modinfo($course->id);
        $this->assertStringContainsString('About the fixture topic', $modinfo->get_cm($shown->cmid)->get_formatted_content());
        $this->assertSame('', (string) $modinfo->get_cm($hidden->cmid)->get_formatted_content());
    }

    /**
     * A name that is only the topic code keeps it with hidelabels, on the SCORM and the course page.
     */
    public function test_a_code_only_name_is_never_blanked_by_hidelabels(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T1 - ', 'hidelabels' => 1]);
        $this->provision($skilland);

        $stored = (string) $DB->get_field('skilland', 'name', ['id' => $skilland->id]);
        $this->assertSame('T1 -', trim($stored));
        $this->assertSame($stored . ' (SCORM)', $this->scorm($skilland->id)->name);
        $this->assertSame('T1 -', trim(get_fast_modinfo($course->id)->get_cm($skilland->cmid)->name));
    }

    /**
     * A 255-character multibyte name with hidelabels gives a stored SCORM name of exactly 255 characters.
     */
    public function test_a_long_multibyte_name_with_hidelabels_is_stored_whole(): void {
        $course = $this->getDataGenerator()->create_course();
        $name = 'T1 - ' . str_repeat('漢', 250);
        $skilland = $this->create_activity($course, ['name' => $name, 'hidelabels' => 1]);
        $this->provision($skilland);

        $stored = $this->scorm($skilland->id)->name;
        $this->assertSame(str_repeat('漢', 247) . ' (SCORM)', $stored);
        $this->assertSame(255, \core_text::strlen($stored));
    }

    /**
     * Hidelabels on, off and on again: the SCORM follows each save.
     */
    public function test_hidelabels_on_off_on_follows_each_save(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T2 - Fixture topic', 'hidelabels' => 1]);
        $this->provision($skilland);

        $names = [$this->scorm($skilland->id)->name];
        foreach ([0, 1] as $hidelabels) {
            $this->save($skilland, ['hidelabels' => $hidelabels]);
            $names[] = $this->scorm($skilland->id)->name;
        }

        $this->assertSame(['Fixture topic (SCORM)', 'T2 - Fixture topic (SCORM)', 'Fixture topic (SCORM)'], $names);
    }

    /**
     * Saving the activity after its hidden SCORM was deleted succeeds and creates no SCORM.
     */
    public function test_saving_after_the_scorm_was_deleted_is_a_no_op_for_the_scorm(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['name' => 'T1 - Fixture topic']);
        $scormcmid = $this->provision($skilland);
        course_delete_module($scormcmid);
        $this->assertSame([], $this->scorm_cmids($course->id));

        $this->save($skilland, ['name' => 'T1 - Renamed topic', 'hidelabels' => 1]);

        $this->assertSame('T1 - Renamed topic', $DB->get_field('skilland', 'name', ['id' => $skilland->id]));
        $this->assertSame([], $this->scorm_cmids($course->id));
        foreach ($this->take_debugging() as $message) {
            $this->assertStringNotContainsString('renaming the SCORM', $message);
        }
    }
}
