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
 * Uninstall hook for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove plugin-owned SCORM modules and the course mapping field.
 *
 * Moodle calls this hook before plugininfo_mod::uninstall_cleanup(), which deletes
 * Skilland's course module records directly without calling course_delete_module()
 * or skilland_delete_instance(). The mapping rows are still available here.
 *
 * @return bool Always true so a failed cleanup part does not prevent uninstall.
 */
function xmldb_skilland_uninstall(): bool {
    global $CFG, $DB;

    try {
        $scormmodule = $DB->get_record('modules', ['name' => 'scorm'], 'id, visible');
        if ($scormmodule && $scormmodule->visible &&
                is_file($CFG->dirroot . '/mod/scorm/lib.php')) {
            require_once($CFG->dirroot . '/course/lib.php');
            $like = $DB->sql_like('cm.idnumber', ':idnumber');
            $idnumber = $DB->sql_like_escape('skilland_topic_') . '%';
            $cms = $DB->get_records_sql(
                "SELECT cm.id, cm.idnumber
                   FROM {course_modules} cm
                  WHERE cm.module = :moduleid AND $like",
                ['moduleid' => $scormmodule->id, 'idnumber' => $idnumber]
            );
            foreach ($cms as $cm) {
                // The numeric suffix is the Skilland activity id; adopted Edukami SCORMs
                // carry their own distinct idnumbers and are never owned by this plugin.
                if (!preg_match('/^skilland_topic_[0-9]+$/D', $cm->idnumber)) {
                    continue;
                }
                try {
                    course_delete_module((int) $cm->id);
                } catch (\Throwable $e) {
                    debugging('mod_skilland: SCORM cleanup failed for cmid ' . $cm->id . ': ' .
                        $e->getMessage(), DEBUG_NORMAL);
                }
            }
        }
    } catch (\Throwable $e) {
        debugging('mod_skilland: SCORM cleanup failed: ' . $e->getMessage(), DEBUG_NORMAL);
    }

    try {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');
        $field = skilland_get_course_customfield();
        $categoryid = $field ? (int) $field->get('categoryid') : 0;
        if ($field && !$field->delete()) {
            debugging('mod_skilland: course mapping field cleanup failed', DEBUG_NORMAL);
        }
    } catch (\Throwable $e) {
        debugging('mod_skilland: course mapping field cleanup failed: ' . $e->getMessage(), DEBUG_NORMAL);
    }

    try {
        if (!empty($categoryid) && !$DB->record_exists('customfield_field', ['categoryid' => $categoryid])) {
            \core_customfield\category_controller::create($categoryid)->delete();
        }
    } catch (\Throwable $e) {
        debugging('mod_skilland: empty custom field category cleanup failed: ' . $e->getMessage(), DEBUG_NORMAL);
    }

    return true;
}
