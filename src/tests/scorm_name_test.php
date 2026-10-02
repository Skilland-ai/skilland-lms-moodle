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
}
