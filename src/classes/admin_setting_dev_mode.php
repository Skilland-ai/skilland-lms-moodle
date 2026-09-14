<?php
namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom admin setting for development mode with dynamic JS behavior.
 */
class admin_setting_dev_mode extends \admin_setting_configcheckbox {
    public function output_html($data, $query = '') {
        $html = parent::output_html($data, $query);

        global $PAGE;
        $PAGE->requires->js_call_amd('mod_skilland/settings', 'init');

        return $html;
    }
}
