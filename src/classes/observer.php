<?php
namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observers for mod_skilland.
 */
class observer {
    /**
     * Unlinks every activity of the course whose topic SCORM was just deleted.
     *
     * Never takes the provisioning lock: the event also fires from inside
     * skilland_update_topic_scorm(), which already holds it, and Moodle locks are not re-entrant.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        $other = $event->other ?? [];
        if (($other['modulename'] ?? null) !== 'scorm') {
            return;
        }

        require_once(__DIR__ . '/../lib.php');

        $linked = $DB->get_records('skilland', [
            'scormcmid' => $event->objectid,
            'course' => $event->courseid,
        ], '', 'id');
        foreach ($linked as $skilland) {
            skilland_unlink_scorm((int) $skilland->id);
            logger::info('SCORM', 'Unlinked deleted SCORM cmid ' . $event->objectid . ' from skilland id ' .
                $skilland->id);
        }
    }

    /**
     * A learner's lesson status or raw score was saved on a SCORM: when it is an activity's topic
     * SCORM, refresh that learner's progress and recompute completion and grade (SKL-668).
     *
     * Never takes the provisioning lock.
     *
     * @param \core\event\base $event A \mod_scorm\event\status_submitted or scoreraw_submitted event.
     */
    public static function scorm_tracking_submitted(\core\event\base $event): void {
        require_once(__DIR__ . '/../lib.php');

        $userid = (int) ($event->relateduserid ?: $event->userid);
        skilland_handle_scorm_tracking((int) $event->contextinstanceid, $userid);
    }
}
