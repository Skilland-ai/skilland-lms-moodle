<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/skilland/backup/moodle2/backup_skilland_stepslib.php');

/**
 * Skilland backup task that provides all the settings and steps to perform one
 * complete backup of the activity
 */
class backup_skilland_activity_task extends backup_activity_task {

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
        $this->add_step(new backup_skilland_activity_structure_step('skilland_structure', 'skilland.xml'));
    }

    /**
     * Code the transformations to perform in the activity in
     * order to get transportable (encoded) links
     */
    static public function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, "/");

        // Link to the list of skillands for a course
        $search = "/(" . $base . "\/mod\/skilland\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SKILLANDINDEX*$2@$', $content);

        // Link to skilland view by moduleid
        $search = "/(" . $base . "\/mod\/skilland\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@SKILLANDVIEWBYID*$2@$', $content);

        return $content;
    }
}
