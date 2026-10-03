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
require_once(dirname(__DIR__) . '/db/uninstall.php');

/**
 * Cleanup performed before Moodle directly removes mod_skilland course modules.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_skilland_uninstall
 */
final class uninstall_test extends skilland_testcase {
    public function test_removes_linked_and_orphaned_owned_scorms_but_preserves_other_scorms(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $linked = $this->create_activity($course);
        $linkedcmid = $this->provision($linked);
        $linkedscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $linkedcmid]);

        $orphan = $this->create_activity($course);
        $orphancmid = $this->provision($orphan);
        $orphanscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $orphancmid]);
        $DB->set_field('skilland', 'scormcmid', null, ['id' => $orphan->id]);

        $adopted = $this->create_activity($course);
        $adoptedcmid = $this->provision($adopted);
        $adoptedscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $adoptedcmid]);
        $DB->set_field('course_modules', 'idnumber', 'edukami_topic_123', ['id' => $adoptedcmid]);

        $malformed = $this->create_activity($course);
        $malformedcmid = $this->provision($malformed);
        $malformedscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $malformedcmid]);
        $DB->set_field('course_modules', 'idnumber', 'skilland_topic_other', ['id' => $malformedcmid]);

        $unrelated = $this->create_activity($course);
        $unrelatedcmid = $this->provision($unrelated);
        $unrelatedscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $unrelatedcmid]);
        $DB->set_field('course_modules', 'idnumber', 'external_course_activity', ['id' => $unrelatedcmid]);

        $this->assertTrue(\xmldb_skilland_uninstall());
        $this->take_debugging();

        foreach ([[$linkedcmid, $linkedscormid], [$orphancmid, $orphanscormid]] as [$cmid, $scormid]) {
            $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
            $this->assertFalse($DB->record_exists('scorm', ['id' => $scormid]));
        }
        foreach ([[$adoptedcmid, $adoptedscormid], [$malformedcmid, $malformedscormid],
                [$unrelatedcmid, $unrelatedscormid]] as [$cmid, $scormid]) {
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $cmid]));
            $this->assertTrue($DB->record_exists('scorm', ['id' => $scormid]));
        }

        // Moodle calls this hook before its own direct removal of Skilland's module records.
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $linked->cmid]));
    }

    public function test_removes_mapping_field_and_data_and_is_idempotent(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->create_activity($course);
        $field = \skilland_get_course_customfield();
        $this->assertNotNull($field);
        $fieldid = (int) $field->get('id');
        $categoryid = (int) $field->get('categoryid');
        $dataid = (int) $this->course_mapping_row((int) $course->id)->id;

        $this->assertTrue(\xmldb_skilland_uninstall());
        $this->take_debugging();

        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $fieldid]));
        $this->assertFalse($DB->record_exists('customfield_data', ['id' => $dataid]));
        $this->assertFalse($DB->record_exists('customfield_category', ['id' => $categoryid]));

        $this->assertTrue(\xmldb_skilland_uninstall());
        $this->take_debugging();
        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $fieldid]));
        $this->assertFalse($DB->record_exists('customfield_category', ['id' => $categoryid]));
    }

    public function test_preserves_mapping_category_when_it_contains_another_field(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->create_activity($course);
        $field = \skilland_get_course_customfield();
        $this->assertNotNull($field);
        $fieldid = (int) $field->get('id');
        $categoryid = (int) $field->get('categoryid');
        $dataid = (int) $this->course_mapping_row((int) $course->id)->id;

        $customfields = $this->getDataGenerator()->get_plugin_generator('core_customfield');
        $other = $customfields->create_field(['categoryid' => $categoryid, 'type' => 'text',
            'shortname' => 'another_course_field', 'name' => 'Another course field']);
        $otherid = (int) $other->get('id');

        $this->assertTrue(\xmldb_skilland_uninstall());
        $this->take_debugging();

        $this->assertFalse($DB->record_exists('customfield_field', ['id' => $fieldid]));
        $this->assertFalse($DB->record_exists('customfield_data', ['id' => $dataid]));
        $this->assertTrue($DB->record_exists('customfield_field', ['id' => $otherid]));
        $this->assertTrue($DB->record_exists('customfield_category', ['id' => $categoryid]));
    }
}
