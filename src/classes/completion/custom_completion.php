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

namespace mod_skilland\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules of mod_skilland (SKL-668).
 *
 * completionlessons: complete once every visible lesson has status completed or passed in the
 * progress store. Hidden lessons are ignored; an activity with no visible lesson never completes.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $this->cm->instance, 'visible' => 1], '', 'id');
        if (!$lessons) {
            return COMPLETION_INCOMPLETE;
        }

        $done = [];
        $rows = $DB->get_records(
            'skilland_progress',
            ['skillandid' => $this->cm->instance, 'userid' => $this->userid],
            '',
            'lessonid, status'
        );
        foreach ($rows as $row) {
            if (in_array($row->status, ['completed', 'passed'], true)) {
                $done[(int) $row->lessonid] = true;
            }
        }

        foreach ($lessons as $lesson) {
            if (!isset($done[(int) $lesson->id])) {
                return COMPLETION_INCOMPLETE;
            }
        }
        return COMPLETION_COMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return ['completionlessons'];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionlessons' => get_string('completiondetail:lessons', 'mod_skilland'),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionlessons',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
