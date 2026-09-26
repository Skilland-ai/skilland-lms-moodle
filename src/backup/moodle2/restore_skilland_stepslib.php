<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one skilland activity
 */
class restore_skilland_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('skilland', '/activity/skilland');
        $paths[] = new restore_path_element('skilland_lesson', '/activity/skilland/lessons/lesson');

        return $this->prepare_activity_structure($paths);
    }

    protected function process_skilland($data) {
        global $DB;

        $data = (object)$data;
        $data->course = $this->get_courseid();
        
        // Apply date offsets to all timestamp fields
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

        // scormcmid keeps the backup's (old) course module id here. The SCORM activity may be
        // restored after this one, so its mapping is resolved in
        // restore_skilland_activity_task::after_restore(), once every activity has been restored.

        // Handle Skilland Course Mapping
        if (!empty($data->skilland_courseid)) {
            $existing_map = $DB->get_record('skilland_course', array('course' => $data->course));
            if (!$existing_map) {
                // Create new course mapping
                $map = new stdClass();
                $map->course = $data->course;
                $map->skilland_courseid = $data->skilland_courseid;
                $map->skilland_orgid = isset($data->skilland_orgid) ? $data->skilland_orgid : '';
                $map->timecreated = time();
                $map->timemodified = time();
                try {
                    $DB->insert_record('skilland_course', $map);
                } catch (Exception $e) {
                    debugging('Failed to create Skilland course mapping: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            } else if ($existing_map->skilland_courseid != $data->skilland_courseid) {
                // Warn if course is already mapped to a different Skilland course
                debugging('Course already mapped to different Skilland course (existing: ' . 
                         $existing_map->skilland_courseid . ', backup: ' . $data->skilland_courseid . ')', 
                         DEBUG_DEVELOPER);
            }
        }

        // Remove fields that don't belong to the skilland table
        unset($data->skilland_courseid);
        unset($data->skilland_orgid);

        $newitemid = $DB->insert_record('skilland', $data);
        $this->apply_activity_instance($newitemid);
    }

    protected function process_skilland_lesson($data) {
        global $DB;

        $data = (object)$data;
        $data->skillandid = $this->get_new_parentid('skilland');

        // Backups taken before SKL-661 still carry the dropped lesson-level scormcmid.
        unset($data->scormcmid);

        // scoid keeps the backup's (old) SCO id here; restore_skilland_activity_task::after_restore()
        // maps it, together with scormcmid, once the SCORM activity has been restored.

        // Apply date offsets to timestamp fields
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
        // Restore files for intro field
        $this->add_related_files('mod_skilland', 'intro', null);
    }
}
