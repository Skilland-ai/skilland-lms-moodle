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
 * Centralized logging utility for mod_skilland.
 *
 * Provides debug and error logging with consistent [Skilland] prefix.
 * Debug messages are only logged when devmode is enabled in plugin settings.
 * Error/warning messages are always logged.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

/**
 * Logger class for mod_skilland.
 *
 * Usage:
 *   \mod_skilland\logger::debug('REST', 'Starting GET ' . $url);
 *   \mod_skilland\logger::error('REST', 'HTTP error: ' . $httpcode);
 *   \mod_skilland\logger::warn('SCORM', 'SCO identifier not found for lesson ' . $id);
 */
class logger {
    /** @var bool|null Cached devmode value. */
    private static $devmode = null;

    /**
     * Check if debug mode (devmode) is enabled.
     *
     * @return bool True if devmode is enabled.
     */
    private static function is_debug_enabled(): bool {
        if (self::$devmode === null) {
            self::$devmode = (bool) get_config('mod_skilland', 'devmode');
        }
        return self::$devmode;
    }

    /**
     * Reset cached devmode value. Useful for testing or after config changes.
     */
    public static function reset_cache(): void {
        self::$devmode = null;
    }

    /**
     * Log a debug message. Only outputs when devmode is enabled AND Moodle debug is enabled.
     *
     * Use this for operational information that helps during development:
     * request/response details, step progress, data dumps, etc.
     *
     * @param string $component Short component name (e.g. 'REST', 'AJAX', 'SCORM').
     * @param string $message The message to log.
     */
    public static function debug(string $component, string $message): void {
        if (self::is_debug_enabled()) {
            debugging('[Skilland] [' . $component . '] ' . $message, DEBUG_DEVELOPER);
        }
    }

    /**
     * Log an informational message. Outputs when Moodle debug level is DEBUG_NORMAL or higher.
     *
     * Use this for operational summaries and progress updates:
     * task start/end, record counts, successful completions.
     *
     * @param string $component Short component name (e.g. 'SyncContent', 'SCORM').
     * @param string $message The message to log.
     */
    public static function info(string $component, string $message): void {
        debugging('[Skilland] [' . $component . '] ' . $message, DEBUG_NORMAL);
    }

    /**
     * Log a warning message. Outputs when Moodle debug level is DEBUG_NORMAL or higher.
     *
     * Use this for unexpected but non-critical situations:
     * missing mappings, fallback behavior, deprecation notices.
     *
     * @param string $component Short component name (e.g. 'REST', 'AJAX', 'SCORM').
     * @param string $message The message to log.
     */
    public static function warn(string $component, string $message): void {
        debugging('[Skilland] [' . $component . '] WARNING: ' . $message, DEBUG_NORMAL);
    }

    /**
     * Log an error message. Outputs when Moodle debug level is DEBUG_MINIMAL or higher.
     *
     * Use this for actual errors, exceptions, and failures that need attention.
     *
     * @param string $component Short component name (e.g. 'REST', 'AJAX', 'SCORM').
     * @param string $message The message to log.
     */
    public static function error(string $component, string $message): void {
        debugging('[Skilland] [' . $component . '] ERROR: ' . $message, DEBUG_MINIMAL);
    }
}
