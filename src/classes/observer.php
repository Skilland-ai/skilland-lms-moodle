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

namespace mod_skilland;

/**
 * Event observers for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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

    /**
     * The course settings form saved: when this user created a SkilLand course for it (SKL-664),
     * offer the Studio link now. The pending path is consumed by sso_redirect.php, not here.
     *
     * @param \core\event\course_updated $event
     */
    public static function course_updated(\core\event\course_updated $event): void {
        require_once(__DIR__ . '/../locallib.php');

        if (!skilland_is_enabled()) {
            return;
        }
        $courseid = (int) $event->courseid;
        if (!$courseid || mod_skilland_peek_pending_studio_path($courseid) === null) {
            return;
        }

        $url = new \moodle_url('/mod/skilland/sso_redirect.php', [
            'courseid' => $courseid,
            'pending' => 1,
            'sesskey' => sesskey(),
        ]);
        \core\notification::info(\html_writer::link(
            $url,
            get_string('open_new_course_in_skilland', 'mod_skilland'),
            ['target' => '_blank']
        ));
    }
}
