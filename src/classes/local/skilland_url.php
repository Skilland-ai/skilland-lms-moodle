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
 * Normalisation of the Skilland URL settings.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

/**
 * The one place a stored Skilland URL (mod_skilland/graphql_endpoint, mod_skilland/frontend_url)
 * becomes the base URL every request, SSO post and Studio link is built from.
 */
class skilland_url {
    /**
     * Normalise a stored Skilland URL to the site's base URL.
     *
     * Trims whitespace, drops trailing slashes and a trailing /api/moodle or /graphql (any case),
     * so values saved for the legacy GraphQL endpoint or with the API prefix keep working.
     *
     * @param string $url The stored value.
     * @return string The base URL without a trailing slash, '' for an empty value.
     */
    public static function normalise(string $url): string {
        $url = rtrim(trim($url), '/');
        $url = (string) preg_replace('#/(?:api/moodle|graphql)$#i', '', $url);
        return rtrim($url, '/');
    }
}
