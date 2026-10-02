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
 * The 2026100210 upgrade step (SKL-689): the legacy skilland_course mapping table fills empty
 * skilland_course_id course custom fields, a field that already holds a value wins, and the
 * table is dropped.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::xmldb_skilland_upgrade
 * @covers ::skilland_migrate_course_mapping_table
 */
final class course_mapping_upgrade_test extends skilland_testcase {
    /**
     * The legacy table as install.xml declared it before 0.9.52-beta.
     *
     * @return \xmldb_table
     */
    private function legacy_table(): \xmldb_table {
        $table = new \xmldb_table('skilland_course');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('course', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('skilland_courseid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('skilland_orgid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timesynced', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('course_unique', XMLDB_KEY_UNIQUE, ['course']);
        return $table;
    }

    /**
     * Insert a legacy mapping row.
     *
     * @param int $courseid
     * @param string $skillid
     */
    private function legacy_row(int $courseid, string $skillid): void {
        global $DB;

        $DB->insert_record('skilland_course', (object) [
            'course' => $courseid,
            'skilland_courseid' => $skillid,
            'skilland_orgid' => 'org-fixture',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    public function test_upgrade_step_moves_the_table_into_the_custom_field_and_drops_it(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/skilland/db/upgrade.php');

        $dbman = $DB->get_manager();
        $table = $this->legacy_table();
        $this->assertFalse($dbman->table_exists($table), 'A fresh install has no legacy table');
        $dbman->create_table($table);

        try {
            $empty = $this->getDataGenerator()->create_course();
            $mapped = $this->getDataGenerator()->create_course();
            $same = $this->getDataGenerator()->create_course();
            $this->generator()->create_course_mapping((int) $mapped->id, 'skill-field');
            $this->generator()->create_course_mapping((int) $same->id, 'skill-same');
            $deletedcourseid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {course}') + 1000;

            $this->legacy_row((int) $empty->id, 'skill-legacy');
            $this->legacy_row((int) $mapped->id, 'skill-other');
            $this->legacy_row((int) $same->id, 'skill-same');
            $this->legacy_row($deletedcourseid, 'skill-gone');

            set_config('version', 2026100209, 'mod_skilland');
            $this->expectOutputRegex('/migrated=1, same=1, conflict=1, deletedcourse=1, empty=0, failed=0/');
            $this->assertTrue(\xmldb_skilland_upgrade(2026100209));

            $this->assertSame('skill-legacy', skilland_get_mapped_courseid((int) $empty->id));
            $this->assertSame('skill-field', skilland_get_mapped_courseid((int) $mapped->id));
            $this->assertSame('skill-same', skilland_get_mapped_courseid((int) $same->id));
            $this->assertSame('skill-legacy', $this->course_mapping_row((int) $empty->id)->charvalue);
            $this->assertFalse($dbman->table_exists('skilland_course'));
            $this->assertEquals(2026100210, get_config('mod_skilland', 'version'));
        } finally {
            if ($dbman->table_exists('skilland_course')) {
                $dbman->drop_table($table);
            }
        }
    }

    public function test_upgrade_step_without_the_table_only_moves_the_version(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/skilland/db/upgrade.php');

        $course = $this->getDataGenerator()->create_course();
        $this->generator()->create_course_mapping((int) $course->id, 'skill-field');

        set_config('version', 2026100209, 'mod_skilland');
        $this->assertTrue(\xmldb_skilland_upgrade(2026100209));

        $this->assertFalse($DB->get_manager()->table_exists('skilland_course'));
        $this->assertSame('skill-field', skilland_get_mapped_courseid((int) $course->id));
        $this->assertEquals(2026100210, get_config('mod_skilland', 'version'));
    }
}
