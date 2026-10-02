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
 * Tells the teachers of an activity that new Skilland content is waiting to be applied.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

/**
 * Sends the contentupdate notification (SKL-650). Callers resolve it with \core\di::get() so
 * tests can replace it with \core\di::set(update_notifier::class, $fake).
 */
class update_notifier {
    /**
     * Notify every active participant of the course who may apply the update (mod/skilland:provision).
     *
     * @param \stdClass $skilland The skilland activity record.
     * @param \stdClass $cm The skilland course module.
     * @param \stdClass $course The Moodle course record.
     * @return int Number of users a message was sent to.
     */
    public function notify(\stdClass $skilland, \stdClass $cm, \stdClass $course): int {
        $context = \context_module::instance($cm->id);
        $recipients = get_enrolled_users($context, 'mod/skilland:provision', 0, 'u.*', null, 0, 0, true);
        if (!$recipients) {
            return 0;
        }

        $url = new \moodle_url('/mod/skilland/view.php', ['id' => $cm->id]);
        $a = (object) [
            'activity' => format_string($skilland->name, true, ['context' => $context, 'escape' => false]),
            'course' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
            'url' => $url->out(false),
        ];

        $sent = 0;
        foreach ($recipients as $user) {
            $message = new \core\message\message();
            $message->component = 'mod_skilland';
            $message->name = 'contentupdate';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = get_string('update_notification_subject', 'mod_skilland', $a);
            $message->fullmessage = get_string('update_notification_body', 'mod_skilland', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '';
            $message->smallmessage = get_string('update_notification_small', 'mod_skilland', $a);
            $message->notification = 1;
            $message->contexturl = $a->url;
            $message->contexturlname = $a->activity;
            $message->courseid = $course->id;
            if (message_send($message)) {
                $sent++;
            }
        }
        return $sent;
    }
}
