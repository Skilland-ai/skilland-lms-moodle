<?php
namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for mod_skilland.
 */
class hooks {
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
                // Turn the SkilLand course ID custom field into a dropdown plus an explicit
                // "Create in SkilLand" button (SKL-664). Inline JavaScript, no AMD module.
                $debug = json_encode((bool) get_config('mod_skilland', 'devmode'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT |
                    JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

                $course = get_course($courseid);
                $coursename = json_encode(format_string($course->fullname, true, ['escape' => false]),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $linked = json_encode(!empty(skilland_get_course_customfield_value($courseid)),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                // Placeholders the script swaps for the (escaped) course name and the stale course id.
                $nameplaceholder = '__SKILLAND_COURSE_NAME__';
                $idplaceholder = '__SKILLAND_COURSE_ID__';
                $nameplaceholderjs = json_encode($nameplaceholder, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $idplaceholderjs = json_encode($idplaceholder, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $confirmbody = json_encode(\get_string('create_course_confirm_body', 'mod_skilland', $nameplaceholder),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $unknownlabel = json_encode(\get_string('course_unknown', 'mod_skilland', $idplaceholder),
                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

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

                    var moodleCourseId = " . $courseid . ";
                    var courseName = " . $coursename . ";
                    var linked = " . $linked . ";
                    var namePlaceholder = " . $nameplaceholderjs . ";
                    var idPlaceholder = " . $idplaceholderjs . ";
                    var strings = {
                        createInSkilland: " . json_encode(\get_string('create_in_skilland', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ",
                        creatingCourse: " . json_encode(\get_string('creating_course', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ",
                        confirmTitle: " . json_encode(\get_string('create_course_confirm_title', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ",
                        confirmBody: " . $confirmbody . ",
                        confirmReplace: " . json_encode(\get_string('create_course_confirm_replace', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ",
                        confirmYes: " . json_encode(\get_string('create_course_confirm_yes', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ",
                        courseUnknown: " . $unknownlabel . ",
                        courseUnknownWarning: " . json_encode(\get_string('course_unknown_warning', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . "
                    };

                    log('Skilland: Initializing course mapping field for course', moodleCourseId);

                    // Wait for DOM to be ready.
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initSkillandField);
                    } else {
                        setTimeout(initSkillandField, 100);
                    }

                    function initSkillandField() {
                        // Moodle names the custom field input after its shortname, whatever its label or language.
                        var fieldInput = document.querySelector('[name=\"customfield_skilland_course_id\"]');
                        log('Skilland: Found input?', !!fieldInput);
                        if (!fieldInput) {
                            return;
                        }

                        var currentValue = fieldInput.value;
                        var originalId = fieldInput.id;
                        if (currentValue !== '') {
                            linked = true;
                        }

                        // The select is the only control that submits the value: it takes the input's
                        // name and id (so the label still points at it), the input is disabled.
                        var container = document.createElement('div');
                        container.className = 'skilland-course-mapping-field';

                        var select = document.createElement('select');
                        select.className = 'form-control';
                        select.style.width = '100%';
                        var loadingOption = document.createElement('option');
                        loadingOption.value = currentValue;
                        loadingOption.textContent = 'Loading courses from Skilland...';
                        select.appendChild(loadingOption);

                        var createBtn = document.createElement('button');
                        createBtn.type = 'button';
                        createBtn.id = 'skilland-create-course-btn';
                        createBtn.className = 'btn btn-secondary mt-2';
                        createBtn.textContent = strings.createInSkilland;
                        createBtn.disabled = true;

                        fieldInput.id = originalId + '_raw';
                        fieldInput.disabled = true;
                        fieldInput.style.display = 'none';
                        select.id = originalId;
                        select.name = fieldInput.name;

                        fieldInput.parentNode.insertBefore(container, fieldInput.nextSibling);
                        container.appendChild(select);
                        container.appendChild(createBtn);

                        // Falls back to the plain text input, still the only submitted control.
                        function restoreTextInput() {
                            if (container.parentNode) {
                                container.parentNode.removeChild(container);
                            }
                            fieldInput.id = originalId;
                            fieldInput.disabled = false;
                            fieldInput.style.display = '';
                        }

                        function selectCourse(id, text) {
                            var option = document.createElement('option');
                            option.value = id;
                            option.textContent = text;
                            select.appendChild(option);
                            select.value = id;
                            var warning = container.querySelector('.skilland-course-unknown-warning');
                            if (warning) {
                                warning.parentNode.removeChild(warning);
                            }
                        }

                        function onCreateClick() {
                            var body = escapeHtml(strings.confirmBody).split(namePlaceholder).join(escapeHtml(courseName));
                            if (linked) {
                                body += ' ' + escapeHtml(strings.confirmReplace);
                            }
                            require(['core/ajax', 'core/notification'], function(ajax, notification) {
                                notification.saveCancelPromise(
                                    escapeHtml(strings.confirmTitle),
                                    body,
                                    escapeHtml(strings.confirmYes)
                                ).then(function() {
                                    createBtn.disabled = true;
                                    createBtn.textContent = strings.creatingCourse;
                                    return ajax.call([{
                                        methodname: 'mod_skilland_create_course_ajax',
                                        args: {moodlecourseid: moodleCourseId}
                                    }])[0].then(function(resp) {
                                        if (resp.error) {
                                            notification.addNotification({
                                                message: escapeHtml('Failed to create course: ' + resp.error),
                                                type: 'error'
                                            });
                                            return;
                                        }
                                        selectCourse(resp.skillid, resp.name);
                                        linked = true;
                                    }).catch(function() {
                                        notification.addNotification({
                                            message: 'Failed to create course in Skilland.',
                                            type: 'error'
                                        });
                                    }).then(function() {
                                        createBtn.textContent = strings.createInSkilland;
                                        createBtn.disabled = false;
                                    });
                                }, function() {
                                    log('Skilland: course creation cancelled');
                                });
                            });
                        }

                        createBtn.addEventListener('click', onCreateClick);

                        // Fetch courses via AJAX using Moodle's core/ajax.
                        require(['core/ajax', 'core/notification'], function(ajax, notification) {
                            log('Skilland: Making AJAX call to fetch courses');
                            ajax.call([{
                                methodname: 'mod_skilland_fetch_courses_ajax',
                                args: {moodlecourseid: moodleCourseId}
                            }])[0].then(function(response) {
                                log('Skilland: AJAX response received');
                                if (response.error) {
                                    restoreTextInput();
                                    notification.addNotification({
                                        message: escapeHtml('Failed to fetch courses from Skilland: ' + response.error),
                                        type: 'error'
                                    });
                                    return;
                                }

                                select.innerHTML = '<option value=\"\">Select a Skilland course...</option>';

                                var found = false;
                                (response.courses || []).forEach(function(course) {
                                    var optionText = course.name;
                                    if (course.code) optionText += ' (' + course.code + ')';
                                    if (course.status) optionText += ' [' + course.status + ']';

                                    var option = document.createElement('option');
                                    option.textContent = optionText;
                                    option.value = course.id;
                                    if (course.id === currentValue) {
                                        option.selected = true;
                                        found = true;
                                    }
                                    select.appendChild(option);
                                });

                                // A stale mapping stays visible and selected until the teacher changes it.
                                if (currentValue !== '' && !found) {
                                    var unknown = document.createElement('option');
                                    unknown.value = currentValue;
                                    unknown.textContent = strings.courseUnknown.split(idPlaceholder).join(currentValue);
                                    unknown.selected = true;
                                    select.appendChild(unknown);

                                    var warning = document.createElement('div');
                                    warning.className = 'alert alert-warning mt-2 skilland-course-unknown-warning';
                                    warning.setAttribute('role', 'status');
                                    warning.textContent = strings.courseUnknownWarning;
                                    container.appendChild(warning);
                                }

                                createBtn.disabled = false;
                            }).catch(function(error) {
                                log('Skilland: AJAX error', error && error.message);
                                restoreTextInput();
                                notification.addNotification({
                                    message: 'Failed to fetch courses from Skilland. Please check your API configuration.',
                                    type: 'error'
                                });
                            });
                        });
                    }
                })();
                ";
                $PAGE->requires->js_amd_inline($js);

                // Inject "Go to Skilland" button above the custom field via JS; it opens the linked course.
                $ssourl = (new \moodle_url('/mod/skilland/sso_redirect.php', [
                    'courseid' => $courseid,
                    'sesskey' => sesskey(),
                ]))->out(false);
                $buttontext = json_encode(\get_string('go_to_skilland', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $ssourljs = json_encode($ssourl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

                $buttonjs = "
                (function() {
                    function insertEdukamButton() {
                        var fieldInput = document.querySelector('[name=\"customfield_skilland_course_id\"]');
                        if (!fieldInput) return;

                        var formItem = fieldInput.closest('.fitem');
                        if (!formItem) return;

                        // Check if button already inserted.
                        if (document.getElementById('skilland-goto-btn')) return;

                        var div = document.createElement('div');
                        div.id = 'skilland-goto-btn';
                        div.className = 'd-flex justify-content-center mb-3';
                        var link = document.createElement('a');
                        link.className = 'btn btn-secondary';
                        link.href = " . $ssourljs . ";
                        link.target = '_blank';
                        var label = document.createElement('span');
                        label.textContent = " . $buttontext . ";
                        var icon = document.createElement('i');
                        icon.className = 'fa fa-external-link ml-1';
                        link.appendChild(label);
                        link.appendChild(document.createTextNode(' '));
                        link.appendChild(icon);
                        div.appendChild(link);

                        formItem.parentNode.insertBefore(div, formItem);
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', insertEdukamButton);
                    } else {
                        setTimeout(insertEdukamButton, 150);
                    }
                })();
                ";
                $PAGE->requires->js_amd_inline($buttonjs);
            }
        }
    }

}
