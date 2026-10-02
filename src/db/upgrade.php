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
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function to create the Skilland Course ID custom field.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool True on success, false on failure.
 */
function xmldb_skilland_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $GLOBALS['DB']->get_manager();

    // For version 2025120103, ensure the custom field is created.
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

    // For version 2025120104, hooks are registered via db/hooks.php.
    if ($oldversion < 2025120104) {
        // Upgrade savepoint reached.
        upgrade_mod_savepoint(true, 2025120104, 'skilland');
    }

    // For version 2025120400: Add topic-level SCORM fields.
    if ($oldversion < 2025120400) {
        // Add scormcmid and scorm_provisioned to skilland table.
        $table = new xmldb_table('skilland');

        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('scormcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'hidelabels');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            $field = new xmldb_field('scorm_provisioned', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'scormcmid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Add scoid and sco_identifier to skilland_lesson table.
        $table = new xmldb_table('skilland_lesson');

        if ($dbman->table_exists($table)) {
            $field = new xmldb_field('scoid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'scormcmid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            $field = new xmldb_field('sco_identifier', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'scoid');
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Upgrade savepoint reached.
        upgrade_mod_savepoint(true, 2025120400, 'skilland');
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

        if ($dbman->table_exists($table)) {
            $field = new xmldb_field(
                'topic_orderindex',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                XMLDB_NOTNULL,
                null,
                '1',
                'hidelabels'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_mod_savepoint(true, 2026020206, 'skilland');
    }

    // For version 2026092522 (SKL-661): lock the Skilland Course ID custom field so only
    // users with moodle/course:changelockedcustomfields can remap a course, and drop the
    // legacy per-lesson SCORM column.
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

        $table = new xmldb_table('skilland_lesson');
        $lessonfield = new xmldb_field('scormcmid');
        if ($dbman->field_exists($table, $lessonfield)) {
            $dbman->drop_field($table, $lessonfield);
        }

        upgrade_mod_savepoint(true, 2026092522, 'skilland');
    }

    // For version 2026092604 (SKL-655): keep the full lesson -> SCO identifier map of the
    // provisioned package, so lessons ticked after provisioning resolve their SCO.
    if ($oldversion < 2026092604) {
        $table = new xmldb_table('skilland');
        $field = new xmldb_field('scomappings', XMLDB_TYPE_TEXT, null, null, null, null, null, 'scorm_provisioned');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092604, 'skilland');
    }

    // For version 2026092608 (SKL-668): the SkilLand activity owns completion and the (opt-in)
    // grade, read from a per-learner progress store that survives SCORM re-provisioning; the
    // hidden topic SCORM keeps no gradebook presence.
    if ($oldversion < 2026092608) {
        $table = new xmldb_table('skilland');

        $field = new xmldb_field(
            'completionlessons',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'scomappings'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'grade',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'completionlessons'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('skilland_progress');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('skillandid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lessonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'not_started');
        $table->add_field('score', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('skillandid_fk', XMLDB_KEY_FOREIGN, ['skillandid'], 'skilland', ['id']);
        $table->add_key('lessonid_fk', XMLDB_KEY_FOREIGN, ['lessonid'], 'skilland_lesson', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $table->add_index('lessonid_userid_uix', XMLDB_INDEX_UNIQUE, ['lessonid', 'userid']);
        $table->add_index('skillandid_userid_idx', XMLDB_INDEX_NOTUNIQUE, ['skillandid', 'userid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Already-linked topic SCORMs drop their grade item: one broken SCORM never fails the upgrade.
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
        $linked = $DB->get_records_select('skilland', 'scormcmid IS NOT NULL', null, 'id ASC', 'id, scormcmid');
        foreach ($linked as $skilland) {
            try {
                $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
                if (!$scormcm) {
                    continue;
                }
                $scorm = $DB->get_record('scorm', ['id' => $scormcm->instance], '*', IGNORE_MISSING);
                if (!$scorm) {
                    continue;
                }
                $scorm->maxgrade = 0;
                $scorm->grademethod = GRADEHIGHEST;
                $DB->update_record('scorm', $scorm);
                $scorm->cmidnumber = $scormcm->idnumber ?? '';
                scorm_grade_item_update($scorm);
            } catch (\Throwable $e) {
                debugging('mod_skilland: could not remove the grade item of SCORM cmid ' . $skilland->scormcmid .
                    ': ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }

        upgrade_mod_savepoint(true, 2026092608, 'skilland');
    }

    // The admin settings page no longer creates the course custom field on a GET request: recreate it here.
    if ($oldversion < 2026092613) {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        try {
            skilland_ensure_course_customfield();
        } catch (\Throwable $e) {
            debugging('mod_skilland: could not ensure the Skilland course custom field: ' . $e->getMessage(), DEBUG_NORMAL);
        }

        upgrade_mod_savepoint(true, 2026092613, 'skilland');
    }

    // For version 2026092800 (SKL-650): the sync task no longer imports content; it records the
    // content hash of an available update and notifies the teachers once per hash.
    if ($oldversion < 2026092800) {
        $table = new xmldb_table('skilland');
        $field = new xmldb_field('updateavailable', XMLDB_TYPE_CHAR, '64', null, null, null, null, 'lastsynced');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026092800, 'skilland');
    }

    // For version 2026100203 (SKL-991): the Frontend URL used to default to https://app.skilland.ai and
    // silently overrode the Skilland URL. Clear it when it still holds that old default; custom values stay.
    if ($oldversion < 2026100203) {
        $frontendurl = \mod_skilland\local\skilland_url::normalise((string) get_config('mod_skilland', 'frontend_url'));
        if ($frontendurl === 'https://app.skilland.ai') {
            set_config('frontend_url', '', 'mod_skilland');
        }

        upgrade_mod_savepoint(true, 2026100203, 'skilland');
    }

    return true;
}
