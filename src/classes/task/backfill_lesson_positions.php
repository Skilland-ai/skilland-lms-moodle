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

namespace mod_skilland\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Ad-hoc task, queued by the 2026100209 upgrade (SKL-694), that stores each lesson's position in
 * its topic in Skilland (`skilland_lesson.skillandposition`, the number in its lesson code).
 *
 * For every activity with a lesson whose position is still unknown (0), it fetches the topic's
 * lessons from Skilland and sets the position of each stored lesson found there. An activity
 * whose fetch fails keeps 0 (its codes fall back to `orderindex`) and the others go on; the next
 * content update of that activity stores the positions too.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backfill_lesson_positions extends \core\task\adhoc_task {
    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_backfill_lesson_positions', 'mod_skilland');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $config = get_config('mod_skilland');
        $nourl = mod_skilland_get_skilland_url() === '' &&
            \mod_skilland\local\skilland_url::normalise((string) ($config->frontend_url ?? '')) === '';
        if (empty($config->apikey) || empty($config->orgid) || $nourl) {
            mtrace('mod_skilland: plugin not configured, lesson positions not backfilled');
            return;
        }

        $ids = $DB->get_fieldset_sql(
            'SELECT DISTINCT skillandid FROM {skilland_lesson} WHERE skillandposition = 0 ORDER BY skillandid'
        );
        foreach ($ids as $id) {
            $this->backfill_activity((int) $id);
        }
    }

    /**
     * Store the Skilland positions of one activity's lessons.
     *
     * @param int $skillandid The skilland activity id.
     * @return int The number of lessons whose position was stored.
     */
    public function backfill_activity(int $skillandid): int {
        global $DB;

        $skilland = $DB->get_record('skilland', ['id' => $skillandid], 'id, skilland_topicid', IGNORE_MISSING);
        if (!$skilland || (string) ($skilland->skilland_topicid ?? '') === '') {
            return 0;
        }

        try {
            $lessons = mod_skilland_fetch_lessons((string) $skilland->skilland_topicid);
        } catch (\Throwable $e) {
            // The activity id only: no API text, which can carry upstream data.
            mtrace('mod_skilland: could not backfill lesson positions for activity ' . $skillandid .
                ' (' . get_class($e) . ')');
            return 0;
        }

        $stored = 0;
        foreach ($lessons as $lesson) {
            $position = (int) ($lesson['position'] ?? 0);
            if ($position <= 0) {
                continue;
            }
            $where = ['skillandid' => $skillandid, 'skilland_lessonid' => (string) $lesson['id']];
            if ($DB->record_exists('skilland_lesson', $where)) {
                $DB->set_field('skilland_lesson', 'skillandposition', $position, $where);
                $stored++;
            }
        }
        return $stored;
    }
}
