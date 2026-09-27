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
 * Injectable pause between GraphQL retries.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Sleeps between retries. Tests replace it with \core\di::set(retry_sleeper::class, $recorder)
 * to record the delays instead of waiting.
 */
class retry_sleeper {
    /**
     * Sleep for the given number of milliseconds.
     *
     * @param int $ms Delay in milliseconds; negative values sleep for zero.
     */
    public function sleep_ms(int $ms): void {
        usleep(max(0, $ms) * 1000);
    }
}
