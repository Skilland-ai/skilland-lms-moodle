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
 * Admin setting for the SSO shared secret.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * Password setting that rejects short secrets and secrets that were ever shipped as dev defaults.
 */
class admin_setting_sso_secret extends \admin_setting_configpasswordunmask {

    /** Minimum secret length in bytes (after base64 decoding when the value is base64). */
    const MIN_SECRET_BYTES = 32;

    /** SHA-256 hex digests of secrets that were published in earlier releases and must never be used. */
    const KNOWN_DEV_SECRET_HASHES = [
        '09d94ce8058bdf77e105ec64188ef85559577523044b43033d67f7ad59deb380',
    ];

    /**
     * Validate the submitted secret.
     *
     * @param string $data
     * @return true|string True when valid, otherwise a localized error message.
     */
    public function validate($data) {
        if ($data === '' || $data === null) {
            return true;
        }

        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }

        $decoded = base64_decode($data, true);
        if (strlen($decoded ?: $data) < self::MIN_SECRET_BYTES) {
            return get_string('error_sso_secret_too_short', 'mod_skilland');
        }

        if (in_array(hash('sha256', $data), $this->known_dev_secret_hashes(), true)) {
            return get_string('error_sso_secret_known_dev', 'mod_skilland');
        }

        return true;
    }

    /**
     * Digests of secrets that are publicly known and therefore rejected.
     *
     * @return string[]
     */
    protected function known_dev_secret_hashes(): array {
        return self::KNOWN_DEV_SECRET_HASHES;
    }
}
