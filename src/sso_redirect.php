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
 * SSO handoff page - generates an SSO token and POSTs it to Skilland
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

use mod_skilland\logger;

// Get parameters.
// $courseid is the Moodle course ID; the Skilland skill ID is resolved from it server-side.
$topicid  = optional_param('topicid', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
// pending=1 comes from the post-save notification (observer::course_updated, SKL-664): open the
// Studio path stored when the course was created from Moodle, once.
$pending  = optional_param('pending', false, PARAM_BOOL);

// Require login and a valid session key.
require_login();
require_sesskey();

global $USER;

// If a Moodle course ID was provided, verify the user has permission to manage Skilland
// activities in that course before granting SSO access.
$skillandcourseid = '';
if (!empty($courseid)) {
    $course  = get_course($courseid); // Throws dml_missing_record_exception if not found.
    $context = context_course::instance($courseid);
    require_login($course);
    require_capability('mod/skilland:accessstudio', $context);

    // Resolve the Skilland skill ID from the Moodle course mapping.
    $skillandcourseid = skilland_get_course_customfield_value($courseid);
    if (empty($skillandcourseid)) {
        $skillandcourseid = skilland_get_skilland_courseid($courseid) ?: '';
    }
}

try {
    // Get organization ID from plugin settings
    $orgid = get_config('mod_skilland', 'orgid');
    if (empty($orgid)) {
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }

    // Generate SSO token
    $token = skilland_generate_sso_token($USER, $orgid);

    // Construct redirect URL: /skills-studio/:skillId/topics/:topicId
    $redirect = '/skills-studio';
    if (!empty($skillandcourseid)) {
        $redirect .= '/' . urlencode($skillandcourseid);
    }
    if (!empty($topicid)) {
        $redirect .= '/topics/' . urlencode($topicid);
    }
    if (!empty($courseid) && $pending) {
        $pendingpath = mod_skilland_take_pending_studio_path($courseid);
        if ($pendingpath !== null) {
            $redirect = $pendingpath;
        }
    }

    logger::debug('SSO', 'Handing user ' . $USER->id . ' off to topic ' . $topicid);

    // POST the token to SkilLand from a self-submitting form, so it never appears in a URL.
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Content-Type: text/html; charset=utf-8');
    echo skilland_render_sso_post_form($token, $redirect);
    die();

} catch (moodle_exception $e) {
    // Carries its own localized string (error_sso_user_not_allowed, error_config_missing_orgid):
    // rethrow it as is so the user reads that message rather than a generic error.
    logger::error('SSO', $e->errorcode . (empty($e->debuginfo) ? '' : ' - ' . $e->debuginfo));
    throw $e;
} catch (Exception $e) {
    logger::error('SSO', $e->getMessage());

    // Show error to user using Moodle's exception handling
    throw new moodle_exception('error', 'mod_skilland', '', null, $e->getMessage());
}
