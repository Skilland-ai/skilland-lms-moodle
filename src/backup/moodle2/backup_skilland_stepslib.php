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
 * Structure step to backup one skilland activity
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_skilland_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the structure backed up for a skilland activity, including its lessons.
     *
     * @return backup_nested_element the root element of the backup structure.
     */
    protected function define_structure() {

        // To know if we include user data.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element.
        $skilland = new backup_nested_element('skilland', ['id'], [
            'name', 'intro', 'introformat', 'skilland_topicid',
            'snapshotid', 'snapshotcreatedat', 'lastsynced',
            'autoupdate', 'lockafterfirstaccess', 'hidelabels', 'topic_orderindex',
            'scormcmid', 'scorm_provisioned', 'scomappings', 'completionlessons', 'grade', 'timecreated', 'timemodified',
            'skilland_courseid', 'skilland_orgid', // The course's skilland_course_id custom field; orgid kept empty.
        ]);

        $lessons = new backup_nested_element('lessons');

        $lesson = new backup_nested_element('lesson', ['id'], [
            'skilland_lessonid', 'title', 'orderindex',
            'scoid', 'sco_identifier', 'snapshotid', 'snapshotcreatedat',
            'updatedat', 'visible', 'skillandposition',
        ]);

        // Build the tree.
        $skilland->add_child($lessons);
        $lessons->add_child($lesson);

        // Define sources.
        // The course mapping is the skilland_course_id course custom field (a text field: charvalue).
        $skilland->set_source_sql("
            SELECT e.*, cd.charvalue AS skilland_courseid, '' AS skilland_orgid
              FROM {skilland} e
         LEFT JOIN (
                    SELECT d.instanceid, d.charvalue
                      FROM {customfield_data} d
                      JOIN {customfield_field} f ON f.id = d.fieldid
                      JOIN {customfield_category} c ON c.id = f.categoryid
                     WHERE f.shortname = ? AND c.component = ? AND c.area = ?
                   ) cd ON cd.instanceid = e.course
             WHERE e.id = ?
        ", [
            backup_helper::is_sqlparam('skilland_course_id'),
            backup_helper::is_sqlparam('core_course'),
            backup_helper::is_sqlparam('course'),
            backup::VAR_ACTIVITYID,
        ]);

        $lesson->set_source_table('skilland_lesson', ['skillandid' => backup::VAR_PARENTID]);

        // Define id annotations
        // The SCORM course module id (and each lesson's scoid) is remapped in
        // restore_skilland_activity_task::after_restore(), once every activity is restored.
        $skilland->annotate_ids('course_module', 'scormcmid');

        // Note: scorm_provisioned is a timestamp field, not an ID - no annotation needed.

        // Define file annotations.
        $skilland->annotate_files('mod_skilland', 'intro', null);

        return $this->prepare_activity_structure($skilland);
    }
}
