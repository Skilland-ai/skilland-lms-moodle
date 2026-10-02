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
 * Structure step to restore one skilland activity
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_skilland_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the structure paths restored for a skilland activity.
     *
     * @return restore_path_element[] the paths to restore, wrapped for activity structure.
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('skilland', '/activity/skilland');
        $paths[] = new restore_path_element('skilland_lesson', '/activity/skilland/lessons/lesson');

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores one skilland activity instance, re-mapping the Skilland course link.
     *
     * @param stdClass|array $data the backed-up activity data.
     * @return void
     */
    protected function process_skilland($data) {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        $data = (object)$data;
        $data->course = $this->get_courseid();

        // Apply date offsets to all timestamp fields.
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        if (!empty($data->lastsynced)) {
            $data->lastsynced = $this->apply_date_offset($data->lastsynced);
        }
        if (!empty($data->scorm_provisioned)) {
            $data->scorm_provisioned = $this->apply_date_offset($data->scorm_provisioned);
        }
        if (!empty($data->snapshotcreatedat)) {
            $data->snapshotcreatedat = $this->apply_date_offset($data->snapshotcreatedat);
        }

        // Scormcmid keeps the backup's (old) course module id here. The SCORM activity may be
        // restored after this one, so its mapping is resolved in
        // restore_skilland_activity_task::after_restore(), once every activity has been restored.

        // The course mapping lives in the skilland_course_id course custom field: an empty field
        // takes the backup's value, a field that already holds another value is kept.
        if (!empty($data->skilland_courseid)) {
            $backupcourseid = (string) $data->skilland_courseid;
            try {
                $existing = skilland_get_course_customfield_value((int) $data->course);
                if (empty($existing)) {
                    if (!skilland_set_course_customfield_value((int) $data->course, $backupcourseid)) {
                        debugging('mod_skilland: could not map restored course id ' . $data->course .
                            ' to its Skilland course', DEBUG_DEVELOPER);
                    }
                } else if ((string) $existing !== $backupcourseid) {
                    debugging('mod_skilland: restored course id ' . $data->course .
                        ' is already mapped to another Skilland course; its mapping is kept', DEBUG_DEVELOPER);
                }
            } catch (\Throwable $e) {
                debugging('mod_skilland: could not map restored course id ' . $data->course .
                    ' to its Skilland course', DEBUG_DEVELOPER);
            }
        }

        // Remove fields that don't belong to the skilland table.
        unset($data->skilland_courseid);
        unset($data->skilland_orgid);

        $newitemid = $DB->insert_record('skilland', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restores one skilland_lesson record for the current activity.
     *
     * @param stdClass|array $data the backed-up lesson data.
     * @return void
     */
    protected function process_skilland_lesson($data) {
        global $DB;

        $data = (object)$data;
        $data->skillandid = $this->get_new_parentid('skilland');

        // Backups taken before SKL-661 still carry the dropped lesson-level scormcmid.
        unset($data->scormcmid);

        // Backups taken before SKL-694 carry no Skilland position: unknown (0), the lesson code
        // falls back to orderindex.
        $data->skillandposition = max(0, (int) ($data->skillandposition ?? 0));

        // Scoid keeps the backup's (old) SCO id here; restore_skilland_activity_task::after_restore()
        // maps it, together with scormcmid, once the SCORM activity has been restored.

        // Apply date offsets to timestamp fields.
        if (!empty($data->updatedat)) {
            $data->updatedat = $this->apply_date_offset($data->updatedat);
        }
        if (!empty($data->snapshotcreatedat)) {
            $data->snapshotcreatedat = $this->apply_date_offset($data->snapshotcreatedat);
        }

        $DB->insert_record('skilland_lesson', $data);
    }

    /**
     * Process file areas after the structure has been restored
     */
    protected function after_execute() {
        // Restore files for intro field.
        $this->add_related_files('mod_skilland', 'intro', null);
    }
}
