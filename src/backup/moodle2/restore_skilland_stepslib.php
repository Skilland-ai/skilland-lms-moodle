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

        // Map the scormcmid if present
        if (!empty($data->scormcmid)) {
            $newscormcmid = $this->get_mappingid('course_module', $data->scormcmid);
            if (!$newscormcmid) {
                $data->scormcmid = null;
                debugging('Could not map SCORM course module ID during restore', DEBUG_DEVELOPER);
            } else {
                $data->scormcmid = $newscormcmid;
            }
        }

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
        
        // Validate SCORM linkage if present
        if (!empty($data->scormcmid)) {
            $scorm_exists = $DB->record_exists('course_modules', array('id' => $data->scormcmid, 'course' => $data->course));
            if (!$scorm_exists) {
                debugging('SCORM course module ' . $data->scormcmid . ' not found after restore', DEBUG_DEVELOPER);
                // Clear invalid reference
                $DB->set_field('skilland', 'scormcmid', null, array('id' => $newitemid));
            }
        }
    }

    protected function process_skilland_lesson($data) {
        global $DB;

        $data = (object)$data;
        $data->skillandid = $this->get_new_parentid('skilland');

        // Map deprecated scormcmid if it exists on lesson level
        if (!empty($data->scormcmid)) {
            $data->scormcmid = $this->get_mappingid('course_module', $data->scormcmid);
        }

        // Handle SCO ID mapping - use sco_identifier as fallback
        if (!empty($data->scoid)) {
            // Try to map the SCO ID
            $newscoid = $this->get_mappingid('scorm_sco', $data->scoid);
            if (!$newscoid) {
                // Fallback: Find SCO by identifier if available
                if (!empty($data->sco_identifier)) {
                    $skilland = $DB->get_record('skilland', array('id' => $data->skillandid));
                    if ($skilland && $skilland->scormcmid) {
                        // Try to find the SCO by identifier in the new SCORM instance
                        $scormid = $DB->get_field('course_modules', 'instance', array('id' => $skilland->scormcmid, 'module' => $DB->get_field('modules', 'id', array('name' => 'scorm'))));
                        if ($scormid) {
                            $newscoid = $DB->get_field('scorm_scoes', 'id', array('scorm' => $scormid, 'identifier' => $data->sco_identifier));
                        }
                    }
                }
                // If still not found, set to null to avoid broken references
                $data->scoid = $newscoid ?: null;
            } else {
                $data->scoid = $newscoid;
            }
        }

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
