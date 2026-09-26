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
 * Admin settings for mod_skilland.
 *
 * Declarative only: this file runs on every admin page load, so it never writes to the
 * database. The course custom field is created on install, on upgrade and the first time
 * a course is mapped.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {
    require_once(__DIR__ . '/locallib.php');
    $field = skilland_get_course_customfield();
    $description = $field ? get_string('customfield_exists', 'mod_skilland') : get_string('customfield_missing', 'mod_skilland');

    $settings->add(new admin_setting_heading('mod_skilland/customfield_status',
        get_string('customfield_status', 'mod_skilland'),
        $description));

    // Verbose debug logging toggle.
    $settings->add(new admin_setting_configcheckbox('mod_skilland/devmode',
        get_string('settings_devmode', 'mod_skilland'),
        get_string('settings_devmode_desc', 'mod_skilland'), 0));

    // API Key setting (password type for security).
    $settings->add(new admin_setting_configpasswordunmask('mod_skilland/apikey',
        get_string('settings_apikey', 'mod_skilland'),
        get_string('settings_apikey_desc', 'mod_skilland'), ''));

    // Organization ID setting.
    $settings->add(new admin_setting_configtext('mod_skilland/orgid',
        get_string('settings_orgid', 'mod_skilland'),
        get_string('settings_orgid_desc', 'mod_skilland'), '', PARAM_ALPHANUMEXT));

    // GraphQL endpoint setting.
    $settings->add(new \mod_skilland\admin_setting_https_url('mod_skilland/graphql_endpoint',
        get_string('settings_graphql_endpoint', 'mod_skilland'),
        get_string('settings_graphql_endpoint_desc', 'mod_skilland'),
        'https://api.skilland.ai/graphql'));

    // Hosts SCORM packages may be downloaded from, besides the GraphQL endpoint host.
    $settings->add(new admin_setting_configtext('mod_skilland/package_hosts',
        get_string('settings_package_hosts', 'mod_skilland'),
        get_string('settings_package_hosts_desc', 'mod_skilland'),
        '*.skilland.ai, *.amazonaws.com', PARAM_TEXT));

    // Maximum SCORM package size in MB.
    $settings->add(new admin_setting_configtext('mod_skilland/package_max_mb',
        get_string('settings_package_max_mb', 'mod_skilland'),
        get_string('settings_package_max_mb_desc', 'mod_skilland'), 200, PARAM_INT));

    // Frontend URL setting (for SSO redirects).
    $settings->add(new \mod_skilland\admin_setting_https_url('mod_skilland/frontend_url',
        get_string('settings_frontend_url', 'mod_skilland'),
        get_string('settings_frontend_url_desc', 'mod_skilland'),
        'https://app.skilland.ai'));

    // SSO shared secret setting.
    $settings->add(new \mod_skilland\admin_setting_sso_secret('mod_skilland/sso_secret',
        get_string('settings_sso_secret', 'mod_skilland'),
        get_string('settings_sso_secret_desc', 'mod_skilland'), ''));
}
