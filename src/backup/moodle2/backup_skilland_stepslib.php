<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to backup one skilland activity
 */
class backup_skilland_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {

        // To know if we include user data
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element
        $skilland = new backup_nested_element('skilland', array('id'), array(
            'name', 'intro', 'introformat', 'skilland_topicid',
            'snapshotid', 'snapshotcreatedat', 'lastsynced',
            'autoupdate', 'lockafterfirstaccess', 'hidelabels',
            'scormcmid', 'scorm_provisioned', 'completionlessons', 'grade', 'timecreated', 'timemodified',
            'skilland_courseid', 'skilland_orgid' // From skilland_course join
        ));

        $lessons = new backup_nested_element('lessons');

        $lesson = new backup_nested_element('lesson', array('id'), array(
            'skilland_lessonid', 'title', 'orderindex',
            'scoid', 'sco_identifier', 'snapshotid', 'snapshotcreatedat',
            'updatedat', 'visible'
        ));

        // Build the tree
        $skilland->add_child($lessons);
        $lessons->add_child($lesson);

        // Define sources
        $skilland->set_source_sql("
            SELECT e.*, ec.skilland_courseid, ec.skilland_orgid
            FROM {skilland} e
            LEFT JOIN {skilland_course} ec ON ec.course = e.course
            WHERE e.id = ?
        ", array(backup::VAR_ACTIVITYID));

        $lesson->set_source_table('skilland_lesson', array('skillandid' => backup::VAR_PARENTID));

        // Define id annotations
        // Annotate the SCORM course module ID so it can be properly mapped during restore
        $skilland->annotate_ids('course_module', 'scormcmid');
        
        // Note: scorm_provisioned is a timestamp field, not an ID - no annotation needed

        // Define file annotations  
        $skilland->annotate_files('mod_skilland', 'intro', null);

        return $this->prepare_activity_structure($skilland);
    }
}
