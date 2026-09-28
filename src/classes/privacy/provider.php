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

namespace mod_skilland\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy API provider for mod_skilland.
 *
 * Declares the personal data sent to SkilLand (SSO sign-in, listing and creating SkilLand
 * courses) and exports and deletes the per-learner progress kept in skilland_progress.
 * Any new field sent to SkilLand or any new table with a userid column must be declared here;
 * tests/phpunit/privacy_provider_test.php enforces it.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\provider, \core_privacy\local\request\core_userlist_provider, \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored locally and sent to SkilLand.
     *
     * @param collection $collection The collection to add metadata to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('skilland_progress', [
            'userid' => 'privacy:metadata:skilland_progress:userid',
            'lessonid' => 'privacy:metadata:skilland_progress:lessonid',
            'status' => 'privacy:metadata:skilland_progress:status',
            'score' => 'privacy:metadata:skilland_progress:score',
            'timemodified' => 'privacy:metadata:skilland_progress:timemodified',
        ], 'privacy:metadata:skilland_progress');

        $collection->add_external_location_link('skilland', [
            'userid' => 'privacy:metadata:skilland:userid',
            'email' => 'privacy:metadata:skilland:email',
            'fullname' => 'privacy:metadata:skilland:fullname',
            'role' => 'privacy:metadata:skilland:role',
            'courseaccess' => 'privacy:metadata:skilland:courseaccess',
        ], 'privacy:metadata:skilland');

        return $collection;
    }

    /**
     * The module contexts of the Skilland activities where the user has progress.
     *
     * @param int $userid The user to search for.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {skilland_progress} sp ON sp.skillandid = cm.instance
                 WHERE sp.userid = :userid";
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'skilland',
            'userid' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * The users with progress in the Skilland activity of the given context.
     *
     * @param userlist $userlist The userlist to add the users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $skillandid = self::get_skillandid($userlist->get_context());
        if ($skillandid === null) {
            return;
        }

        $rows = $DB->get_records('skilland_progress', ['skillandid' => $skillandid], '', 'id, userid');
        $userlist->add_users(array_values(array_unique(array_map(fn($row) => (int) $row->userid, $rows))));
    }

    /**
     * Export the user's lesson progress in each approved Skilland activity.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $skillandid = self::get_skillandid($context);
            if ($skillandid === null) {
                continue;
            }

            $rows = $DB->get_records('skilland_progress', ['skillandid' => $skillandid, 'userid' => $userid]);
            if (!$rows) {
                continue;
            }
            $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $skillandid]);

            $exported = [];
            foreach ($rows as $row) {
                $lesson = $lessons[$row->lessonid] ?? null;
                $exported[] = (object) [
                    'lesson' => $lesson ? format_string($lesson->title) : '',
                    'status' => $row->status,
                    'score' => $row->score,
                    'timemodified' => transform::datetime($row->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('privacy:path:progress', 'mod_skilland')],
                (object) ['lessons' => $exported]
            );
        }
    }

    /**
     * Delete every user's progress in the Skilland activity of the given context.
     *
     * @param \context $context The context to delete data in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $skillandid = self::get_skillandid($context);
        if ($skillandid === null) {
            return;
        }
        $DB->delete_records('skilland_progress', ['skillandid' => $skillandid]);
    }

    /**
     * Delete the user's progress in each approved Skilland activity.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete data in.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = (int) $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $skillandid = self::get_skillandid($context);
            if ($skillandid === null) {
                continue;
            }
            $DB->delete_records('skilland_progress', ['skillandid' => $skillandid, 'userid' => $userid]);
        }
    }

    /**
     * Delete the progress of the approved users in the Skilland activity of the given context.
     *
     * @param approved_userlist $userlist The approved users and context.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $skillandid = self::get_skillandid($userlist->get_context());
        $userids = $userlist->get_userids();
        if ($skillandid === null || empty($userids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        $params['skillandid'] = $skillandid;
        $DB->delete_records_select('skilland_progress', "skillandid = :skillandid AND userid $insql", $params);
    }

    /**
     * The skilland.id behind a module context, or null for any other context.
     *
     * @param \context $context
     * @return int|null
     */
    private static function get_skillandid(\context $context): ?int {
        if ((int) $context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }
        $cm = get_coursemodule_from_id('skilland', $context->instanceid);
        if (!$cm) {
            return null;
        }
        return (int) $cm->instance;
    }
}
