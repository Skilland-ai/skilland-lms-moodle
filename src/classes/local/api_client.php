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
 * Transport to the Skilland API, resolved through the Moodle DI container.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

/**
 * Every call the plugin makes to Skilland goes through this interface.
 *
 * Production binds it to {@see http_api_client} in {@see \mod_skilland\hooks::di_configuration()}.
 * Tests replace it with \core\di::set(api_client::class, $fake); production code never checks for
 * test hooks. mod_skilland_rest_get(), mod_skilland_rest_post() and mod_skilland_download_package() are the
 * public entry points and forward here.
 */
interface api_client {
    /**
     * GET a Skilland REST route below the frontend URL, authenticated with the API key as a Bearer token.
     *
     * Transient failures are retried; redirects are refused.
     *
     * @param string $path Route path, e.g. /api/moodle/topics/{id}/scorm-hash.
     * @return array The decoded JSON body.
     * @throws \moodle_exception When the API key is missing, the URL is not HTTPS or the server redirects.
     * @throws \mod_skilland\rest_exception On a non-2xx status or a transport failure (httpcode 0), or
     *     when a 2xx body is not JSON.
     */
    public function rest_get(string $path): array;

    /**
     * POST a JSON body to a Skilland REST route below the frontend URL, authenticated like rest_get().
     *
     * Sent exactly once: a POST is never retried. Redirects are refused.
     *
     * @param string $path Route path, e.g. /api/moodle/skills.
     * @param array $body The JSON body.
     * @return array The decoded JSON answer.
     * @throws \moodle_exception When the API key is missing, the URL is not HTTPS or the server redirects.
     * @throws \mod_skilland\rest_exception On a non-2xx status (with the answer's `error` code) or a
     *     transport failure (httpcode 0), or when a 2xx body is not JSON.
     */
    public function rest_post(string $path, array $body): array;

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
