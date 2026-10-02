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

/**
 * Upgrade helpers for mod_skilland.
 *
 * The only code that still knows the legacy skilland_course mapping table: it moves the
 * table's rows into the skilland_course_id course custom field before the table is dropped.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../locallib.php');

/**
 * Copy the legacy skilland_course mappings into the skilland_course_id course custom field.
 *
 * A course whose field is empty gets the table's value; a field that already holds a value
 * wins and the row is only counted (as same or conflict); rows of deleted courses and rows
 * without a value are counted and skipped. Only counts and course ids are logged. The table
 * itself is left in place: the upgrade step drops it.
 *
 * @return array Counts keyed by migrated, same, conflict, deletedcourse, empty and failed.
 */
function skilland_migrate_course_mapping_table(): array {
    global $DB;

    $counts = ['migrated' => 0, 'same' => 0, 'conflict' => 0, 'deletedcourse' => 0, 'empty' => 0, 'failed' => 0];

    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('skilland_course')) {
        return $counts;
    }

    skilland_ensure_course_customfield();

    $conflicts = [];
    $failures = [];
    $rows = $DB->get_records('skilland_course', [], 'id ASC', 'id, course, skilland_courseid');
    foreach ($rows as $row) {
        $courseid = (int) $row->course;
        $value = trim((string) $row->skilland_courseid);
        if ($value === '') {
            $counts['empty']++;
            continue;
        }
        if (!$DB->record_exists('course', ['id' => $courseid])) {
            $counts['deletedcourse']++;
            continue;
        }

        $current = skilland_get_mapped_courseid($courseid);
        if ($current !== null) {
            if ($current === $value) {
                $counts['same']++;
            } else {
                $counts['conflict']++;
                $conflicts[] = $courseid;
            }
            continue;
        }

        if (skilland_set_course_customfield_value($courseid, $value)) {
            $counts['migrated']++;
        } else {
            $counts['failed']++;
            $failures[] = $courseid;
        }
    }

    $summary = [];
    foreach ($counts as $key => $count) {
        $summary[] = $key . '=' . $count;
    }
    mtrace('mod_skilland: course mapping table migrated to the custom field: ' . implode(', ', $summary));
    if ($conflicts) {
        mtrace('mod_skilland: kept the custom field value of course ids ' . implode(', ', $conflicts));
    }
    if ($failures) {
        mtrace('mod_skilland: could not write the custom field of course ids ' . implode(', ', $failures));
    }

    return $counts;
}
