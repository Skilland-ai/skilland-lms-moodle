<?php
defined('MOODLE_INTERNAL') || die;

// Handle custom field creation request - must be before $ADMIN->fulltree check.
$createfield = optional_param('createcustomfield', 0, PARAM_INT);
if ($createfield && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    require_once(__DIR__ . '/locallib.php');

    $field = skilland_ensure_course_customfield();
    if ($field) {
        redirect(new moodle_url('/admin/settings.php', ['section' => 'modsettingskilland']),
            get_string('customfield_created', 'mod_skilland'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        redirect(new moodle_url('/admin/settings.php', ['section' => 'modsettingskilland']),
            get_string('customfield_create_failed', 'mod_skilland'), null, \core\output\notification::NOTIFY_ERROR);
    }
}

if ($ADMIN->fulltree) {
    require_once(__DIR__ . '/locallib.php');
    // Check if custom field exists.
    $field = skilland_get_course_customfield();

    // Custom field status and creation button.
    $fieldstatus = $field ? get_string('customfield_exists', 'mod_skilland') : get_string('customfield_missing', 'mod_skilland');
    $description = $fieldstatus;
    if (!$field) {
        $createurl = new moodle_url('/admin/settings.php', [
            'section' => 'modsettingskilland',
            'createcustomfield' => 1,
            'sesskey' => sesskey()
        ]);
        $description .= ' ' . html_writer::link($createurl, get_string('create_customfield_button', 'mod_skilland'),
            ['class' => 'btn btn-primary btn-sm']);
    }

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
        get_string('settings_orgid_desc', 'mod_skilland'), '', PARAM_TEXT));

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
