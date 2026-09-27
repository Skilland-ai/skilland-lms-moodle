/**
 * SkilLand course mapping on the course settings page (SKL-664, SKL-681).
 *
 * Turns the SkilLand course ID custom field into a dropdown of SkilLand courses, adds a
 * "Create in SkilLand" button that creates and links a course after a confirmation, keeps a
 * stale mapping visible with a warning, and inserts a "Go to SkilLand" button above the field.
 *
 * @module     mod_skilland/course_mapping
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification', 'mod_skilland/local/string_loader'], function(Ajax, Notification, StringLoader) {

    /** @var {boolean} debug Whether devmode console logging is enabled */
    var debug = false;

    // core/str fills {$a} with String.replace, which reads "$&" or "$'" in the value as a pattern,
    // so runtime values go in through these placeholders and a literal split/join instead.
    /** @var {string} namePlaceholder Stands for the Moodle course name in the confirmation body */
    var namePlaceholder = '__SKILLAND_COURSE_NAME__';
    /** @var {string} idPlaceholder Stands for the stale SkilLand course id */
    var idPlaceholder = '__SKILLAND_COURSE_ID__';
    /** @var {string} errorPlaceholder Stands for the error text returned by a web service */
    var errorPlaceholder = '__SKILLAND_ERROR__';

    /** @var {Array} STRINGS The language strings the module needs, fetched once through core/str */
    var STRINGS = [
        {name: 'createInSkilland', key: 'create_in_skilland'},
        {name: 'creatingCourse', key: 'creating_course'},
        {name: 'confirmTitle', key: 'create_course_confirm_title'},
        {name: 'confirmBody', key: 'create_course_confirm_body', param: namePlaceholder},
        {name: 'confirmReplace', key: 'create_course_confirm_replace'},
        {name: 'confirmYes', key: 'create_course_confirm_yes'},
        {name: 'courseUnknown', key: 'course_unknown', param: idPlaceholder},
        {name: 'courseUnknownWarning', key: 'course_unknown_warning'},
        {name: 'loadingCourses', key: 'loading_courses'},
        {name: 'selectSkillandCourse', key: 'select_skilland_course'},
        {name: 'errorFetchCourses', key: 'error_fetch_courses'},
        {name: 'errorCreateCourse', key: 'error_create_course', param: errorPlaceholder},
        {name: 'errorCreateCourseFailed', key: 'error_create_course_failed'},
        {name: 'errorFetchCoursesDetail', key: 'error_fetch_courses_detail', param: errorPlaceholder},
        {name: 'goToSkilland', key: 'go_to_skilland'}
    ];

    /**
     * Log to the console, only when the plugin's devmode setting is on.
     */
    function log() {
        if (debug && window.console) {
            console.log.apply(console, arguments);
        }
    }

    /**
     * Escape a value for use as HTML (notifications and modal bodies render their text as HTML).
     *
     * @param {*} str
     * @return {string}
     */
    function escapeHtml(str) {
        return String(str === undefined || str === null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Insert the "Go to SkilLand" button above the custom field; it opens the linked course.
     *
     * @param {HTMLElement} fieldInput The custom field's text input
     * @param {string} ssoUrl The sso_redirect.php URL for this course
     * @param {string} labelText
     */
    function insertGoToSkillandButton(fieldInput, ssoUrl, labelText) {
        var formItem = fieldInput.closest('.fitem');
        if (!formItem) {
            return;
        }

        // Check if button already inserted.
        if (document.getElementById('skilland-goto-btn')) {
            return;
        }

        var div = document.createElement('div');
        div.id = 'skilland-goto-btn';
        div.className = 'd-flex justify-content-center mb-3';
        var link = document.createElement('a');
        link.className = 'btn btn-secondary';
        link.href = ssoUrl;
        link.target = '_blank';
        var label = document.createElement('span');
        label.textContent = labelText;
        var icon = document.createElement('i');
        icon.className = 'fa fa-external-link ml-1';
        link.appendChild(label);
        link.appendChild(document.createTextNode(' '));
        link.appendChild(icon);
        div.appendChild(link);

        formItem.parentNode.insertBefore(div, formItem);
    }

    /**
     * Replace the custom field's text input with a SkilLand course dropdown and a create button.
     *
     * @param {HTMLElement} fieldInput The custom field's text input
     * @param {Object} config The init configuration
     * @param {Object} strings The resolved language strings
     */
    function buildMappingField(fieldInput, config, strings) {
        var moodleCourseId = config.courseid;
        var courseName = config.coursename;
        var linked = !!config.linked;
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
        loadingOption.textContent = strings.loadingCourses;
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
            Notification.saveCancelPromise(
                escapeHtml(strings.confirmTitle),
                body,
                escapeHtml(strings.confirmYes)
            ).then(function() {
                createBtn.disabled = true;
                createBtn.textContent = strings.creatingCourse;
                return Ajax.call([{
                    methodname: 'mod_skilland_create_course_ajax',
                    args: {moodlecourseid: moodleCourseId}
                }])[0].then(function(resp) {
                    if (resp.error) {
                        Notification.addNotification({
                            message: escapeHtml(strings.errorCreateCourse.split(errorPlaceholder).join(resp.error)),
                            type: 'error'
                        });
                        return;
                    }
                    selectCourse(resp.skillid, resp.name);
                    linked = true;
                }).catch(function() {
                    Notification.addNotification({
                        message: strings.errorCreateCourseFailed,
                        type: 'error'
                    });
                }).then(function() {
                    createBtn.textContent = strings.createInSkilland;
                    createBtn.disabled = false;
                });
            }, function() {
                log('Skilland: course creation cancelled');
            });
        }

        createBtn.addEventListener('click', onCreateClick);

        // Fetch the SkilLand courses this site can link to.
        log('Skilland: Making AJAX call to fetch courses');
        Ajax.call([{
            methodname: 'mod_skilland_fetch_courses_ajax',
            args: {moodlecourseid: moodleCourseId}
        }])[0].then(function(response) {
            log('Skilland: AJAX response received');
            if (response.error) {
                restoreTextInput();
                Notification.addNotification({
                    message: escapeHtml(strings.errorFetchCoursesDetail.split(errorPlaceholder).join(response.error)),
                    type: 'error'
                });
                return;
            }

            while (select.firstChild) {
                select.removeChild(select.firstChild);
            }
            var emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = strings.selectSkillandCourse;
            select.appendChild(emptyOption);

            var found = false;
            (response.courses || []).forEach(function(course) {
                var optionText = course.name;
                if (course.code) {
                    optionText += ' (' + course.code + ')';
                }
                if (course.status) {
                    optionText += ' [' + course.status + ']';
                }

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
            Notification.addNotification({
                message: strings.errorFetchCourses,
                type: 'error'
            });
        });
    }

    /**
     * Find the custom field and, once the strings resolve, build both controls.
     *
     * @param {Object} config The init configuration
     */
    function start(config) {
        // Moodle names the custom field input after its shortname, whatever its label or language.
        var fieldInput = document.querySelector('[name="customfield_skilland_course_id"]');
        log('Skilland: Found input?', !!fieldInput);
        if (!fieldInput) {
            return;
        }

        // Nothing is built or bound before the strings resolve; if they never do, the plain
        // text input stays in place and still submits the value.
        StringLoader.loadStrings(STRINGS).then(function(strings) {
            insertGoToSkillandButton(fieldInput, config.ssourl, strings.goToSkilland);
            buildMappingField(fieldInput, config, strings);
            return strings;
        }).catch(Notification.exception);
    }

    /**
     * Initialise the course mapping controls.
     *
     * @param {Object} config Configuration object
     * @param {number} config.courseid The Moodle course id
     * @param {string} config.coursename The Moodle course full name, as plain text
     * @param {boolean} config.linked Whether the course already has a SkilLand course mapped
     * @param {string} config.ssourl The "Go to SkilLand" sso_redirect.php URL
     * @param {boolean} config.debug Whether the plugin's devmode console logging is on
     */
    var init = function(config) {
        debug = !!config.debug;
        log('Skilland: Initializing course mapping field for course', config.courseid);

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                start(config);
            });
        } else {
            start(config);
        }
    };

    return {
        init: init
    };
});
