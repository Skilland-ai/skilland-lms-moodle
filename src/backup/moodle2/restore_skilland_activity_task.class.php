<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/skilland/backup/moodle2/restore_skilland_stepslib.php');

/**
 * Skilland restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 */
class restore_skilland_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        $this->add_step(new restore_skilland_activity_structure_step('skilland_structure', 'skilland.xml'));
    }

    /**
     * Define the contents for this activity
     */
    static public function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('skilland', array('intro'), 'skilland');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    static public function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('SKILLANDVIEWBYID', '/mod/skilland/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('SKILLANDINDEX', '/mod/skilland/index.php?id=$1', 'course');

        return $rules;
    }

    /**
     * Define the restoring rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    static public function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('skilland', 'add', 'view.php?id={course_module}', '{name}');
        $rules[] = new restore_log_rule('skilland', 'update', 'view.php?id={course_module}', '{name}');
        $rules[] = new restore_log_rule('skilland', 'view', 'view.php?id={course_module}', '{name}');

        return $rules;
    }

    /**
     * Define the restoring rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    static public function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('skilland', 'view all', 'index.php?id={course}', '{course}');

        return $rules;
    }

    /**
     * Called after the restore process completes.
     * Re-provisions SCORM content if needed.
     */
    public function after_restore() {
        global $DB, $CFG;

        // Get the restored activity instance
        $skillandid = $this->get_activityid();
        $skilland = $DB->get_record('skilland', ['id' => $skillandid]);

        if (!$skilland) {
            return;
        }

        // Check if SCORM needs to be re-provisioned
        // This happens when the backup/restore couldn't map the original SCORM cmid
        if (empty($skilland->scormcmid) && !empty($skilland->skilland_topicid)) {
            // Load required functions
            require_once($CFG->dirroot . '/mod/skilland/locallib.php');

            try {
                // Get the course and course module
                $cm = get_coursemodule_from_instance('skilland', $skillandid, 0, false, MUST_EXIST);
                $course = get_course($cm->course);
                $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

                // Check if we have visible lessons to provision
                $hasvisiblelessons = $DB->record_exists('skilland_lesson', [
                    'skillandid' => $skillandid,
                    'visible' => 1
                ]);

                if ($hasvisiblelessons) {
                    // Provision the topic SCORM package
                    $scormcmid = skilland_provision_topic_scorm($skilland, $course, $sectionnum);

                    if ($scormcmid) {
                        debugging('Skilland: Successfully re-provisioned SCORM (cmid=' . $scormcmid . ') after restore for activity ' . $skillandid, DEBUG_DEVELOPER);
                    }
                } else {
                    debugging('Skilland: Skipping SCORM provisioning after restore - no visible lessons for activity ' . $skillandid, DEBUG_DEVELOPER);
                }
            } catch (Exception $e) {
                // Log the error but don't fail the restore
                debugging('Skilland: Failed to re-provision SCORM after restore: ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }
    }
}
