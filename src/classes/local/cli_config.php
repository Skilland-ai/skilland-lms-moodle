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
 * Validation and planning for the development-only cli/configure_api.php script.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure logic behind cli/configure_api.php: validates the options and returns the
 * config writes, so nothing is written when any option is invalid.
 */
class cli_config {
    /**
     * Validate a GraphQL endpoint URL.
     *
     * @param string $url
     * @param bool $allowinsecure True when --allow-insecure was passed.
     * @return string|null Null when valid, otherwise the error message.
     */
    public static function validate_endpoint(string $url, bool $allowinsecure): ?string {
        global $CFG;

        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return 'Invalid endpoint URL: expected an absolute http(s) URL.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'Invalid endpoint URL: expected an absolute http(s) URL.';
        }

        if ($scheme !== 'https' && !$allowinsecure && empty($CFG->mod_skilland_allow_http)) {
            return 'The endpoint must use https://. Pass --allow-insecure (or set $CFG->mod_skilland_allow_http) ' .
                'for a local http:// endpoint.';
        }

        return null;
    }

    /**
     * Whether an organization id only uses the PARAM_ALPHANUMEXT charset.
     *
     * @param string $orgid
     * @return bool
     */
    public static function valid_orgid(string $orgid): bool {
        return preg_match('/^[A-Za-z0-9_-]+$/', $orgid) === 1;
    }

    /**
     * Plan the config writes for the given CLI options.
     *
     * @param array $options Parsed CLI options (endpoint, orgid, allow-insecure).
     * @param string|null $apikey The API key read from the environment or the prompt, if any.
     * @return array<string, string> Config name => value, for the mod_skilland plugin.
     * @throws \InvalidArgumentException When any option is invalid; nothing must be written then.
     */
    public static function plan(array $options, ?string $apikey): array {
        $errors = [];
        $writes = [];

        $endpoint = trim((string) ($options['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $error = self::validate_endpoint($endpoint, !empty($options['allow-insecure']));
            if ($error !== null) {
                $errors[] = $error;
            } else {
                $writes['graphql_endpoint'] = $endpoint;
            }
        }

        $orgid = trim((string) ($options['orgid'] ?? ''));
        if ($orgid !== '') {
            if (!self::valid_orgid($orgid)) {
                $errors[] = 'Invalid organization id: only letters, digits, "_" and "-" are allowed.';
            } else {
                $writes['orgid'] = $orgid;
            }
        }

        $apikey = trim((string) $apikey);
        if ($apikey !== '') {
            $writes['apikey'] = $apikey;
        }

        if ($errors) {
            throw new \InvalidArgumentException(implode("\n", $errors));
        }

        return $writes;
    }
}
