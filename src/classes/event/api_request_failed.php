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
 * Event for a terminal Skilland REST request failure.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\event;

/**
 * Safe, site-wide operational event without a user or activity payload.
 */
class api_request_failed extends \core\event\base {
    /**
     * Initialise event metadata.
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventapi_request_failed', 'mod_skilland');
    }

    /**
     * Return a description containing only the safe request details.
     *
     * @return string
     */
    public function get_description() {
        $path = \mod_skilland\local\api_failure_event_recorder::safe_path((string) ($this->other['path'] ?? ''));
        $httpcode = (int) ($this->other['httpcode'] ?? 0);
        $errorclass = (string) ($this->other['errorclass'] ?? 'transport');
        if (!in_array($errorclass, ['http', 'transport', 'redirect', 'decode', 'config'], true)) {
            $errorclass = 'transport';
        }
        return 'A Skilland API request to ' . $path . ' failed (HTTP ' . $httpcode . ', ' . $errorclass . ').';
    }
}
