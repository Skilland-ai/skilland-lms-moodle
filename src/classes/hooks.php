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

namespace mod_skilland;

/**
 * Hook callbacks for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hooks {
    /**
     * Bind the plugin's injectable services in the Moodle DI container.
     *
     * The Skilland transport is the real HTTPS client, except on a Behat site, where the
     * fixture client (when installed) answers from canned responses instead of the network.
     *
     * @param \core\hook\di_configuration $hook
     */
    public static function di_configuration(\core\hook\di_configuration $hook): void {
        $hook->add_definition(
            \mod_skilland\local\api_client::class,
            function (): \mod_skilland\local\api_client {
                $fixture = \mod_skilland\local\testing\fixture_api_client::class;
                if (defined('BEHAT_SITE_RUNNING') && BEHAT_SITE_RUNNING && class_exists($fixture)) {
                    return new $fixture();
                }
                return new \mod_skilland\local\http_api_client();
            },
        );
    }

    /**
     * Callback for before_footer_html_generation hook.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(\core\hook\output\before_footer_html_generation $hook) {
        global $PAGE;

        require_once(__DIR__ . '/../locallib.php');
        if (!skilland_is_enabled()) {
            return;
        }

        // Only run on course edit page.
        $url = $PAGE->url;
        if ($url && strpos($url->get_path(), '/course/edit.php') !== false) {
            $courseid = optional_param('id', 0, PARAM_INT);
            if (!$courseid && isset($PAGE->course->id)) {
                $courseid = $PAGE->course->id;
            }

            if ($courseid) {
                // Turn the Skilland course ID custom field into a dropdown plus an explicit
                // "Create in Skilland" button (SKL-664), and add a "Go to Skilland" button above it.
                // The strings are fetched by the module through core/str.
                $course = get_course($courseid);
                $ssourl = new \moodle_url('/mod/skilland/sso_redirect.php', [
                    'courseid' => $courseid,
                    'sesskey' => sesskey(),
                ]);
                $PAGE->requires->js_call_amd('mod_skilland/course_mapping', 'init', [[
                    'courseid' => (int) $courseid,
                    'coursename' => format_string($course->fullname, true, ['escape' => false]),
                    'linked' => !empty(skilland_get_course_customfield_value($courseid)),
                    'ssourl' => $ssourl->out(false),
                    'debug' => (bool) get_config('mod_skilland', 'devmode'),
                ]]);
            }
        }
    }
}
