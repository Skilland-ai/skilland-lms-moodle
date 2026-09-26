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


namespace mod_skilland\external;

use core_external\external_api;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Base class of the mod_skilland web services.
 *
 * Every function lives in its own class under classes/external/ and, in execute(), calls
 * self::require_enabled(), validate_parameters(), resolves its context, then
 * self::validate_context() before require_capability().
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Throw an exception if the Skilland module is disabled.
     *
     * @throws \moodle_exception
     */
    protected static function require_enabled(): void {
        if (!skilland_is_enabled()) {
            throw new \moodle_exception('error_plugin_disabled', 'mod_skilland');
        }
    }
}
