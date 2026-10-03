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
 * Uninstall hook for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove plugin-owned SCORM modules and the course mapping field.
 *
 * Moodle calls this hook before plugininfo_mod::uninstall_cleanup(), which deletes
 * Skilland's course module records directly without calling course_delete_module()
 * or skilland_delete_instance(). The mapping rows are still available here.
 *
 * @return bool Always true so a failed cleanup part does not prevent uninstall.
 */
function xmldb_skilland_uninstall(): bool {
    global $CFG;

    try {
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');
        skilland_uninstall_cleanup();
    } catch (\Throwable $e) {
        debugging('mod_skilland: uninstall cleanup failed: ' . $e->getMessage(), DEBUG_NORMAL);
    }

    return true;
}
