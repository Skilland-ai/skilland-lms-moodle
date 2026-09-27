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
 * Exception raised when a SkilLand REST route fails.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * A moodle_exception that also carries the HTTP status of the failed request.
 *
 * Callers branch on $httpcode (0 when no response arrived) instead of parsing the message.
 */
class rest_exception extends \moodle_exception {

    /** @var int HTTP status of the response, 0 on a transport failure. Read-only: set by the constructor. */
    public $httpcode;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string identifier in mod_skilland.
     * @param int $httpcode HTTP status, 0 when no response arrived.
     * @param mixed $a Extra data for the language string.
     */
    public function __construct(string $errorcode, int $httpcode, $a = null) {
        $this->httpcode = $httpcode;
        parent::__construct($errorcode, 'mod_skilland', '', $a);
    }
}
