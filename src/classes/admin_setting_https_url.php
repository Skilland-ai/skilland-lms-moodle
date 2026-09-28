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
 * Admin setting for a URL that must use HTTPS.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * Text setting (PARAM_URL) that requires the https:// scheme.
 *
 * Plain http:// is accepted only when $CFG->mod_skilland_allow_http is set in config.php,
 * which the local development stack does.
 */
class admin_setting_https_url extends \admin_setting_configtext {

    /**
     * Constructor.
     *
     * @param string $name
     * @param string $visiblename
     * @param string $description
     * @param string $defaultsetting
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_URL);
    }

    /**
     * Validate the submitted URL.
     *
     * @param string $data
     * @return true|string True when valid, otherwise a localized error message.
     */
    public function validate($data) {
        global $CFG;

        if ($data === '' || $data === null) {
            return parent::validate($data);
        }

        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        if (!empty($CFG->mod_skilland_allow_http)) {
            return true;
        }

        if (strtolower((string) parse_url(trim($data), PHP_URL_SCHEME)) !== 'https') {
            return get_string('error_url_https_required', 'mod_skilland');
        }

        return true;
    }
}
