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
 * Production transport to the SkilLand API over HTTPS.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

defined('MOODLE_INTERNAL') || die();

/**
 * The real {@see api_client}: Moodle's curl against the configured SkilLand URLs.
 */
class http_api_client implements api_client {
    /**
     * Load the transport functions from locallib.php.
     */
    public function __construct() {
        require_once(__DIR__ . '/../../locallib.php');
    }

    /**
     * {@inheritDoc}
     */
    public function graphql(string $query, array $variables = []): array {
        return mod_skilland_graphql_http($query, $variables);
    }

    /**
     * {@inheritDoc}
     */
    public function rest_get(string $path): array {
        return mod_skilland_rest_get_http($path);
    }

    /**
     * {@inheritDoc}
     */
    public function download_package(string $packageurl, int $expectedsize): string {
        return mod_skilland_download_package_http($packageurl, $expectedsize);
    }
}
