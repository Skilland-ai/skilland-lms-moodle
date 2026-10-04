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
 * Safe Moodle event recording for failed REST requests.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

use mod_skilland\event\api_request_failed;
use mod_skilland\rest_exception;

/**
 * Records only a route shape, numeric status, and fixed failure category.
 */
class api_failure_event_recorder {
    /**
     * Keep known route words while replacing every variable segment.
     *
     * @param string $path Requested route, possibly with a query or fragment.
     * @return string Safe route shape.
     */
    public static function safe_path(string $path): string {
        $path = substr($path, 0, strcspn($path, '?#'));
        $allowed = ['api', 'moodle', 'skills', 'topics', 'users', 'courses', 'contents', 'lessons',
            'scorm', 'scorm-hash', 'snapshot', 'check', 'create'];
        $segments = explode('/', $path);
        foreach ($segments as &$segment) {
            if ($segment !== '' && !in_array($segment, $allowed, true)) {
                $segment = '[redacted]';
            }
        }
        unset($segment);
        return implode('/', $segments);
    }

    /**
     * Event recording is best effort; the original request failure must still reach its caller.
     *
     * @param string $path Requested route.
     * @param \Throwable $failure Original request failure.
     */
    public static function record(string $path, \Throwable $failure): void {
        $httpcode = 0;
        $errorclass = 'transport';
        if ($failure instanceof rest_exception) {
            $httpcode = $failure->httpcode;
            $errorclass = $failure->errorcode === 'error_graphql_invalid_json' ? 'decode' :
                ($httpcode === 0 ? 'transport' : 'http');
        } else if ($failure instanceof \moodle_exception) {
            $errorclass = $failure->errorcode === 'error_http_redirect' ? 'redirect' : 'config';
            if ($errorclass === 'redirect') {
                $httpcode = (int) $failure->a;
            }
        }

        try {
            api_request_failed::create([
                'context' => \context_system::instance(),
                'other' => [
                    'path' => self::safe_path($path),
                    'httpcode' => $httpcode,
                    'errorclass' => $errorclass,
                ],
            ])->trigger();
        } catch (\Throwable $ignored) {
            // Moodle event storage is best effort during an already failed request.
        }
    }
}
