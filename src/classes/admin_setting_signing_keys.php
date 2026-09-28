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
 * Admin setting for the extra SCORM package signing keys.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

use mod_skilland\local\package_signature;

/**
 * Textarea of keyid:base64publickey lines, trusted next to the pinned keys (SKL-650).
 *
 * The whole value is refused when any line is invalid, so a typo never silently drops a key.
 */
class admin_setting_signing_keys extends \admin_setting_configtextarea {
    /**
     * Validate the submitted keys.
     *
     * @param string $data
     * @return true|string True when every line is valid, otherwise a localized error message.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $errors = package_signature::parse_keys((string) $data)['errors'];
        if ($errors) {
            return get_string('error_signing_keys_invalid', 'mod_skilland', implode(', ', $errors));
        }
        return true;
    }
}
