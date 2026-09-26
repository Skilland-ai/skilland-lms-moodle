<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');
require_once($CFG->dirroot.'/mod/skilland/locallib.php');

use mod_skilland\logger;

/**
 * Module instance settings form for mod_skilland.
 */
class mod_skilland_mod_form extends moodleform_mod {

    /**
     * Defines forms elements
     */
    public function definition() {
        global $CFG, $DB;

        $mform = $this->_form;

        // Get course custom field value for Skilland Course ID.
        $courseid = $this->get_course()->id;
        $skillandcourseid = skilland_get_course_customfield_value($courseid);

        // Get link to course settings for editing.
        $courseediturl = new moodle_url('/course/edit.php', array('id' => $courseid));
        $courseediturl->set_anchor('id_course_customfields');

        // If Skilland Course ID is not set, show only a message and prevent form submission.
        if (empty($skillandcourseid)) {
            // Show a prominent message that Skilland Course ID must be set first.
            $linktext = get_string('set_skilland_course_id', 'mod_skilland');
            $link = html_writer::link($courseediturl, $linktext, array(
                'class' => 'btn btn-primary',
                'target' => '_blank'
            ));

            // Add a "Go to Skilland" button (SSO without a specific course).
            $ssourl = new moodle_url('/mod/skilland/sso_redirect.php', ['sesskey' => sesskey()]);
            $golink = html_writer::link($ssourl, get_string('go_to_skilland', 'mod_skilland'), array(
                'class' => 'btn btn-secondary ml-2',
                'target' => '_blank',
                'rel' => 'noopener'
            ));

            $message = html_writer::div(
                html_writer::tag('p', get_string('skilland_course_id_required_message', 'mod_skilland')) .
                html_writer::tag('p', $link . $golink),
                'alert alert-warning',
                array('style' => 'margin: 20px 0; padding: 20px;')
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

            // Hide all visible form fields and headers except our message using JavaScript and CSS.
            $mform->addElement('html', '<style>
                /* Hide all form sections and headers when Skilland Course ID is not set */
                form.mform .fheader,
                form.mform .fitem_fheader,
                form.mform [class*="fheader"],
                form.mform [id^="id_"][id$="header"],
                form.mform h3:not(.alert-warning h3),
                form.mform h4:not(.alert-warning h4),
                form.mform .collapsible-actions,
                form.mform .collapsed {
                    display: none !important;
                }

                /* Fix vertical alignment for Skilland Course ID display */
                #fitem_id_skilland_course_id_display .felement {
                    align-items: center !important;
                }
            </style>');
            $mform->addElement('html', "<script>
                document.addEventListener('DOMContentLoaded', function() {
                    var form = document.querySelector('form.mform');
                    if (form) {
                        var items = form.querySelectorAll('.fitem');
                        items.forEach(function(item) {
                            if (!item.querySelector('.alert-warning')) {
                                item.style.display = 'none';
                            }
                        });
                        var headerSelectors = [
                            '.fheader',
                            '.fitem_fheader',
                            '[class*=\"fheader\"]',
                            '[id^=\"id_\"][id$=\"header\"]',
                            'h3',
                            'h4'
                        ];
                        headerSelectors.forEach(function(selector) {
                            try {
                                var headers = form.querySelectorAll(selector);
                                headers.forEach(function(header) {
                                    if (!header.closest('.alert-warning')) {
                                        header.style.display = 'none';
                                    }
                                });
                            } catch(e) {
                                // Ignore selector errors
                            }
                        });
                        var collapsed = form.querySelectorAll('.collapsible-actions, .collapsed, [class*=\"collapsible\"]');
                        collapsed.forEach(function(collapsed) {
                            collapsed.style.display = 'none';
                        });
                    }
                });
            </script>");

            // Don't add any other fields.
            return;
        }

        // Skilland Course ID is set - show the full form.

        // 1. Skilland-specific fields (MOVED TO TOP)
        $mform->addElement('header', 'skillandfieldset', get_string('pluginname', 'mod_skilland'));

        // Display Skilland Course ID as read-only with link to course settings.
        $displayvalue = format_string($skillandcourseid);
        $editlinktext = get_string('edit_course_settings', 'mod_skilland');
        $editlink = html_writer::link($courseediturl, $editlinktext, array('target' => '_blank'));
        $displaytext = $displayvalue . ' (' . $editlink . ')';

        $mform->addElement('static', 'skilland_course_id_display',
            get_string('skilland_course_id', 'mod_skilland'),
            $displaytext);
        $mform->addHelpButton('skilland_course_id_display', 'skilland_course_id', 'mod_skilland');

        // Topic ID field (always shown, depends on Skilland course).
        // This will be populated via AJAX based on Skilland course.
        $mform->addElement('select', 'skilland_topicid',
            get_string('topicid', 'mod_skilland'),
            array('' => get_string('loading', 'mod_skilland')));
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

        // Edit in Skilland button (only shown when topic is selected)
        $skillandbuttonhtml = '<div id="skilland-edit-button-container" class="form-group row fitem" style="display: none;">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label class="d-inline word-break">' . get_string('edit_in_skilland', 'mod_skilland') . '</label>
            </div>
            <div class="col-md-9 form-inline felement" data-fieldtype="html">
                <a href="#" id="skilland-edit-link" class="btn btn-secondary" target="_blank" rel="noopener">
                    <i class="fa fa-external-link"></i> ' . get_string('edit_lessons_in_skilland', 'mod_skilland') . '
                </a>
                <div class="form-text text-muted mt-2" style="width: 100%;">
                    ' . get_string('edit_in_skilland_desc', 'mod_skilland') . '
                </div>
            </div>
        </div>
        <style>
            #skilland-edit-link {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                font-weight: 500;
            }
            #skilland-edit-link:hover {
                text-decoration: none;
            }
        </style>';
        $mform->addElement('html', $skillandbuttonhtml);

        // Lessons Selection Container
        $mform->addElement('html', '<div id="fitem_id_lessons_container" class="form-group row fitem">
            <div class="col-md-3 col-form-label d-flex pb-0 pr-md-0">
                <label class="d-inline word-break">' . get_string('lessons', 'mod_skilland') . '</label>
            </div>
            <div class="col-md-9 form-inline felement" data-fieldtype="html">
                <div id="skilland-select-actions" class="mb-2" style="display: none;">
                    <button type="button" id="skilland-select-all" class="btn btn-sm btn-outline-primary mr-2">
                        ' . get_string('select_all', 'mod_skilland') . '
                    </button>
                    <button type="button" id="skilland-select-none" class="btn btn-sm btn-outline-secondary">
                        ' . get_string('deselect_all', 'mod_skilland') . '
                    </button>
                </div>
                <div id="id_lessons_container" style="width: 100%; border: 1px solid #dee2e6; padding: 10px; border-radius: 4px;">
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
        </div>
        <style>
            /* Topic selector styling */
            #id_skilland_topicid {
                font-size: 16px;
                font-weight: 600;
                padding: 8px 12px;
                min-height: 44px;
                width: 100%;
            }
            #id_skilland_topicid option {
                font-size: 16px;
                font-weight: 600;
                padding: 8px;
            }
            /* Skilland Course display styling */
            #fitem_id_skilland_course_id_display .felement {
                display: flex;
                justify-content: space-between;
                align-items: center;
                width: 100%;
            }
            #fitem_id_skilland_course_id_display .skilland-course-name {
                font-size: 18px;
                font-weight: 700;
            }
            #fitem_id_skilland_course_id_display .skilland-course-edit-link {
                margin-left: auto;
                font-size: 14px;
            }
            #id_lessons_container {
                background-color: #fafbfd;
            }
            #id_lessons_container.skilland-lessons-loading {
                opacity: 0.6;
                pointer-events: none;
            }
            #id_lessons_container .skilland-lessons-list {
                display: flex;
                flex-direction: column;
                gap: 8px;
                margin: 0;
                padding: 0;
            }
            #id_lessons_container .skilland-lesson-card {
                display: flex;
                align-items: flex-start;
                gap: 12px;
                padding: 12px 14px;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                background-color: #ffffff;
                box-shadow: 0 1px 2px rgba(16, 24, 40, 0.05);
                cursor: pointer;
                transition: border-color 0.2s ease, box-shadow 0.2s ease;
            }
            #id_lessons_container .skilland-lesson-card:hover {
                border-color: #5c068c;
                box-shadow: 0 2px 8px rgba(92, 6, 140, 0.15);
            }
            #id_lessons_container .skilland-lesson-checkbox {
                margin-top: 4px;
                margin-right: 6px;
            }
            #id_lessons_container .skilland-lesson-content {
                flex: 1;
            }
            #id_lessons_container .skilland-lesson-title {
                font-weight: 600;
                color: #1f2933;
            }
            #id_lessons_container .skilland-lesson-meta {
                font-size: 0.85rem;
                color: #6c757d;
                margin-top: 2px;
                display: flex;
                align-items: center;
                gap: 8px;
                flex-wrap: wrap;
            }
            #id_lessons_container .skilland-new-content-pill {
                display: inline-flex;
                align-items: center;
                padding: 2px 8px;
                font-size: 0.75rem;
                font-weight: 600;
                color: #92400e;
                background-color: #fef3c7;
                border: 1px solid #fcd34d;
                border-radius: 9999px;
                white-space: nowrap;
            }
            #skilland-update-container {
                margin-top: 12px;
                padding: 12px;
                background-color: #fffbeb;
                border: 1px solid #fcd34d;
                border-radius: 8px;
            }
            #skilland-update-container.d-none {
                display: none;
            }
            #skilland-update-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 16px;
                font-size: 0.875rem;
                font-weight: 600;
                color: #92400e;
                background-color: #fcd34d;
                border: 1px solid #f59e0b;
                border-radius: 6px;
                cursor: pointer;
                transition: background-color 0.2s ease;
            }
            #skilland-update-btn:hover {
                background-color: #fbbf24;
            }
            #skilland-update-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            #skilland-update-loading {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                color: #92400e;
            }
            #skilland-update-loading .spinner-border {
                width: 1rem;
                height: 1rem;
            }
        </style>');

        // Hidden field to store selected lessons (JSON)
        $mform->addElement('hidden', 'selected_lessons', '{}');
        $mform->setType('selected_lessons', PARAM_RAW);
        $mform->setDefault('selected_lessons', '{}');

        // 2. General settings (MOVED AFTER SKILLAND, HIDDEN via CSS)
        // We hide this section because the name and description are auto-filled from the selected topic.
        $mform->addElement('header', 'general', get_string('general', 'form'));
        // Hidden via a JS-toggled class, not unconditional CSS: the name field is normally
        // auto-filled from the selected topic, but when the topic fetch fails (or a previous
        // submit left a "name is required" error here) the section must stay visible so the
        // field - and any validation error on it - is never invisible to the teacher.
        $mform->addElement('html', '<style>
            #id_general.skilland-hide-general { display: none; }
            /* Fix vertical alignment for Skilland Course ID display */
            #fitem_id_skilland_course_id_display .felement {
                align-items: center !important;
            }
        </style>');

        // Adding the standard "name" field.
        $mform->addElement('text', 'name', get_string('modulename', 'mod_skilland'), array('size' => '64'));
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

        $mform->addElement('advcheckbox', 'autoupdate',
            get_string('autoupdate', 'mod_skilland'),
            get_string('autoupdate_desc', 'mod_skilland'));
        $mform->setDefault('autoupdate', 0);

        $mform->addElement('advcheckbox', 'lockafterfirstaccess',
            get_string('lockafterfirstaccess', 'mod_skilland'),
            get_string('lockafterfirstaccess_desc', 'mod_skilland'));
        $mform->setDefault('lockafterfirstaccess', 0);

        // Shown only while auto-update is on and the lock is off - the combination that lets a
        // future sync silently wipe student progress.
        $mform->addElement('static', 'autoupdatewarning', '',
            html_writer::div(get_string('autoupdate_warning', 'mod_skilland'), 'alert alert-warning'));
        $mform->hideIf('autoupdatewarning', 'autoupdate', 'eq', 0);
        $mform->hideIf('autoupdatewarning', 'lockafterfirstaccess', 'eq', 1);

        $mform->addElement('advcheckbox', 'hidelabels',
            get_string('hidelabels', 'mod_skilland'),
            get_string('hidelabels_desc', 'mod_skilland'));
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
            $currentSelectedLessons = [];
            $hasscorm = false;
            $topicstudentattemptcount = 0;

            if (!empty($this->_instance)) {
                global $DB;
                $skilland = $DB->get_record('skilland', array('id' => $this->_instance));
                if ($skilland) {
                    $currenttopicid = $skilland->skilland_topicid;
                    $hasscorm = !empty($skilland->scormcmid);
                    $topicstudentattemptcount = skilland_count_topic_student_attempts($skilland);
                    $mform->setDefault('skilland_topicid', $currenttopicid);
                    // Fetch existing selected lessons (visible=1)
                    $records = $DB->get_records('skilland_lesson',
                        array('skillandid' => $this->_instance, 'visible' => 1),
                        '',
                        'skilland_lessonid, updatedat, title');

                    foreach ($records as $record) {
                        $currentSelectedLessons[$record->skilland_lessonid] = [
                            'updatedAt' => $record->updatedat,
                            'name' => $record->title
                        ];
                    }
                }
            }

            // Destructive-confirmation copy for the two dialogs below (SKL-697): a clear action
            // label and a mention of the count of students who would lose progress, only when
            // there is anything to lose - otherwise the lighter, non-scary copy stays as-is.
            $lockafterfirstaccesshint = get_string('lockafterfirstaccess_hint', 'mod_skilland',
                get_string('lockafterfirstaccess', 'mod_skilland'));
            $destructiveconfirmactionlabel = get_string('destructive_confirm_action', 'mod_skilland');
            if ($topicstudentattemptcount > 0) {
                $updateconfirmmessagetext = get_string('update_confirm_message_students', 'mod_skilland',
                    $topicstudentattemptcount) . ' ' . $lockafterfirstaccesshint;
                $updateconfirmactionlabel = $destructiveconfirmactionlabel;
                $topicchangeconfirmmessagetext = get_string('topic_change_confirm_students', 'mod_skilland',
                    $topicstudentattemptcount) . ' ' . $lockafterfirstaccesshint;
                $topicchangeconfirmactionlabel = $destructiveconfirmactionlabel;
            } else {
                $updateconfirmmessagetext = get_string('update_confirm_message', 'mod_skilland') . ' ' .
                    $lockafterfirstaccesshint;
                $updateconfirmactionlabel = get_string('yes');
                $topicchangeconfirmmessagetext = get_string('topic_change_confirm', 'mod_skilland') . ' ' .
                    $lockafterfirstaccesshint;
                $topicchangeconfirmactionlabel = get_string('yes');
            }

            $debug = json_encode((bool) get_config('mod_skilland', 'devmode'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT |
                JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
            $js = "
            (function() {
                var debug = " . $debug . ";
                function log() {
                    if (debug && window.console) {
                        console.log.apply(console, arguments);
                    }
                }

                function escapeHtml(str) {
                    return String(str === undefined || str === null ? '' : str)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\"/g, '&quot;')
                        .replace(/'/g, '&#39;');
                }

                function showLessonsError(container, message) {
                    container.innerHTML = '';
                    var errorSpan = document.createElement('span');
                    errorSpan.className = 'text-danger';
                    errorSpan.textContent = message;
                    container.appendChild(errorSpan);
                }


                var skillandCourseId = " . json_encode($skillandcourseid) . ";
                var moodleCourseId = " . json_encode($this->get_course()->id) . ";
                var currentTopicId = " . json_encode($currenttopicid) . ";
                var currentSelectedLessons = " . json_encode($currentSelectedLessons) . ";
                var selectionsByTopic = {}; // Ticked lessons per topic ID, restored when the topic is shown again.
                var missingLessonsByTopic = {}; // Per topic ID: DB-saved lessons the API no longer lists, pending an explicit Remove.
                var renderedTopicId = null; // Topic whose lessons are rendered as checkboxes, null while loading.
                var activeTopicId = null; // Topic last picked in the dropdown (SKL-655 reuses it as the previous value).
                var hasScorm = " . json_encode($hasscorm) . "; // The activity has a provisioned SCORM (SKL-655).
                var confirmedTopicId = null; // Topic the teacher just confirmed leaving the saved topic for.
                var topicChangeConfirmTitle = " . json_encode(get_string('topic_change_confirm_title', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";
                var topicChangeConfirmMessage = " . json_encode($topicchangeconfirmmessagetext, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";
                var topicChangeConfirmActionLabel = " . json_encode($topicchangeconfirmactionlabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";
                var lessonsRequestSeq = 0; // Bumped per lessons request; a response with an older token is dropped.
                var topicsMap = {}; // Store full topic objects by ID
                var editLinkHtml = " . json_encode($editlink) . ";

                // Wait for DOM to be ready.
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initForm);
                } else {
                    setTimeout(initForm, 100);
                }

                function initForm() {
                    log('Skilland: Initialising form with currentTopicId:', currentTopicId);
                    log('Skilland: currentSelectedLessons:', currentSelectedLessons);
                    applyGeneralSectionVisibility();
                    initTopicSelect();
                }

                // Keep the auto-filled Name/Description section hidden only when nothing needs
                // the teacher's attention there: a prior submit's validation error on 'name' (or
                // the field already having no value while the topic could not be auto-filled)
                // must keep the section visible.
                function applyGeneralSectionVisibility() {
                    var generalHeader = document.getElementById('id_general');
                    if (!generalHeader) {
                        return;
                    }
                    var nameField = document.getElementById('id_name');
                    var hasNameError = !!document.getElementById('id_error_name') ||
                        (nameField && nameField.classList && nameField.classList.contains('is-invalid'));
                    if (hasNameError) {
                        return;
                    }
                    if (nameField && nameField.value) {
                        generalHeader.classList.add('skilland-hide-general');
                    }
                }

                function initTopicSelect() {
                    var topicSelect = document.getElementById('id_skilland_topicid');
                    var lessonsContainer = document.getElementById('id_lessons_container');
                    var selectedLessonsInput = document.getElementById('id_selected_lessons');

                    if (!topicSelect) {
                        log('Skilland: Topic select field not found');
                        return;
                    }

                    // Ensure hidden fields exist (Moodle sometimes doesn't add IDs to hidden elements)
                    var selectedLessonsInputCreated = false;
                    if (!selectedLessonsInput && topicSelect.form) {
                        selectedLessonsInputCreated = true;
                        selectedLessonsInput = document.createElement('input');
                        selectedLessonsInput.type = 'hidden';
                        selectedLessonsInput.name = 'selected_lessons';
                        selectedLessonsInput.id = 'id_selected_lessons';
                        selectedLessonsInput.value = '{}';
                        topicSelect.form.appendChild(selectedLessonsInput);
                        log('Skilland: Created selected_lessons hidden input');
                    }

                    // Find or create the saved topic ID hidden field
                    var savedTopicInput = document.getElementById('id_skilland_topicid_saved');
                    if (!savedTopicInput) {
                        // Try finding by name attribute
                        savedTopicInput = topicSelect.form.querySelector('input[name=\"skilland_topicid_saved\"]');
                    }
                    if (!savedTopicInput && topicSelect.form) {
                        savedTopicInput = document.createElement('input');
                        savedTopicInput.type = 'hidden';
                        savedTopicInput.name = 'skilland_topicid_saved';
                        savedTopicInput.id = 'id_skilland_topicid_saved';
                        savedTopicInput.value = currentTopicId || '';
                        topicSelect.form.appendChild(savedTopicInput);
                        log('Skilland: Created skilland_topicid_saved hidden input with value:', savedTopicInput.value);
                    }

                    if (!currentTopicId && savedTopicInput && savedTopicInput.value) {
                        currentTopicId = savedTopicInput.value;
                        log('Skilland: Restored currentTopicId from savedTopicInput:', currentTopicId);
                    }

                    var loadingText = " . json_encode(get_string('loading', 'mod_skilland')) . ";
                    var errorText = " . json_encode(get_string('error_fetch_topics', 'mod_skilland')) . ";
                    var selectTopicText = " . json_encode(get_string('select_topic', 'mod_skilland')) . ";
                    var noTopicsText = " . json_encode(get_string('no_topics_available', 'mod_skilland')) . ";
                    var currentTopicUnavailableText = " . json_encode(get_string('current_topic_unavailable', 'mod_skilland')) . ";
                    var topicNoLongerAvailableText = " . json_encode(get_string('topic_no_longer_available', 'mod_skilland')) . ";
                    var topicNoLongerAvailableWarning = " . json_encode(get_string('topic_no_longer_available_warning', 'mod_skilland')) . ";
                    var retryText = " . json_encode(get_string('retry', 'mod_skilland')) . ";
                    var missingLessonsWarningText = " . json_encode(get_string('missing_lessons_warning', 'mod_skilland')) . ";
                    var removeMissingLessonText = " . json_encode(get_string('remove_from_activity', 'mod_skilland')) . ";
                    var newContentAvailableText = " . json_encode($this->get_new_content_string()) . ";
                    var updateConfirmTitle = " . json_encode(get_string('update_confirm_title', 'mod_skilland')) . ";
                    var updateConfirmMessage = " . json_encode($updateconfirmmessagetext) . ";
                    var updateConfirmActionLabel = " . json_encode($updateconfirmactionlabel) . ";
                    var updateSuccessText = " . json_encode(get_string('update_success', 'mod_skilland')) . ";
                    var updateErrorText = " . json_encode(get_string('update_error', 'mod_skilland')) . ";
                    var skillandInstanceId = " . json_encode($this->_instance ?? 0) . ";
                    var cmId = " . json_encode($this->_cm->id ?? 0) . ";

                    // Seed the saved topic's selection: the form's own value when it belongs to that topic
                    // (a redisplay after a validation error carries the posted value), else the DB rows.
                    if (currentTopicId) {
                        var seededSelection = null;
                        if (!selectedLessonsInputCreated && savedTopicInput && String(savedTopicInput.value) === String(currentTopicId)) {
                            seededSelection = parseSelectedLessons(selectedLessonsInput.value);
                        }
                        if (seededSelection === null) {
                            seededSelection = Array.isArray(currentSelectedLessons) ? {} : currentSelectedLessons;
                        }
                        selectionsByTopic[currentTopicId] = seededSelection;
                    }

                    function parseSelectedLessons(raw) {
                        if (typeof raw !== 'string' || raw.indexOf('{') !== 0) {
                            return null;
                        }
                        try {
                            var parsed = JSON.parse(raw);
                            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
                        } catch (e) {
                            return null;
                        }
                    }

                    function setLessonsLoading(loading) {
                        if (lessonsContainer) {
                            if (loading) {
                                lessonsContainer.setAttribute('aria-busy', 'true');
                                lessonsContainer.classList.add('skilland-lessons-loading');
                            } else {
                                lessonsContainer.removeAttribute('aria-busy');
                                lessonsContainer.classList.remove('skilland-lessons-loading');
                            }
                        }
                        ['skilland-select-all', 'skilland-select-none', 'id_submitbutton', 'id_submitbutton2'].forEach(function(id) {
                            var control = document.getElementById(id);
                            if (control) {
                                control.disabled = loading;
                            }
                        });
                    }

                    // Set initial loading state.
                    topicSelect.innerHTML = '<option value=\"\">' + loadingText + '...</option>';
                    topicSelect.disabled = true;

                    // Set up update button click handler
                    var updateBtn = document.getElementById('skilland-update-btn');
                    var updateLoading = document.getElementById('skilland-update-loading');
                    var updateContainer = document.getElementById('skilland-update-container');

                    if (updateBtn && skillandInstanceId && cmId) {
                        updateBtn.addEventListener('click', function() {
                            // Show confirmation dialog using Moodle's notification module, styled as a
                            // destructive action when it will actually delete student progress (SKL-697).
                            require(['jquery', 'core/notification'], function($, Notification) {
                                Notification.confirm(
                                    updateConfirmTitle,
                                    updateConfirmMessage,
                                    updateConfirmActionLabel,
                                    " . json_encode(get_string('no')) . ",
                                    function() {
                                        // User confirmed - perform the update
                                        performUpdate();
                                    }
                                ).then(function(modal) {
                                    if (modal && typeof modal.getRoot === 'function') {
                                        modal.getRoot().find('[data-action=\"save\"]')
                                            .removeClass('btn-primary btn-outline-danger')
                                            .addClass('btn-danger');
                                    }
                                    return modal;
                                }).catch(function() {
                                    // Notification.confirm already reports its own failures.
                                });
                            });
                        });
                    }

                    function performUpdate() {
                        if (!updateBtn || !updateLoading) return;

                        // Show loading state
                        updateBtn.classList.add('d-none');
                        updateLoading.classList.remove('d-none');

                        require(['core/ajax', 'core/notification'], function(ajax, notification) {
                            ajax.call([{
                                methodname: 'mod_skilland_update_topic_scorm_ajax',
                                args: {
                                    skillandid: skillandInstanceId,
                                    cmid: cmId
                                }
                            }])[0].then(function(response) {
                                if (response.error) {
                                    notification.addNotification({
                                        message: escapeHtml(updateErrorText + ': ' + response.error),
                                        type: 'error'
                                    });
                                    // Reset button state
                                    updateBtn.classList.remove('d-none');
                                    updateLoading.classList.add('d-none');
                                } else {
                                    notification.addNotification({
                                        message: updateSuccessText,
                                        type: 'success'
                                    });
                                    // Reload the page to reflect the updated content
                                    window.location.reload();
                                }
                            }).catch(function(error) {
                                notification.addNotification({
                                    message: escapeHtml(updateErrorText + ': ' + error.message),
                                    type: 'error'
                                });
                                // Reset button state
                                updateBtn.classList.remove('d-none');
                                updateLoading.classList.add('d-none');
                            });
                        });
                    }

                    // Handle topic selection change
                    topicSelect.addEventListener('change', function() {
                        var topicId = this.value;
                        // Leaving the saved topic of a provisioned activity replaces its SCORM (SKL-655):
                        // confirm first. Cancel restores the select and changes nothing else.
                        if (hasScorm && activeTopicId !== null && String(activeTopicId) === String(currentTopicId) &&
                                String(topicId) !== String(currentTopicId) && confirmedTopicId !== topicId) {
                            var previousTopicId = activeTopicId;
                            topicSelect.value = previousTopicId;
                            require(['jquery', 'core/notification'], function($, Notification) {
                                Notification.confirm(
                                    topicChangeConfirmTitle,
                                    topicChangeConfirmMessage,
                                    topicChangeConfirmActionLabel,
                                    " . json_encode(get_string('no')) . ",
                                    function() {
                                        confirmedTopicId = topicId;
                                        topicSelect.value = topicId;
                                        topicSelect.dispatchEvent(new Event('change', { bubbles: true }));
                                    },
                                    function() {
                                        topicSelect.value = previousTopicId;
                                    }
                                ).then(function(modal) {
                                    if (modal && typeof modal.getRoot === 'function') {
                                        modal.getRoot().find('[data-action=\"save\"]')
                                            .removeClass('btn-primary btn-outline-danger')
                                            .addClass('btn-danger');
                                    }
                                    return modal;
                                }).catch(function() {
                                    // Notification.confirm already reports its own failures.
                                });
                            });
                            return;
                        }
                        confirmedTopicId = null;
                        if (savedTopicInput) {
                            savedTopicInput.value = topicId;
                            log('Skilland: Updated savedTopicInput to:', topicId);
                        } else {
                            log('Skilland: savedTopicInput not found, cannot save topic ID');
                        }
                        if (topicId && topicsMap[topicId]) {
                            var topic = topicsMap[topicId];
                            log('Skilland: Topic selected', topicId);

                            // Update topic order index hidden field.
                            var topicOrderInput = document.getElementById('id_topic_orderindex') ||
                                                  topicSelect.form.querySelector('input[name=\"topic_orderindex\"]');
                            if (topicOrderInput) {
                                topicOrderInput.value = topic.orderIndex || 1;
                                log('Skilland: Updated topic_orderindex to:', topicOrderInput.value);
                            }

                            // Keep the rendered topic's ticks for a later visit. The synthetic change of the
                            // initial load has nothing rendered yet, so it never overwrites the seed.
                            if (renderedTopicId !== null) {
                                updateSelectedState();
                            }
                            selectedLessonsInput.value = '{}';
                            renderedTopicId = null;
                            activeTopicId = topicId;

                            updateFormFields(topic);
                            fetchLessons(topicId);
                        } else {
                            if (renderedTopicId !== null) {
                                updateSelectedState();
                            }
                            lessonsRequestSeq++;
                            selectedLessonsInput.value = '{}';
                            renderedTopicId = null;
                            activeTopicId = topicId || null;
                            setLessonsLoading(false);
                            lessonsContainer.innerHTML = '';
                        }
                    });

                    function formatUpdatedAt(value) {
                        if (!value) {
                            return '';
                        }
                        var date = new Date(value);
                        if (isNaN(date.getTime())) {
                            var timestamp = parseInt(value, 10);
                            if (!isNaN(timestamp)) {
                                date = new Date(timestamp * 1000);
                            }
                        }
                        if (isNaN(date.getTime())) {
                            return '';
                        }
                        return 'Updated ' + date.toLocaleString();
                    }

                    function parseTimestamp(value) {
                        if (!value) {
                            return null;
                        }
                        // If it's already a number, treat as Unix seconds
                        if (typeof value === 'number') {
                            if (value < 10000000000) {
                                return value * 1000;
                            }
                            return value;
                        }
                        // Try parsing as ISO8601 date string
                        var date = new Date(value);
                        if (!isNaN(date.getTime())) {
                            return date.getTime();
                        }
                        // Try parsing as numeric string (Unix timestamp)
                        var timestamp = parseInt(value, 10);
                        if (!isNaN(timestamp)) {
                            if (timestamp < 10000000000) {
                                return timestamp * 1000;
                            }
                            return timestamp;
                        }
                        return null;
                    }

                    function hasNewerContent(skillandUpdatedAt, storedUpdatedAt) {
                        var skillandTs = parseTimestamp(skillandUpdatedAt);
                        var storedTs = parseTimestamp(storedUpdatedAt);
                        if (skillandTs === null || storedTs === null) {
                            return false;
                        }
                        // Add 1 second tolerance to account for millisecond truncation when storing
                        var toleranceMs = 1000;
                        return skillandTs > (storedTs + toleranceMs);
                    }

                    function updateFormFields(topic) {
                        // Update Name with topic label (T1, T2, etc.)
                        var nameField = document.getElementById('id_name');
                        if (nameField) {
                            var topicLabel = 'T' + (topic.orderIndex || 1);
                            nameField.value = topicLabel + ' - ' + topic.name;
                        }
                        applyGeneralSectionVisibility();

                        // Update Edit in Skilland button
                        updateEditButton(topic);

                        // Update Description (Intro). Moodle renders the intro as an editor
                        // on the textarea #id_introeditor (TinyMCE or Atto), not #id_intro.
                        setIntroDescription(topic.description || '');
                    }

                    // HTML this form last wrote into the intro, so a teacher edit is never clobbered.
                    var lastAutoIntroHtml = null;

                    // Text of an HTML string, read through an inert DOMParser document
                    // (no scripts run, no images load), never through a live element.
                    function htmlToText(html) {
                        var text = String(html || '');
                        if (window.DOMParser) {
                            var doc = new window.DOMParser().parseFromString(text, 'text/html');
                            text = doc.body ? (doc.body.textContent || '') : '';
                        }
                        return text.replace(/\\s+/g, ' ').trim();
                    }

                    // The description is untrusted API data: keep only its text and escape it.
                    function descriptionToIntroHtml(description) {
                        var text = htmlToText(description);
                        return text ? '<p>' + escapeHtml(text) + '</p>' : '';
                    }

                    function getIntroTinyEditor() {
                        var tiny = window.tinymce || window.tinyMCE;
                        if (tiny && typeof tiny.get === 'function') {
                            return tiny.get('id_introeditor') || null;
                        }
                        return null;
                    }

                    function introIsEmpty(html) {
                        return !htmlToText(html) && !/<(img|video|audio|iframe|object)\\b/i.test(String(html || ''));
                    }

                    function currentIntroHtml(textarea, tinyEditor, attoNode) {
                        if (tinyEditor) {
                            return tinyEditor.getContent();
                        }
                        if (attoNode) {
                            return attoNode.innerHTML;
                        }
                        return textarea.value;
                    }

                    function writeIntroHtml(textarea, html) {
                        var tinyEditor = getIntroTinyEditor();
                        if (tinyEditor) {
                            tinyEditor.setContent(html);
                            if (typeof tinyEditor.save === 'function') {
                                tinyEditor.save();
                            }
                        }
                        var attoNode = document.getElementById('id_introeditoreditable');
                        if (attoNode) {
                            attoNode.innerHTML = html;
                        }
                        textarea.value = html;
                        textarea.dispatchEvent(new Event('input', {bubbles: true}));
                        textarea.dispatchEvent(new Event('change', {bubbles: true}));
                        if (attoNode) {
                            // Atto copies its contenteditable into the textarea on input/change.
                            attoNode.dispatchEvent(new Event('input', {bubbles: true}));
                        }
                    }

                    function setIntroDescription(description) {
                        var textarea = document.getElementById('id_introeditor');
                        if (!textarea) {
                            return;
                        }
                        var html = descriptionToIntroHtml(description);
                        var existing = currentIntroHtml(
                            textarea,
                            getIntroTinyEditor(),
                            document.getElementById('id_introeditoreditable')
                        );
                        // Editors normalise HTML (whitespace, entities), so compare the visible text.
                        var untouched = introIsEmpty(existing) ||
                            (lastAutoIntroHtml !== null && htmlToText(existing) === htmlToText(lastAutoIntroHtml));
                        if (!untouched) {
                            log('Skilland: intro edited by the teacher, not overwriting it');
                            return;
                        }
                        lastAutoIntroHtml = html;
                        writeIntroHtml(textarea, html);

                        // TinyMCE may still be initialising: set it again once Moodle's editor is ready.
                        if (!getIntroTinyEditor() && typeof require === 'function') {
                            require(['editor_tiny/editor'], function(tinyModule) {
                                var instance = tinyModule && tinyModule.getInstanceForElementId ?
                                    tinyModule.getInstanceForElementId('id_introeditor') : null;
                                if (instance && lastAutoIntroHtml === html) {
                                    instance.setContent(html);
                                    if (typeof instance.save === 'function') {
                                        instance.save();
                                    }
                                }
                            }, function() {
                                // Tiny is not the active editor.
                            });
                        }
                    }

                    function fetchLessons(topicId) {
                        if (!lessonsContainer) return;

                        var seq = ++lessonsRequestSeq;
                        lessonsContainer.innerHTML = '<em>' + loadingText + '...</em>';
                        setLessonsLoading(true);

                        require(['core/ajax', 'core/notification'], function(ajax, notification) {
                            log('Skilland: Fetching lessons for topic', topicId);
                            ajax.call([{
                                methodname: 'mod_skilland_fetch_lessons_ajax',
                                args: {
                                    topicid: topicId,
                                    moodlecourseid: moodleCourseId
                                }
                            }])[0].then(function(response) {
                                // A newer request or another topic owns the list now: drop this response.
                                if (seq !== lessonsRequestSeq || String(topicSelect.value) !== String(topicId)) {
                                    return;
                                }

                                if (response.error) {
                                    selectedLessonsInput.value = '{}';
                                    showLessonsError(lessonsContainer, response.error);
                                    setLessonsLoading(false);
                                    return;
                                }

                                if (!response.lessons || response.lessons.length === 0) {
                                    selectionsByTopic[topicId] = {};
                                    selectedLessonsInput.value = '{}';
                                    lessonsContainer.innerHTML = '<em>' + " . json_encode(get_string('no_lessons_found', 'mod_skilland')) . " + '</em>';
                                    setLessonsLoading(false);
                                    return;
                                }

                                log('Skilland: Lessons response received');
                                renderLessons(response.lessons, topicId);
                                setLessonsLoading(false);
                            }).catch(function(error) {
                                if (seq !== lessonsRequestSeq || String(topicSelect.value) !== String(topicId)) {
                                    return;
                                }
                                selectedLessonsInput.value = '{}';
                                showLessonsError(lessonsContainer, error.message);
                                setLessonsLoading(false);
                            });
                        });
                    }

                    function renderLessons(lessons, topicId) {
                        lessonsContainer.innerHTML = '';

                        // Track if any lesson has new content available
                        var hasAnyNewContent = false;

                        // Get the current topic's order index
                        var currentTopicOrderIndex = 1;
                        if (topicId && topicsMap[topicId] && topicsMap[topicId].orderIndex) {
                            currentTopicOrderIndex = topicsMap[topicId].orderIndex;
                        }

                        // A topic seen before (or the saved one) is restored exactly, even when nothing was
                        // ticked; a topic never shown gets every lesson ticked.
                        var hasSavedSelection = Object.prototype.hasOwnProperty.call(selectionsByTopic, topicId);
                        var savedSelection = hasSavedSelection ? selectionsByTopic[topicId] : {};

                        // Create a list of checkboxes
                        var list = document.createElement('div');
                        list.className = 'skilland-lessons-list';

                        lessons.forEach(function(lesson, lessonIndex) {
                            var card = document.createElement('label');
                            card.className = 'skilland-lesson-card';
                            card.setAttribute('for', 'lesson_' + lesson.id);

                            var checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.id = 'lesson_' + lesson.id;
                            checkbox.value = lesson.id;
                            checkbox.dataset.updatedAt = lesson.updatedAt;
                            checkbox.dataset.name = lesson.name;
                            checkbox.className = 'skilland-lesson-checkbox';

                            checkbox.checked = hasSavedSelection ? !!savedSelection[lesson.id] : true;

                            checkbox.addEventListener('change', function(event) {
                                event.stopPropagation();
                                updateSelectedState();
                            });

                            var content = document.createElement('div');
                            content.className = 'skilland-lesson-content';

                            var title = document.createElement('div');
                            title.className = 'skilland-lesson-title';
                            // Format: L{topicIndex}.{lessonIndex} - {lessonName}
                            var lessonLabel = 'L' + currentTopicOrderIndex + '.' + (lessonIndex + 1);
                            title.textContent = lessonLabel + ' - ' + lesson.name;
                            content.appendChild(title);

                            var metaText = formatUpdatedAt(lesson.updatedAt);
                            if (metaText) {
                                var meta = document.createElement('div');
                                meta.className = 'skilland-lesson-meta';

                                var metaTextSpan = document.createElement('span');
                                metaTextSpan.textContent = metaText;
                                meta.appendChild(metaTextSpan);

                                // Check if there's newer content available from Skilland
                                var storedLesson = currentSelectedLessons[lesson.id];
                                if (storedLesson && storedLesson.updatedAt && hasNewerContent(lesson.updatedAt, storedLesson.updatedAt)) {
                                    var pill = document.createElement('span');
                                    pill.className = 'skilland-new-content-pill';
                                    pill.textContent = newContentAvailableText;
                                    meta.appendChild(pill);
                                    hasAnyNewContent = true;
                                }

                                content.appendChild(meta);
                            }

                            card.appendChild(checkbox);
                            card.appendChild(content);
                            list.appendChild(card);
                        });

                        lessonsContainer.appendChild(list);

                        // Any DB-saved lesson missing from this topic's own fresh API list is not
                        // dropped silently (SKL-688): flag it in a warning block with an explicit
                        // Remove-from-activity action, and keep it in updateSelectedState()'s
                        // output until the teacher clicks Remove.
                        missingLessonsByTopic[topicId] = {};
                        if (String(topicId) === String(currentTopicId)) {
                            var apiLessonIds = {};
                            lessons.forEach(function(lesson) {
                                apiLessonIds[String(lesson.id)] = true;
                            });
                            var missingIds = Object.keys(currentSelectedLessons).filter(function(lessonId) {
                                return !apiLessonIds[String(lessonId)];
                            });
                            if (missingIds.length > 0) {
                                var missingBlock = document.createElement('div');
                                missingBlock.className = 'skilland-missing-lessons-warning alert alert-warning';

                                var missingTitle = document.createElement('div');
                                missingTitle.className = 'skilland-missing-lessons-title';
                                missingTitle.textContent = missingLessonsWarningText;
                                missingBlock.appendChild(missingTitle);

                                missingIds.forEach(function(lessonId) {
                                    var storedLesson = currentSelectedLessons[lessonId] || {};
                                    missingLessonsByTopic[topicId][lessonId] = {
                                        updatedAt: storedLesson.updatedAt,
                                        name: storedLesson.name || ''
                                    };

                                    var row = document.createElement('div');
                                    row.className = 'skilland-missing-lesson-row';

                                    var label = document.createElement('span');
                                    label.textContent = storedLesson.name || lessonId;
                                    row.appendChild(label);

                                    var removeBtn = document.createElement('button');
                                    removeBtn.type = 'button';
                                    removeBtn.className = 'btn btn-sm btn-outline-danger skilland-remove-missing-lesson';
                                    removeBtn.textContent = removeMissingLessonText;
                                    removeBtn.addEventListener('click', function(e) {
                                        e.preventDefault();
                                        removeMissingLesson(topicId, lessonId, row);
                                    });
                                    row.appendChild(removeBtn);

                                    missingBlock.appendChild(row);
                                });

                                lessonsContainer.appendChild(missingBlock);
                            }
                        }

                        // Show/hide update button based on whether any lesson has new content
                        var updateContainer = document.getElementById('skilland-update-container');
                        if (updateContainer) {
                            if (hasAnyNewContent) {
                                updateContainer.classList.remove('d-none');
                            } else {
                                updateContainer.classList.add('d-none');
                            }
                        }

                        // Show Select All/Deselect All buttons and set up handlers
                        var selectActions = document.getElementById('skilland-select-actions');
                        if (selectActions && lessons.length > 0) {
                            selectActions.style.display = '';
                            setupSelectAllHandlers();
                        }

                        renderedTopicId = topicId;
                        updateSelectedState();
                    }

                    function setupSelectAllHandlers() {
                        var selectAllBtn = document.getElementById('skilland-select-all');
                        var selectNoneBtn = document.getElementById('skilland-select-none');

                        if (selectAllBtn) {
                            // Remove old listeners by cloning
                            var newSelectAll = selectAllBtn.cloneNode(true);
                            selectAllBtn.parentNode.replaceChild(newSelectAll, selectAllBtn);
                            newSelectAll.addEventListener('click', function(e) {
                                e.preventDefault();
                                var checkboxes = document.querySelectorAll('#id_lessons_container .skilland-lesson-checkbox');
                                checkboxes.forEach(function(cb) { cb.checked = true; });
                                updateSelectedState();
                            });
                        }

                        if (selectNoneBtn) {
                            // Remove old listeners by cloning
                            var newSelectNone = selectNoneBtn.cloneNode(true);
                            selectNoneBtn.parentNode.replaceChild(newSelectNone, selectNoneBtn);
                            newSelectNone.addEventListener('click', function(e) {
                                e.preventDefault();
                                var checkboxes = document.querySelectorAll('#id_lessons_container .skilland-lesson-checkbox');
                                checkboxes.forEach(function(cb) { cb.checked = false; });
                                updateSelectedState();
                            });
                        }
                    }

                    function updateSelectedState() {
                        // The hidden value is always the rendered topic's ticked checkboxes, rebuilt in DOM
                        // order (insertion order becomes orderindex), plus any lessons missing from Skilland
                        // that the teacher has not explicitly removed yet (SKL-688). Nothing else reaches
                        // the hidden input.
                        var state = {};
                        if (renderedTopicId !== null) {
                            var checkboxes = document.querySelectorAll('#id_lessons_container .skilland-lesson-checkbox');
                            checkboxes.forEach(function(cb) {
                                if (cb.checked) {
                                    // A lesson stored for this activity keeps its stored updatedAt (the version
                                    // inside the installed SCORM); only a lesson not stored yet takes the API one.
                                    var stored = currentSelectedLessons[cb.value];
                                    state[cb.value] = {
                                        updatedAt: stored ? stored.updatedAt : cb.dataset.updatedAt,
                                        name: cb.dataset.name || ''
                                    };
                                }
                            });
                            var pendingMissing = missingLessonsByTopic[renderedTopicId] || {};
                            Object.keys(pendingMissing).forEach(function(lessonId) {
                                state[lessonId] = pendingMissing[lessonId];
                            });
                            selectionsByTopic[renderedTopicId] = state;
                        }

                        selectedLessonsInput.value = JSON.stringify(state);
                    }

                    // Explicitly forgets a DB-saved lesson the API no longer lists (SKL-688). This is
                    // the only path that drops one: nothing removes it automatically on save.
                    function removeMissingLesson(topicId, lessonId, rowEl) {
                        delete currentSelectedLessons[lessonId];
                        if (missingLessonsByTopic[topicId]) {
                            delete missingLessonsByTopic[topicId][lessonId];
                        }
                        if (rowEl && rowEl.parentNode) {
                            rowEl.parentNode.removeChild(rowEl);
                        }
                        updateSelectedState();
                    }

                    function updateEditButton(topic) {
                        var editButtonContainer = document.getElementById('skilland-edit-button-container');
                        var editLink = document.getElementById('skilland-edit-link');

                        if (!editButtonContainer || !editLink) {
                            return;
                        }

                        if (topic && topic.id) {
                            // Show the button
                            editButtonContainer.style.display = '';

                            // Generate SSO URL
                            var ssoUrl = " . json_encode((new moodle_url('/mod/skilland/sso_redirect.php'))->out(false)) . ";
                            var params = {
                                'topicid': topic.id,
                                'sesskey': M.cfg.sesskey
                            };

                            // Pass Moodle course ID so sso_redirect.php can verify capability
                            // and look up the Skilland skill ID server-side.
                            if (moodleCourseId) {
                                params['courseid'] = moodleCourseId;
                            }

                            var urlParts = ssoUrl.split('?');
                            var baseUrl = urlParts[0];
                            var queryString = Object.keys(params).map(function(key) {
                                return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
                            }).join('&');

                            editLink.href = baseUrl + '?' + queryString;

                            log('Skilland: Edit button updated for topic', topic.id);
                        } else {
                            // Hide the button
                            editButtonContainer.style.display = 'none';
                        }
                    }

                    // Fetch topics via AJAX. Named so the Retry control (shown on failure) can
                    // call it again without duplicating the request logic.
                    function fetchTopics() {
                        hideTopicRetryControl();
                        require(['core/ajax', 'core/notification'], function(ajax, notification) {
                            log('Skilland: Fetching topics for course', skillandCourseId);
                            log('Skilland: About to call mod_skilland_fetch_topics_ajax');
                            ajax.call([{
                                methodname: 'mod_skilland_fetch_topics_ajax',
                                args: {
                                    courseid: skillandCourseId,
                                    moodlecourseid: moodleCourseId
                                }
                            }])[0].then(function(response) {
                                log('Skilland: Topics response received');

                                if (response.error) {
                                    showTopicFetchError();
                                    notification.addNotification({
                                        message: escapeHtml('Failed to fetch topics from Skilland: ' + response.error),
                                        type: 'error'
                                    });
                                    return;
                                }

                                // Update Skilland Course ID display with actual course name if available.
                                if (response.course && response.course.name) {
                                    var courseDisplayEl = document.querySelector('#fitem_id_skilland_course_id_display .felement, #fitem_id_skilland_course_id_display .fstatic');
                                    if (courseDisplayEl) {
                                        courseDisplayEl.innerHTML = '';
                                        var nameSpan = document.createElement('span');
                                        nameSpan.className = 'skilland-course-name';
                                        nameSpan.textContent = response.course.name;
                                        courseDisplayEl.appendChild(nameSpan);
                                        // Only show code if it's different from the name and doesn't look like a long ID
                                        if (response.course.code && response.course.code !== response.course.name && response.course.code.length < 20) {
                                            var codeSpan = document.createElement('span');
                                            codeSpan.className = 'skilland-course-code';
                                            codeSpan.textContent = ' (' + response.course.code + ')';
                                            courseDisplayEl.appendChild(codeSpan);
                                        }
                                        // editLinkHtml is a trusted fragment built server-side by html_writer.
                                        var editLinkSpan = document.createElement('span');
                                        editLinkSpan.className = 'skilland-course-edit-link';
                                        editLinkSpan.innerHTML = '(' + editLinkHtml + ')';
                                        courseDisplayEl.appendChild(editLinkSpan);
                                    }
                                }

                                // Clear and populate dropdown.
                                topicSelect.innerHTML = '<option value=\"\">' + selectTopicText + '...</option>';
                                topicsMap = {};

                                if (response.topics && response.topics.length > 0) {
                                    response.topics.forEach(function(topic, index) {
                                        topic.orderIndex = topic.position || (index + 1); // Use API position, fallback to index
                                        topicsMap[topic.id] = topic;
                                        var optionText = 'T' + topic.orderIndex + ' - ' + topic.name + ' (' + topic.id + ')';

                                        var option = document.createElement('option');
                                        option.textContent = optionText;
                                        option.value = topic.id;
                                        if (String(topic.id) === String(currentTopicId)) {
                                            log('Skilland: Found matching topic option', topic.id);
                                            option.selected = true;
                                        }
                                        topicSelect.appendChild(option);
                                    });
                                } else {
                                    var option = document.createElement('option');
                                    option.textContent = noTopicsText;
                                    topicSelect.appendChild(option);
                                }

                                topicSelect.disabled = false;

                                // If we have a current topic ID, fetch its lessons immediately - unless
                                // the saved topic is no longer in the fresh list (SKL-688): keep it as a
                                // disabled option and leave the saved topic id and lessons untouched,
                                // instead of silently falling back to the blank option and wiping both
                                // on the resulting change event.
                                if (currentTopicId) {
                                    log('Skilland: Attempting to restore topic selection', currentTopicId);
                                    if (topicsMap[currentTopicId]) {
                                        topicSelect.value = String(currentTopicId);
                                        if (savedTopicInput) {
                                            savedTopicInput.value = currentTopicId;
                                        }
                                        var changeEvent = new Event('change', { bubbles: true });
                                        topicSelect.dispatchEvent(changeEvent);
                                    } else {
                                        addStaleTopicOption(currentTopicId);
                                        notification.addNotification({
                                            message: escapeHtml(topicNoLongerAvailableWarning),
                                            type: 'warning'
                                        });
                                        renderStaleTopicLessons();
                                    }
                                }
                            }).catch(function(error) {
                                log('Skilland: AJAX error', error && error.message);
                                showTopicFetchError();
                                notification.addNotification({
                                    message: escapeHtml('Failed to fetch topics from Skilland: ' + (error.message || JSON.stringify(error))),
                                    type: 'error'
                                });
                            });
                        });
                    }

                    // Re-adds the saved topic id as a disabled, selected option so it stays visible
                    // and the select keeps its value, without letting the teacher pick it again.
                    function addStaleTopicOption(topicId) {
                        var option = document.createElement('option');
                        option.value = String(topicId);
                        option.textContent = topicNoLongerAvailableText;
                        option.disabled = true;
                        option.selected = true;
                        topicSelect.appendChild(option);
                        topicSelect.value = String(topicId);
                    }

                    // Shows the DB-saved lesson selection read-only, without calling the lessons API
                    // for a topic that no longer exists there. Nothing here touches selectedLessonsInput
                    // or selectionsByTopic, so the value already rendered server-side is what gets saved.
                    function renderStaleTopicLessons() {
                        lessonsContainer.innerHTML = '';
                        var ids = Object.keys(currentSelectedLessons);
                        if (ids.length === 0) {
                            lessonsContainer.innerHTML = '<em>' + " . json_encode(get_string('no_lessons_found', 'mod_skilland')) . " + '</em>';
                            return;
                        }
                        var list = document.createElement('div');
                        list.className = 'skilland-lessons-list';
                        ids.forEach(function(lessonId) {
                            var card = document.createElement('div');
                            card.className = 'skilland-lesson-card';
                            var content = document.createElement('div');
                            content.className = 'skilland-lesson-content';
                            var title = document.createElement('div');
                            title.className = 'skilland-lesson-title';
                            title.textContent = currentSelectedLessons[lessonId].name || lessonId;
                            content.appendChild(title);
                            card.appendChild(content);
                            list.appendChild(card);
                        });
                        lessonsContainer.appendChild(list);
                    }

                    // Keeps the topic select usable on a fetch failure: the saved topic (if any)
                    // stays selectable so unrelated settings can still be saved, and a Retry control
                    // lets the teacher try the same request again without reloading the page.
                    function showTopicFetchError() {
                        topicSelect.innerHTML = '';
                        if (currentTopicId) {
                            var keepOption = document.createElement('option');
                            keepOption.value = String(currentTopicId);
                            keepOption.textContent = currentTopicUnavailableText;
                            keepOption.selected = true;
                            topicSelect.appendChild(keepOption);
                            topicSelect.disabled = false;
                        } else {
                            var errorOption = document.createElement('option');
                            errorOption.value = '';
                            errorOption.textContent = errorText;
                            topicSelect.appendChild(errorOption);
                            topicSelect.disabled = true;
                        }
                        showTopicRetryControl();
                    }

                    function showTopicRetryControl() {
                        var retryBtn = document.getElementById('skilland-topic-retry');
                        if (retryBtn) {
                            retryBtn.style.display = '';
                            return;
                        }
                        retryBtn = document.createElement('button');
                        retryBtn.type = 'button';
                        retryBtn.id = 'skilland-topic-retry';
                        retryBtn.className = 'btn btn-sm btn-outline-secondary ml-2';
                        retryBtn.textContent = retryText;
                        retryBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            fetchTopics();
                        });
                        topicSelect.parentNode.insertBefore(retryBtn, topicSelect.nextSibling);
                    }

                    function hideTopicRetryControl() {
                        var retryBtn = document.getElementById('skilland-topic-retry');
                        if (retryBtn) {
                            retryBtn.style.display = 'none';
                        }
                    }

                    fetchTopics();
                }
            })();
            ";
            $mform->addElement('html', '<script>' . $js . '</script>');
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
            $courseediturl = new moodle_url('/course/edit.php', array('id' => $courseid));
            $courseediturl->set_anchor('id_course_customfields');
            $linktext = get_string('set_skilland_course_id', 'mod_skilland');
            $link = html_writer::link($courseediturl, $linktext);
            $errors['skilland_course_id_display'] = get_string('skilland_course_id_required', 'mod_skilland', $link);
        } else {
            // The submitted topic must belong to the skill this course is mapped to. The client-side
            // 'required' rule on skilland_topicid was removed (SKL-688) so an unrelated setting can
            // still be saved while the topic select couldn't load; this is the real, server-side guard
            // against an empty topic id ever reaching save.
            $topicid = !empty($data['skilland_topicid_saved']) ? $data['skilland_topicid_saved'] : ($data['skilland_topicid'] ?? '');
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
        $mform->addElement('advcheckbox', $name, get_string('completionlessons', 'mod_skilland'),
            get_string('completionlessons_desc', 'mod_skilland'));
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
     * Get the "New Content Available" string with fallback.
     * @return string
     */
    private function get_new_content_string() {
        $stringman = get_string_manager();
        if ($stringman->string_exists('new_content_available', 'mod_skilland')) {
            return get_string('new_content_available', 'mod_skilland');
        }
        return 'New Content Available';
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

            $records = $DB->get_records('skilland_lesson',
                ['skillandid' => $this->_instance, 'visible' => 1],
                'orderindex ASC',
                'skilland_lessonid, title, updatedat');

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
