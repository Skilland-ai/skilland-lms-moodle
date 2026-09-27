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
 * Transport to the SkilLand API, resolved through the Moodle DI container.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Every call the plugin makes to SkilLand goes through this interface.
 *
 * Production binds it to {@see http_api_client} in {@see \mod_skilland\hooks::di_configuration()}.
 * Tests replace it with \core\di::set(api_client::class, $fake); production code never checks for
 * test hooks. mod_skilland_graphql() and mod_skilland_download_package() are the public entry
 * points and forward here.
 */
interface api_client {
    /**
     * Execute a GraphQL query or mutation against the configured SkilLand endpoint.
     *
     * Read queries are retried on transient failures; mutations are sent once.
     *
     * @param string $query The GraphQL document.
     * @param array $variables Query variables; an empty array is sent as an empty JSON object.
     * @return array The decoded `data` member of the response (an empty array when absent).
     * @throws \moodle_exception When the configuration is missing, the endpoint is not HTTPS, the
     *     server redirects or answers a non-2xx status, or the body is not valid JSON.
     * @throws \mod_skilland\graphql_exception When the response carries a GraphQL error.
     */
    public function graphql(string $query, array $variables = []): array;

    /**
     * Download a SCORM package to a temp file after validating its URL, size and format.
     *
     * @param string $packageurl HTTPS URL on an allowed package host.
     * @param int $expectedsize Size in bytes the API announced for the package; 0 skips the check.
     * @return string Path of the downloaded zip; the caller deletes it.
     * @throws \moodle_exception On any failed check; the temp file is removed first.
     */
    public function download_package(string $packageurl, int $expectedsize): string;
}
