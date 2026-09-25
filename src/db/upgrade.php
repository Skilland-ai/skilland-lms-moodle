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
 * Upgrade script for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade function to create the Skilland Course ID custom field.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success, false on failure.
 */
function xmldb_skilland_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $GLOBALS['DB']->get_manager();

    // For version 2025120104, hooks are registered via db/callbacks.php.
    if ($oldversion < 2025120104) {
        // Upgrade savepoint reached.
        upgrade_mod_savepoint(true, 2025120104, 'skilland');
    }

    // For version 2025120400: Add topic-level SCORM fields.
    if ($oldversion < 2025120400) {
        // Add scormcmid and scorm_provisioned to skilland table.
        $table = new xmldb_table('skilland');

        $field = new xmldb_field('scormcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'hidelabels');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('scorm_provisioned', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'scormcmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Add scoid and sco_identifier to skilland_lesson table.
        $table = new xmldb_table('skilland_lesson');

        $field = new xmldb_field('scoid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'scormcmid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('sco_identifier', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'scoid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Upgrade savepoint reached.
        upgrade_mod_savepoint(true, 2025120400, 'skilland');
    }

    // For version 2025120102 and 2025120103, ensure the custom field is created.
    if ($oldversion < 2025120103) {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        // Ensure the custom field is created.
        try {
            $field = skilland_ensure_course_customfield();
            if (!$field) {
                // Try to get more information about why it failed.
                $handler = \core_customfield\handler::get_handler('core_course', 'course');
                if (!$handler) {
                    throw new Exception('Could not get custom field handler');
                }

                // Log detailed error.
                $error = 'Failed to create Skilland Course ID custom field during upgrade. ';
                $error .= 'Please create it manually in Course custom fields settings or use the button in plugin settings.';
                debugging($error, DEBUG_NORMAL);
            } else {
                // Success - log it.
                mtrace('Successfully created Skilland Course ID custom field during upgrade.');
            }
        } catch (Exception $e) {
            debugging('Exception while creating Skilland custom field: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        // Upgrade savepoint reached.
        upgrade_mod_savepoint(true, 2025120103, 'skilland');
    }

    // For version 2026012701: Ensure Skilland custom field is in the correct category.
    if ($oldversion < 2026012701) {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        try {
            // This function now handles migration of the field to the correct category.
            skilland_ensure_course_customfield();
        } catch (Exception $e) {
            debugging('Exception while ensuring Skilland custom field during upgrade: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        upgrade_mod_savepoint(true, 2026012701, 'skilland');
    }

    // For version 2026020206: Add topic_orderindex field for correct lesson numbering.
    if ($oldversion < 2026020206) {
        $table = new xmldb_table('skilland');

        $field = new xmldb_field('topic_orderindex', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '1', 'hidelabels');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026020206, 'skilland');
    }

    // For version 2026092522 (SKL-661): lock the Skilland Course ID custom field so only
    // users with moodle/course:changelockedcustomfields can remap a course.
    if ($oldversion < 2026092522) {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        $field = skilland_get_course_customfield();
        if ($field) {
            $configdata = json_decode((string)$field->get('configdata'), true);
            if (!is_array($configdata)) {
                $configdata = [];
            }
            if (($configdata['locked'] ?? '0') !== '1') {
                $configdata['locked'] = '1';
                $field->set('configdata', json_encode($configdata));
                $field->save();
            }
        }

        upgrade_mod_savepoint(true, 2026092522, 'skilland');
    }

    return true;
}

