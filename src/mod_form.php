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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/skilland/locallib.php');

use mod_skilland\logger;

/**
 * Module instance settings form for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_skilland_mod_form extends moodleform_mod {
    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $DB, $PAGE;

        $mform = $this->_form;

        // Get course custom field value for Skilland Course ID.
        $courseid = $this->get_course()->id;
        $skillandcourseid = skilland_get_course_customfield_value($courseid);

        // Get link to course settings for editing.
        $courseediturl = new moodle_url('/course/edit.php', ['id' => $courseid]);
        $courseediturl->set_anchor('id_course_customfields');

        // If Skilland Course ID is not set, show only a message and prevent form submission.
        if (empty($skillandcourseid)) {
            // Show a prominent message that Skilland Course ID must be set first.
            $linktext = get_string('set_skilland_course_id', 'mod_skilland');
            $link = html_writer::link($courseediturl, $linktext, [
                'class' => 'btn btn-primary',
                'target' => '_blank',
            ]);

            // Add a "Go to Skilland" button (SSO from this course, which has no Skilland course yet).
            $ssourl = new moodle_url('/mod/skilland/sso_redirect.php', ['courseid' => $courseid, 'sesskey' => sesskey()]);
            $golink = html_writer::link($ssourl, get_string('go_to_skilland', 'mod_skilland'), [
                'class' => 'btn btn-secondary ml-2',
                'target' => '_blank',
                'rel' => 'noopener',
            ]);

            $message = html_writer::div(
                html_writer::tag('p', get_string('skilland_course_id_required_message', 'mod_skilland')) .
                html_writer::tag('p', $link . $golink),
                // Skilland-missing-courseid: styles.css hides the rest of the form around it (without JS too).
                'alert alert-warning skilland-missing-courseid'
            );

            $mform->addElement('html', $message);

            // Add minimal required hidden fields for form structure.
            $mform->addElement('hidden', 'name', '');
            $mform->setType('name', PARAM_TEXT);
            $mform->addElement('hidden', 'intro', '');
            $mform->setType('intro', PARAM_RAW);
            $mform->addElement('hidden', 'introformat', FORMAT_HTML);
            $mform->setType('introformat', PARAM_INT);

            // Add standard coursemodule elements (required for form structure).
            $this->standard_coursemodule_elements();

            // Add only a cancel button - no save buttons since form can't be submitted.
            $this->add_action_buttons(false);

            // Hide all visible form fields and headers except our message: styles.css hides the
            // headers (scoped to a form holding .skilland-missing-courseid), the module the rest.
            $PAGE->requires->js_call_amd('mod_skilland/mod_form', 'init', [['missingcourseid' => true]]);

            // Don't add any other fields.
            return;
        }

        // Skilland Course ID is set - show the full form.

        // 1. Skilland-specific fields (MOVED TO TOP)
        $mform->addElement('header', 'skillandfieldset', get_string('pluginname', 'mod_skilland'));

        // Display Skilland Course ID as read-only with link to course settings.
        $displayvalue = format_string($skillandcourseid);
        $editlinktext = get_string('edit_course_settings', 'mod_skilland');
        $editlink = html_writer::link($courseediturl, $editlinktext, ['target' => '_blank']);
        $displaytext = $displayvalue . ' (' . $editlink . ')';

        $mform->addElement(
            'static',
            'skilland_course_id_display',
            get_string('skilland_course_id', 'mod_skilland'),
            $displaytext
        );
        $mform->addHelpButton('skilland_course_id_display', 'skilland_course_id', 'mod_skilland');

        // Topic ID field (always shown, depends on Skilland course).
        // This will be populated via AJAX based on Skilland course.
        $mform->addElement(
            'select',
            'skilland_topicid',
            get_string('topicid', 'mod_skilland'),
            ['' => get_string('loading', 'mod_skilland')]
        );
        $mform->setType('skilland_topicid', PARAM_ALPHANUMEXT);
        // No client-side 'required' rule: the topic select is populated by AJAX and stays
        // usable (loading, then a saved/stale option) while the SkilLand API is unreachable,
        // so an unrelated setting can still be saved. validation() below is the real guard.
        $mform->addHelpButton('skilland_topicid', 'topicid', 'mod_skilland');

        // Hidden field to remember last saved topic ID (used when select hasn't loaded yet).
        $mform->addElement('hidden', 'skilland_topicid_saved', '');
        $mform->setType('skilland_topicid_saved', PARAM_ALPHANUMEXT);

        // Hidden field to store topic order index (T1, T2, etc.).
        $mform->addElement('hidden', 'topic_orderindex', 1);
        $mform->setType('topic_orderindex', PARAM_INT);

        // Edit in Skilland button (only shown when topic is selected).
        $skillandbuttonhtml = '<div id="skilland-edit-button-container" class="form-group row fitem d-none">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label class="d-inline word-break">' . get_string('edit_in_skilland', 'mod_skilland') . '</label>
            </div>
            <div class="col-md-9 form-inline felement" data-fieldtype="html">
                <a href="#" id="skilland-edit-link" class="btn btn-secondary" target="_blank" rel="noopener">
                    <i class="fa fa-external-link"></i> ' . get_string('edit_lessons_in_skilland', 'mod_skilland') . '
                </a>
                <div class="form-text text-muted mt-2 w-100">
                    ' . get_string('edit_in_skilland_desc', 'mod_skilland') . '
                </div>
            </div>
        </div>';
        $mform->addElement('html', $skillandbuttonhtml);

        // Lessons Selection Container.
        $mform->addElement('html', '<div id="fitem_id_lessons_container" class="form-group row fitem">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label class="d-inline word-break">' . get_string('lessons', 'mod_skilland') . '</label>
            </div>
            <div class="col-md-9 form-inline felement" data-fieldtype="html">
                <div id="skilland-select-actions" class="mb-2 d-none">
                    <button type="button" id="skilland-select-all" class="btn btn-sm btn-outline-primary mr-2">
                        ' . get_string('select_all', 'mod_skilland') . '
                    </button>
                    <button type="button" id="skilland-select-none" class="btn btn-sm btn-outline-secondary">
                        ' . get_string('deselect_all', 'mod_skilland') . '
                    </button>
                </div>
                <div id="id_lessons_container" class="skilland-lessons-box w-100">
                    ' . get_string('select_topic_first', 'mod_skilland') . '
                </div>
                <div id="skilland-update-container" class="d-none">
                    <button type="button" id="skilland-update-btn">
                        <i class="fa fa-refresh"></i>
                        ' . get_string('update_from_skilland', 'mod_skilland') . '
                    </button>
                    <span id="skilland-update-loading" class="d-none">
                        <span class="spinner-border spinner-border-sm" role="status"></span>
                        ' . get_string('updating', 'mod_skilland') . '
                    </span>
                </div>
            </div>
        </div>');

        // Hidden field to store selected lessons (JSON).
        $mform->addElement('hidden', 'selected_lessons', '{}');
        $mform->setType('selected_lessons', PARAM_RAW);
        $mform->setDefault('selected_lessons', '{}');

        // 2. General settings (MOVED AFTER SKILLAND, HIDDEN via CSS)
        // We hide this section because the name and description are auto-filled from the selected topic.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        // Hidden via a JS-toggled class (styles.css), not unconditional CSS: the name field is
        // normally auto-filled from the selected topic, but when the topic fetch fails (or a
        // previous submit left a "name is required" error here) the section must stay visible so
        // the field - and any validation error on it - is never invisible to the teacher.

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('modulename', 'mod_skilland'), ['size' => '64']);
        if (!empty($CFG->formatstringstriptags)) {
            $mform->setType('name', PARAM_TEXT);
        } else {
            $mform->setType('name', PARAM_CLEANHTML);
        }
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // Adding the standard "intro" and "introformat" fields.
        $this->standard_intro_elements();

        // 3. Behavior flags
        $mform->addElement('header', 'behaviourfieldset', get_string('behaviour', 'mod_skilland'));

        $mform->addElement(
            'advcheckbox',
            'autoupdate',
            get_string('autoupdate', 'mod_skilland'),
            get_string('autoupdate_desc', 'mod_skilland')
        );
        $mform->setDefault('autoupdate', 0);

        $mform->addElement(
            'advcheckbox',
            'lockafterfirstaccess',
            get_string('lockafterfirstaccess', 'mod_skilland'),
            get_string('lockafterfirstaccess_desc', 'mod_skilland')
        );
        $mform->setDefault('lockafterfirstaccess', 0);

        // Shown only while auto-update is on and the lock is off - the combination that lets a
        // future sync silently wipe student progress.
        $mform->addElement(
            'static',
            'autoupdatewarning',
            '',
            html_writer::div(get_string('autoupdate_warning', 'mod_skilland'), 'alert alert-warning')
        );
        $mform->hideIf('autoupdatewarning', 'autoupdate', 'eq', 0);
        $mform->hideIf('autoupdatewarning', 'lockafterfirstaccess', 'eq', 1);

        $mform->addElement(
            'advcheckbox',
            'hidelabels',
            get_string('hidelabels', 'mod_skilland'),
            get_string('hidelabels_desc', 'mod_skilland')
        );
        $mform->setDefault('hidelabels', 0);

        // Grade (SKL-668): opt-in, "None" by default so the gradebook stays untouched.
        $this->standard_grading_coursemodule_elements();
        $mform->setDefault('grade', 0);

        // Add standard elements.
        $this->standard_coursemodule_elements();

        // Add standard buttons.
        $this->add_action_buttons();

        // Add JavaScript to populate topic dropdown and sync fields.
        // Get the Skilland Course ID from the course custom field.
        $skillandcourseid = skilland_get_course_customfield_value($courseid);
        if (!empty($skillandcourseid)) {
                    // Get current topic ID and selected lessons if editing.
            $currenttopicid = '';
            $currentselectedlessons = [];
            $hasscorm = false;
            $topicstudentattemptcount = 0;

            if (!empty($this->_instance)) {
                global $DB;
                $skilland = $DB->get_record('skilland', ['id' => $this->_instance]);
                if ($skilland) {
                    $currenttopicid = $skilland->skilland_topicid;
                    $hasscorm = !empty($skilland->scormcmid);
                    $topicstudentattemptcount = skilland_count_topic_student_attempts($skilland);
                    $mform->setDefault('skilland_topicid', $currenttopicid);
                    // Fetch existing selected lessons (visible=1).
                    $records = $DB->get_records(
                        'skilland_lesson',
                        ['skillandid' => $this->_instance, 'visible' => 1],
                        '',
                        'skilland_lessonid, updatedat, title'
                    );

                    foreach ($records as $record) {
                        $currentselectedlessons[$record->skilland_lessonid] = [
                            'updatedAt' => $record->updatedat,
                            'name' => $record->title,
                        ];
                    }
                }
            }

            // The saved lesson selection can outgrow js_call_amd's argument budget, so it reaches
            // the module as a JSON data attribute; everything else is in the init config.
            $mform->addElement('html', html_writer::div('', 'd-none', [
                'id' => 'skilland-current-lessons',
                'data-lessons' => json_encode($currentselectedlessons),
            ]));

            $PAGE->requires->js_call_amd('mod_skilland/mod_form', 'init', [[
                'debug' => (bool) get_config('mod_skilland', 'devmode'),
                'skillandcourseid' => (string) $skillandcourseid,
                'moodlecourseid' => (int) $this->get_course()->id,
                'currenttopicid' => (string) $currenttopicid,
                'currentlessonsid' => 'skilland-current-lessons',
                'hasscorm' => $hasscorm,
                // Destructive-confirmation copy (SKL-697): the module words both confirmation
                // dialogs as a progress-deleting action only when this count is above zero.
                'studentattemptcount' => (int) $topicstudentattemptcount,
                'skillandinstanceid' => (int) ($this->_instance ?? 0),
                'cmid' => (int) ($this->_cm->id ?? 0),
                'ssourl' => (new moodle_url('/mod/skilland/sso_redirect.php'))->out(false),
                // Trusted fragment built above by html_writer; the module re-renders it after the course name.
                'editlinkhtml' => $editlink,
            ]]);
        }
    }

    /**
     * Perform minimal validation on the settings form
     * @param array $data
     * @param array $files
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // The grade is computed as points; scales are not supported.
        if (isset($data['grade']) && (int) $data['grade'] < 0) {
            $errors['grade'] = get_string('error_grade_scale_unsupported', 'mod_skilland');
        }

        // Validate that course has Skilland Course ID custom field set.
        // This should not be needed if form is structured correctly, but keep as safety check.
        $courseid = $this->get_course()->id;
        $skillandcourseid = skilland_get_course_customfield_value($courseid);
        if (empty($skillandcourseid)) {
            $courseediturl = new moodle_url('/course/edit.php', ['id' => $courseid]);
            $courseediturl->set_anchor('id_course_customfields');
            $linktext = get_string('set_skilland_course_id', 'mod_skilland');
            $link = html_writer::link($courseediturl, $linktext);
            $errors['skilland_course_id_display'] = get_string('skilland_course_id_required', 'mod_skilland', $link);
        } else {
            // The submitted topic must belong to the skill this course is mapped to. The client-side
            // 'required' rule on skilland_topicid was removed (SKL-688) so an unrelated setting can
            // still be saved while the topic select couldn't load; this is the real, server-side guard
            // against an empty topic id ever reaching save.
            $topicid = !empty($data['skilland_topicid_saved'])
                ? $data['skilland_topicid_saved']
                : ($data['skilland_topicid'] ?? '');
            if ($topicid === '') {
                $errors['skilland_topicid'] = get_string('error_topicid_required', 'mod_skilland');
            } else {
                try {
                    if (!skilland_topic_belongs_to_course((string)$topicid, (string)$skillandcourseid)) {
                        $errors['skilland_topicid'] = get_string('error_course_not_mapped_to_skill', 'mod_skilland');
                    }
                } catch (moodle_exception $e) {
                    logger::error('Form', 'validation - topic check failed: ' . $e->getMessage());
                    $errors['skilland_topicid'] = mod_skilland_client_error_message($e);
                }

                // The submitted lessons must belong to the submitted topic. Against an empty lesson
                // list the helper returns every submitted ID, so this skips the fetch when none were sent.
                $selectedlessons = (string)($data['selected_lessons'] ?? '');
                if (empty($errors['skilland_topicid']) && skilland_lessons_outside_topic($selectedlessons, []) !== []) {
                    try {
                        $topiclessons = mod_skilland_fetch_lessons((string)$topicid);
                        if (skilland_lessons_outside_topic($selectedlessons, $topiclessons) !== []) {
                            $errors['skilland_topicid'] = get_string('error_lessons_not_in_topic', 'mod_skilland');
                        }
                    } catch (moodle_exception $e) {
                        // The topic check just passed against the same API: fail open rather than block the save.
                        logger::debug('Form', 'validation - lesson/topic check skipped: ' . $e->getMessage());
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Suffix Moodle 4.3+ appends to completion element names (the default completion form).
     *
     * @return string
     */
    private function completion_suffix(): string {
        return method_exists($this, 'get_suffix') ? $this->get_suffix() : '';
    }

    /**
     * Adds the "complete all lessons" completion rule.
     *
     * @return array Names of the added elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;

        $name = 'completionlessons' . $this->completion_suffix();
        $mform->addElement(
            'advcheckbox',
            $name,
            get_string('completionlessons', 'mod_skilland'),
            get_string('completionlessons_desc', 'mod_skilland')
        );
        $mform->addHelpButton($name, 'completionlessons', 'mod_skilland');
        $mform->setDefault($name, 0);

        return [$name];
    }

    /**
     * Whether the "complete all lessons" rule is enabled in the submitted data.
     *
     * @param array $data Submitted data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionlessons' . $this->completion_suffix()]);
    }

    /**
     * Clear the completion rule when automatic completion is turned off.
     *
     * @param stdClass $data Submitted data.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);

        if (!empty($data->completionunlocked)) {
            $suffix = $this->completion_suffix();
            $completion = $data->{'completion' . $suffix} ?? COMPLETION_TRACKING_NONE;
            if ((int) $completion !== COMPLETION_TRACKING_AUTOMATIC) {
                $data->{'completionlessons' . $suffix} = 0;
            }
        }
    }

    /**
     * Preprocess data before form display
     * @param array $defaultvalues
     */
    public function data_preprocessing(&$defaultvalues) {
        parent::data_preprocessing($defaultvalues);
        if (!empty($this->_instance)) {
            global $DB;
            if ($skilland = $DB->get_record('skilland', ['id' => $this->_instance])) {
                logger::debug('Form', 'data_preprocessing - skilland_topicid from DB = ' . ($skilland->skilland_topicid ?? 'NULL'));
                $defaultvalues['skilland_topicid'] = $skilland->skilland_topicid ?? '';
                $defaultvalues['skilland_topicid_saved'] = $skilland->skilland_topicid ?? '';
                $defaultvalues['topic_orderindex'] = $skilland->topic_orderindex ?? 1;
            }

            $records = $DB->get_records(
                'skilland_lesson',
                ['skillandid' => $this->_instance, 'visible' => 1],
                'orderindex ASC',
                'skilland_lessonid, title, updatedat'
            );

            if (!empty($records)) {
                $lessons = [];
                foreach ($records as $record) {
                    $lessons[$record->skilland_lessonid] = [
                        'name' => $record->title,
                        'updatedAt' => $record->updatedat,
                    ];
                }
                $defaultvalues['selected_lessons'] = json_encode($lessons);
            }
        }
        if (empty($defaultvalues['selected_lessons'])) {
            $defaultvalues['selected_lessons'] = '{}';
        }
    }
}
