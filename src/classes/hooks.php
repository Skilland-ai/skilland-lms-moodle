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
                // Add JavaScript to enhance the Skilland Course ID custom field.
                // Use inline JavaScript that directly implements the functionality
                // without relying on AMD module loading.
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

                    log('Skilland: Initializing course mapping field for course " . $courseid . "');

                    // Wait for DOM to be ready.
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', initSkillandField);
                    } else {
                        setTimeout(initSkillandField, 100);
                    }

                    function initSkillandField() {
                        // Find the Skilland Course ID custom field input.
                        var fieldInput = document.querySelector('input[id*=\"customfield_skilland_course_id\"], input[name*=\"customfield_skilland_course_id\"], input[id*=\"id_customfield_skilland_course_id\"]');

                        if (!fieldInput) {
                            // Try finding by label text.
                            var labels = document.querySelectorAll('label');
                            for (var i = 0; i < labels.length; i++) {
                                if (labels[i].textContent.indexOf('Skilland Course ID') !== -1) {
                                    var forAttr = labels[i].getAttribute('for');
                                    if (forAttr) {
                                        fieldInput = document.getElementById(forAttr);
                                        if (fieldInput) break;
                                    }
                                }
                            }
                        }

                        if (!fieldInput) {
                            // Last resort: find input in form item with label.
                            var fitems = document.querySelectorAll('.fitem');
                            for (var i = 0; i < fitems.length; i++) {
                                var label = fitems[i].querySelector('label');
                                if (label && label.textContent.indexOf('Skilland Course ID') !== -1) {
                                    fieldInput = fitems[i].querySelector('input[type=\"text\"]');
                                    if (fieldInput) break;
                                }
                            }
                        }

                        log('Skilland: Found input?', !!fieldInput);

                        if (fieldInput) {
                            var currentValue = fieldInput.value;
                            log('Skilland: Current value', currentValue);

                            // Find the parent form item (.fitem) that contains the entire field (label + input + description).
                            var formItem = fieldInput.closest('.fitem');
                            if (!formItem) {
                                // Fallback: find parent element that contains the label.
                                var parent = fieldInput.parentElement;
                                while (parent && !parent.classList.contains('fitem')) {
                                    parent = parent.parentElement;
                                }
                                formItem = parent;
                            }

                            // Find the description element that contains the help text.
                            // The description might be in a sibling .felement or within the .fitem.
                            var descriptionElement = null;
                            if (formItem) {
                                // Look for elements containing the description text.
                                var allElements = formItem.querySelectorAll('*');
                                for (var i = 0; i < allElements.length; i++) {
                                    var el = allElements[i];
                                    var text = el.textContent || el.innerText || '';
                                    if (text.indexOf('The Skilland Course ID associated') !== -1 ||
                                        text.indexOf('used to link all Skilland activities') !== -1) {
                                        descriptionElement = el;
                                        break;
                                    }
                                }
                                // If not found in formItem, check siblings.
                                if (!descriptionElement && formItem.nextElementSibling) {
                                    var sibling = formItem.nextElementSibling;
                                    var siblingText = sibling.textContent || sibling.innerText || '';
                                    if (siblingText.indexOf('The Skilland Course ID associated') !== -1) {
                                        descriptionElement = sibling;
                                    }
                                }
                                // Also check parent container - sometimes custom fields have a wrapper div.
                                if (!descriptionElement && formItem.parentElement) {
                                    var parent = formItem.parentElement;
                                    var parentText = parent.textContent || parent.innerText || '';
                                    // Check if parent contains the description text but also contains other fields.
                                    // If parent only contains this field and its description, we can hide the parent.
                                    if (parentText.indexOf('The Skilland Course ID associated') !== -1) {
                                        // Count how many .fitem elements are in the parent.
                                        var fitemCount = parent.querySelectorAll('.fitem').length;
                                        if (fitemCount === 1) {
                                            // Parent only contains this one field, so we can hide the parent instead.
                                            formItem = parent;
                                        } else {
                                            // Parent contains multiple fields, just hide the description element.
                                            descriptionElement = parent.querySelector('.form-text, .felement, .form-description');
                                        }
                                    }
                                }
                            }

                            // Pending redirect URL — opened in new tab on form submit.
                            var pendingRedirectUrl = null;

                            // Create container and select dropdown.
                            var container = document.createElement('div');
                            container.className = 'skilland-course-mapping-field';

                            var select = document.createElement('select');
                            select.name = fieldInput.name;
                            select.id = fieldInput.id;
                            select.className = 'form-control';
                            select.style.width = '100%';
                            select.innerHTML = '<option value=\"\">Loading courses from Skilland...</option>';

                            var hiddenInput = document.createElement('input');
                            hiddenInput.type = 'hidden';
                            hiddenInput.name = fieldInput.name;
                            hiddenInput.value = currentValue;

                            // Insert after fieldInput.
                            fieldInput.parentNode.insertBefore(container, fieldInput.nextSibling);
                            container.appendChild(select);
                            container.appendChild(hiddenInput);
                            fieldInput.style.display = 'none';

                            // Sync select to hidden input and original field.
                            select.addEventListener('change', function() {
                                var value = this.value;

                                if (value === '__create_new__') {
                                    // Disable select and show creating state.
                                    select.disabled = true;
                                    var originalText = select.options[select.selectedIndex].textContent;
                                    select.options[select.selectedIndex].textContent = " . json_encode(\get_string('creating_course', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";

                                    require(['core/ajax', 'core/notification'], function(ajax, notification) {
                                        ajax.call([{
                                            methodname: 'mod_skilland_create_course_ajax',
                                            args: {moodlecourseid: " . $courseid . "}
                                        }])[0].then(function(resp) {
                                            if (resp.error) {
                                                notification.addNotification({
                                                    message: escapeHtml('Failed to create course: ' + resp.error),
                                                    type: 'error'
                                                });
                                                // Restore the create option text and re-enable.
                                                select.options[select.selectedIndex].textContent = originalText;
                                                select.value = '';
                                                select.disabled = false;
                                                return;
                                            }

                                            // Add the new course as an option and select it.
                                            var newOption = document.createElement('option');
                                            newOption.value = resp.skillid;
                                            newOption.textContent = resp.name;
                                            select.appendChild(newOption);
                                            select.value = resp.skillid;
                                            hiddenInput.value = resp.skillid;
                                            fieldInput.value = resp.skillid;

                                            // Restore the create option text.
                                            var createOpt = select.querySelector('option[value=\"__create_new__\"]');
                                            if (createOpt) {
                                                createOpt.textContent = originalText;
                                            }

                                            select.disabled = false;

                                            // Store redirect URL to open after form save.
                                            if (resp.redirect_url) {
                                                pendingRedirectUrl = resp.redirect_url;
                                            }
                                        }).catch(function(error) {
                                            notification.addNotification({
                                                message: 'Failed to create course in Skilland.',
                                                type: 'error'
                                            });
                                            select.options[select.selectedIndex].textContent = originalText;
                                            select.value = '';
                                            select.disabled = false;
                                        });
                                    });
                                    return;
                                }

                                hiddenInput.value = value;
                                fieldInput.value = value;
                            });

                            // Fetch courses via AJAX using Moodle's core/ajax.
                            require(['core/ajax', 'core/notification'], function(ajax, notification) {
                                log('Skilland: Making AJAX call to fetch courses');
                                ajax.call([{
                                    methodname: 'mod_skilland_fetch_courses_ajax',
                                    args: {moodlecourseid: " . $courseid . "}
                                }])[0].then(function(response) {
                                    log('Skilland: AJAX response received');
                                    // Check if there's an error in the response.
                                    if (response.error) {
                                        // On error, revert to text input.
                                        fieldInput.style.display = '';
                                        container.style.display = 'none';

                                        notification.addNotification({
                                            message: escapeHtml('Failed to fetch courses from Skilland: ' + response.error),
                                            type: 'error'
                                        });
                                        return;
                                    }

                                    select.innerHTML = '<option value=\"\">Select a Skilland course...</option>';

                                    // Add '+ Create in Skilland' option.
                                    var createOption = document.createElement('option');
                                    createOption.value = '__create_new__';
                                    createOption.textContent = " . json_encode(\get_string('create_in_skilland', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ";
                                    createOption.style.fontWeight = 'bold';
                                    select.appendChild(createOption);

                                    if (response.courses && response.courses.length > 0) {
                                        response.courses.forEach(function(course) {
                                            var optionText = course.name;
                                            if (course.code) optionText += ' (' + course.code + ')';
                                            if (course.status) optionText += ' [' + course.status + ']';

                                            var option = document.createElement('option');
                                            option.textContent = optionText;
                                            option.value = course.id;
                                            if (course.id === currentValue) {
                                                option.selected = true;
                                                hiddenInput.value = course.id;
                                            }
                                            select.appendChild(option);
                                        });
                                    }
                                }).catch(function(error) {
                                    log('Skilland: AJAX error', error && error.message);
                                    // On error, revert to text input.
                                    fieldInput.style.display = '';
                                    container.style.display = 'none';

                                    notification.addNotification({
                                        message: 'Failed to fetch courses from Skilland. Please check your API configuration.',
                                        type: 'error'
                                    });
                                });
                            });

                            // On form submit, open the pending Skilland redirect in a new tab.
                            var form = fieldInput.closest('form');
                            if (form) {
                                form.addEventListener('submit', function() {
                                    if (pendingRedirectUrl) {
                                        if (/^https?:\/\//i.test(pendingRedirectUrl)) {
                                            window.open(pendingRedirectUrl, '_blank');
                                        } else {
                                            log('Skilland: ignoring non-http(s) redirect URL');
                                        }
                                        pendingRedirectUrl = null;
                                    }
                                });
                            }
                        }
                    }
                })();
                ";
                $PAGE->requires->js_amd_inline($js);

                // Inject "Go to Skilland" button above the custom field via JS.
                $ssourl = (new \moodle_url('/mod/skilland/sso_redirect.php', [
                    'sesskey' => sesskey()
                ]))->out(false);
                $buttontext = json_encode(\get_string('go_to_skilland', 'mod_skilland'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
                $ssourljs = json_encode($ssourl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

                $buttonjs = "
                (function() {
                    function insertEdukamButton() {
                        var fieldInput = document.querySelector('input[id*=\"customfield_skilland_course_id\"], input[name*=\"customfield_skilland_course_id\"], input[id*=\"id_customfield_skilland_course_id\"]');
                        if (!fieldInput) {
                            var labels = document.querySelectorAll('label');
                            for (var i = 0; i < labels.length; i++) {
                                if (labels[i].textContent.indexOf('Skilland Course ID') !== -1) {
                                    var forAttr = labels[i].getAttribute('for');
                                    if (forAttr) {
                                        fieldInput = document.getElementById(forAttr);
                                        if (fieldInput) break;
                                    }
                                }
                            }
                        }
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
