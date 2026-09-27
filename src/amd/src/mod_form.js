/**
 * Activity settings form for mod_skilland (mod_form.php).
 *
 * Populates the topic select and the lesson checkboxes from the SkilLand API through
 * Moodle web services, keeps the lesson selection per topic in the selected_lessons
 * hidden field, fills Name/Description from the picked topic and offers the
 * "Update from Skilland" action. When the course has no Skilland Course ID mapped it
 * only hides the form around the warning PHP renders.
 *
 * @module     mod_skilland/mod_form
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification', 'core/str'], function(Ajax, Notification, Str) {

    /** @var {boolean} debug Whether debug logging is enabled (the plugin's devmode setting) */
    var debug = false;

    // core/str fills {$a} with String.replace, which reads "$&" or "$'" in the value as a pattern,
    // so runtime values go in through these placeholders and a literal split/join instead (fillIn()).
    /** @var {string} datePlaceholder Stands for a lesson's formatted update date in updated_on */
    var datePlaceholder = '__SKILLAND_DATE__';
    /** @var {string} errorPlaceholder Stands for the error text in error_fetch_topics_detail */
    var errorPlaceholder = '__SKILLAND_ERROR__';
    /** @var {string} settingPlaceholder Stands for the "Lock after first access" label in its hint */
    var settingPlaceholder = '__SKILLAND_SETTING__';

    /**
     * Every string the form's script shows, loaded once through core/str before any handler is bound.
     * The two "_students" variants take the student count as their {$a}; updated_on,
     * error_fetch_topics_detail and lockafterfirstaccess_hint get a placeholder that fillIn() swaps
     * for the runtime value.
     *
     * @param {number} studentCount Students with progress on the saved topic
     * @return {Object[]}
     */
    function stringRequests(studentCount) {
        return [
            {key: 'loading', component: 'mod_skilland'},
            {key: 'error_fetch_topics', component: 'mod_skilland'},
            {key: 'error_fetch_topics_detail', component: 'mod_skilland', param: errorPlaceholder},
            {key: 'select_topic', component: 'mod_skilland'},
            {key: 'no_topics_available', component: 'mod_skilland'},
            {key: 'no_lessons_found', component: 'mod_skilland'},
            {key: 'current_topic_unavailable', component: 'mod_skilland'},
            {key: 'topic_no_longer_available', component: 'mod_skilland'},
            {key: 'topic_no_longer_available_warning', component: 'mod_skilland'},
            {key: 'retry', component: 'mod_skilland'},
            {key: 'missing_lessons_warning', component: 'mod_skilland'},
            {key: 'remove_from_activity', component: 'mod_skilland'},
            {key: 'new_content_available', component: 'mod_skilland'},
            {key: 'updated_on', component: 'mod_skilland', param: datePlaceholder},
            {key: 'update_confirm_title', component: 'mod_skilland'},
            {key: 'update_confirm_message', component: 'mod_skilland'},
            {key: 'update_confirm_message_students', component: 'mod_skilland', param: studentCount},
            {key: 'update_success', component: 'mod_skilland'},
            {key: 'update_error', component: 'mod_skilland'},
            {key: 'topic_change_confirm_title', component: 'mod_skilland'},
            {key: 'topic_change_confirm', component: 'mod_skilland'},
            {key: 'topic_change_confirm_students', component: 'mod_skilland', param: studentCount},
            {key: 'destructive_confirm_action', component: 'mod_skilland'},
            {key: 'lockafterfirstaccess', component: 'mod_skilland'},
            {key: 'lockafterfirstaccess_hint', component: 'mod_skilland', param: settingPlaceholder},
            {key: 'yes', component: 'core'},
            {key: 'no', component: 'core'}
        ];
    }

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
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showLessonsError(container, message) {
        container.innerHTML = '';
        var errorSpan = document.createElement('span');
        errorSpan.className = 'text-danger';
        errorSpan.textContent = message;
        container.appendChild(errorSpan);
    }

    /**
     * Put a runtime value where core/str left a placeholder, literally (no replacement patterns).
     *
     * @param {string} text String fetched with the placeholder as its {$a}
     * @param {string} placeholder
     * @param {*} value
     * @return {string}
     */
    function fillIn(text, placeholder, value) {
        return String(text).split(placeholder).join(String(value));
    }

    /**
     * Runs fn once the DOM is ready.
     *
     * @param {Function} fn
     */
    function whenReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            setTimeout(fn, 100);
        }
    }

    /**
     * No Skilland Course ID on the course: hide every form item and header around the warning.
     * styles.css hides the headers without JS too; this also hides the remaining items.
     */
    function hideUnmappedFormFields() {
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
                '[class*="fheader"]',
                '[id^="id_"][id$="header"]',
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
                } catch (e) {
                    // Ignore selector errors
                }
            });
            var collapsed = form.querySelectorAll('.collapsible-actions, .collapsed, [class*="collapsible"]');
            collapsed.forEach(function(collapsed) {
                collapsed.style.display = 'none';
            });
        }
    }

    /**
     * Reads the DB-saved lesson selection PHP renders as a JSON data attribute (too large for
     * js_call_amd's arguments on a long topic).
     *
     * @param {string} elementId
     * @return {Object|Array}
     */
    function readCurrentSelectedLessons(elementId) {
        var holder = elementId ? document.getElementById(elementId) : null;
        if (!holder) {
            return {};
        }
        try {
            var parsed = JSON.parse(holder.getAttribute('data-lessons') || '{}');
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    /**
     * The activity form proper, run once the strings are available.
     *
     * @param {Object} config See init()
     * @param {Object} strings String values by key
     */
    function initActivityForm(config, strings) {
        var skillandCourseId = config.skillandcourseid;
        var moodleCourseId = config.moodlecourseid;
        var currentTopicId = config.currenttopicid || '';
        var currentSelectedLessons = readCurrentSelectedLessons(config.currentlessonsid);
        var selectionsByTopic = {}; // Ticked lessons per topic ID, restored when the topic is shown again.
        var missingLessonsByTopic = {}; // Per topic ID: DB-saved lessons the API no longer lists, pending an explicit Remove.
        var renderedTopicId = null; // Topic whose lessons are rendered as checkboxes, null while loading.
        var activeTopicId = null; // Topic last picked in the dropdown (SKL-655 reuses it as the previous value).
        var hasScorm = !!config.hasscorm; // The activity has a provisioned SCORM (SKL-655).
        var confirmedTopicId = null; // Topic the teacher just confirmed leaving the saved topic for.
        // Destructive-confirmation copy (SKL-697): a clear action label and the count of students
        // who would lose progress, only when there is anything to lose.
        var studentAttemptCount = parseInt(config.studentattemptcount, 10) || 0;
        var lockAfterFirstAccessHint = fillIn(strings.lockafterfirstaccess_hint, settingPlaceholder,
            strings.lockafterfirstaccess);
        var topicChangeConfirmTitle = strings.topic_change_confirm_title;
        var topicChangeConfirmMessage = (studentAttemptCount > 0 ?
            strings.topic_change_confirm_students : strings.topic_change_confirm) + ' ' + lockAfterFirstAccessHint;
        var topicChangeConfirmActionLabel = studentAttemptCount > 0 ? strings.destructive_confirm_action : strings.yes;
        var lessonsRequestSeq = 0; // Bumped per lessons request; a response with an older token is dropped.
        var topicsMap = {}; // Store full topic objects by ID
        var editLinkHtml = config.editlinkhtml;
        var ssoRedirectUrl = config.ssourl;

        log('Skilland: Initialising form with currentTopicId:', currentTopicId);
        log('Skilland: currentSelectedLessons:', currentSelectedLessons);
        applyGeneralSectionVisibility();
        initTopicSelect();

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
                savedTopicInput = topicSelect.form.querySelector('input[name="skilland_topicid_saved"]');
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

            var loadingText = strings.loading;
            var errorText = strings.error_fetch_topics;
            var selectTopicText = strings.select_topic;
            var noTopicsText = strings.no_topics_available;
            var noLessonsText = strings.no_lessons_found;
            var currentTopicUnavailableText = strings.current_topic_unavailable;
            var topicNoLongerAvailableText = strings.topic_no_longer_available;
            var topicNoLongerAvailableWarning = strings.topic_no_longer_available_warning;
            var retryText = strings.retry;
            var missingLessonsWarningText = strings.missing_lessons_warning;
            var removeMissingLessonText = strings.remove_from_activity;
            var newContentAvailableText = strings.new_content_available;
            var updateConfirmTitle = strings.update_confirm_title;
            var updateConfirmMessage = (studentAttemptCount > 0 ?
                strings.update_confirm_message_students : strings.update_confirm_message) + ' ' + lockAfterFirstAccessHint;
            var updateConfirmActionLabel = studentAttemptCount > 0 ? strings.destructive_confirm_action : strings.yes;
            var updateSuccessText = strings.update_success;
            var updateErrorText = strings.update_error;
            var skillandInstanceId = config.skillandinstanceid || 0;
            var cmId = config.cmid || 0;

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
            topicSelect.innerHTML = '<option value="">' + escapeHtml(loadingText) + '</option>';
            topicSelect.disabled = true;

            // Set up update button click handler
            var updateBtn = document.getElementById('skilland-update-btn');
            var updateLoading = document.getElementById('skilland-update-loading');

            if (updateBtn && skillandInstanceId && cmId) {
                updateBtn.disabled = false;
                updateBtn.addEventListener('click', function() {
                    // Show confirmation dialog using Moodle's notification module, styled as a
                    // destructive action when it will actually delete student progress (SKL-697).
                    Notification.confirm(
                        updateConfirmTitle,
                        updateConfirmMessage,
                        updateConfirmActionLabel,
                        strings.no,
                        function() {
                            // User confirmed - perform the update
                            performUpdate();
                        }
                    ).then(function(modal) {
                        if (modal && typeof modal.getRoot === 'function') {
                            modal.getRoot().find('[data-action="save"]')
                                .removeClass('btn-primary btn-outline-danger')
                                .addClass('btn-danger');
                        }
                        return modal;
                    }).catch(function() {
                        // Notification.confirm already reports its own failures.
                    });
                });
            }

            function performUpdate() {
                if (!updateBtn || !updateLoading) {
                    return;
                }

                // Show loading state
                updateBtn.classList.add('d-none');
                updateLoading.classList.remove('d-none');

                Ajax.call([{
                    methodname: 'mod_skilland_update_topic_scorm_ajax',
                    args: {
                        skillandid: skillandInstanceId,
                        cmid: cmId
                    }
                }])[0].then(function(response) {
                    if (response.error) {
                        Notification.addNotification({
                            message: escapeHtml(updateErrorText + ': ' + response.error),
                            type: 'error'
                        });
                        // Reset button state
                        updateBtn.classList.remove('d-none');
                        updateLoading.classList.add('d-none');
                    } else {
                        Notification.addNotification({
                            message: updateSuccessText,
                            type: 'success'
                        });
                        // Reload the page to reflect the updated content
                        window.location.reload();
                    }
                    return response;
                }).catch(function(error) {
                    Notification.addNotification({
                        message: escapeHtml(updateErrorText + ': ' + error.message),
                        type: 'error'
                    });
                    // Reset button state
                    updateBtn.classList.remove('d-none');
                    updateLoading.classList.add('d-none');
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
                    Notification.confirm(
                        topicChangeConfirmTitle,
                        topicChangeConfirmMessage,
                        topicChangeConfirmActionLabel,
                        strings.no,
                        function() {
                            confirmedTopicId = topicId;
                            topicSelect.value = topicId;
                            topicSelect.dispatchEvent(new Event('change', {bubbles: true}));
                        },
                        function() {
                            topicSelect.value = previousTopicId;
                        }
                    ).then(function(modal) {
                        if (modal && typeof modal.getRoot === 'function') {
                            modal.getRoot().find('[data-action="save"]')
                                .removeClass('btn-primary btn-outline-danger')
                                .addClass('btn-danger');
                        }
                        return modal;
                    }).catch(function() {
                        // Notification.confirm already reports its own failures.
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
                                          topicSelect.form.querySelector('input[name="topic_orderindex"]');
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
                return fillIn(strings.updated_on, datePlaceholder, date.toLocaleString());
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
                return text.replace(/\s+/g, ' ').trim();
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
                return !htmlToText(html) && !/<(img|video|audio|iframe|object)\b/i.test(String(html || ''));
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
                if (!lessonsContainer) {
                    return;
                }

                var seq = ++lessonsRequestSeq;
                lessonsContainer.innerHTML = '<em>' + escapeHtml(loadingText) + '</em>';
                setLessonsLoading(true);

                log('Skilland: Fetching lessons for topic', topicId);
                Ajax.call([{
                    methodname: 'mod_skilland_fetch_lessons_ajax',
                    args: {
                        topicid: topicId,
                        moodlecourseid: moodleCourseId
                    }
                }])[0].then(function(response) {
                    // A newer request or another topic owns the list now: drop this response.
                    if (seq !== lessonsRequestSeq || String(topicSelect.value) !== String(topicId)) {
                        return response;
                    }

                    if (response.error) {
                        selectedLessonsInput.value = '{}';
                        showLessonsError(lessonsContainer, response.error);
                        setLessonsLoading(false);
                        return response;
                    }

                    if (!response.lessons || response.lessons.length === 0) {
                        selectionsByTopic[topicId] = {};
                        selectedLessonsInput.value = '{}';
                        lessonsContainer.innerHTML = '<em>' + escapeHtml(noLessonsText) + '</em>';
                        setLessonsLoading(false);
                        return response;
                    }

                    log('Skilland: Lessons response received');
                    renderLessons(response.lessons, topicId);
                    setLessonsLoading(false);
                    return response;
                }).catch(function(error) {
                    if (seq !== lessonsRequestSeq || String(topicSelect.value) !== String(topicId)) {
                        return;
                    }
                    selectedLessonsInput.value = '{}';
                    showLessonsError(lessonsContainer, error.message);
                    setLessonsLoading(false);
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
                    selectActions.classList.remove('d-none');
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
                        checkboxes.forEach(function(cb) {
                            cb.checked = true;
                        });
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
                        checkboxes.forEach(function(cb) {
                            cb.checked = false;
                        });
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
                    editButtonContainer.classList.remove('d-none');

                    // Generate SSO URL
                    var ssoUrl = ssoRedirectUrl;
                    var params = {
                        'topicid': topic.id,
                        'sesskey': M.cfg.sesskey
                    };

                    // Pass Moodle course ID so sso_redirect.php can verify capability
                    // and look up the Skilland skill ID server-side.
                    if (moodleCourseId) {
                        params.courseid = moodleCourseId;
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
                    editButtonContainer.classList.add('d-none');
                }
            }

            // Fetch topics via AJAX. Named so the Retry control (shown on failure) can
            // call it again without duplicating the request logic.
            function fetchTopics() {
                hideTopicRetryControl();
                log('Skilland: Fetching topics for course', skillandCourseId);
                log('Skilland: About to call mod_skilland_fetch_topics_ajax');
                Ajax.call([{
                    methodname: 'mod_skilland_fetch_topics_ajax',
                    args: {
                        courseid: skillandCourseId,
                        moodlecourseid: moodleCourseId
                    }
                }])[0].then(function(response) {
                    log('Skilland: Topics response received');

                    if (response.error) {
                        showTopicFetchError();
                        Notification.addNotification({
                            message: escapeHtml(fillIn(strings.error_fetch_topics_detail, errorPlaceholder, response.error)),
                            type: 'error'
                        });
                        return response;
                    }

                    // Update Skilland Course ID display with actual course name if available.
                    if (response.course && response.course.name) {
                        var courseDisplayEl = document.querySelector(
                            '#fitem_id_skilland_course_id_display .felement, #fitem_id_skilland_course_id_display .fstatic');
                        if (courseDisplayEl) {
                            courseDisplayEl.innerHTML = '';
                            var nameSpan = document.createElement('span');
                            nameSpan.className = 'skilland-course-name';
                            nameSpan.textContent = response.course.name;
                            courseDisplayEl.appendChild(nameSpan);
                            // Only show code if it's different from the name and doesn't look like a long ID
                            if (response.course.code && response.course.code !== response.course.name &&
                                    response.course.code.length < 20) {
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
                    topicSelect.innerHTML = '<option value="">' + escapeHtml(selectTopicText) + '...</option>';
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
                            var changeEvent = new Event('change', {bubbles: true});
                            topicSelect.dispatchEvent(changeEvent);
                        } else {
                            addStaleTopicOption(currentTopicId);
                            Notification.addNotification({
                                message: escapeHtml(topicNoLongerAvailableWarning),
                                type: 'warning'
                            });
                            renderStaleTopicLessons();
                        }
                    }
                    return response;
                }).catch(function(error) {
                    log('Skilland: AJAX error', error && error.message);
                    showTopicFetchError();
                    Notification.addNotification({
                        message: escapeHtml(fillIn(strings.error_fetch_topics_detail, errorPlaceholder,
                            error.message || JSON.stringify(error))),
                        type: 'error'
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
            // for a topic that no longer exists there. Nothing here touches the hidden input
            // or the per-topic map, so the value already rendered server-side is what gets saved.
            function renderStaleTopicLessons() {
                lessonsContainer.innerHTML = '';
                var ids = Object.keys(currentSelectedLessons);
                if (ids.length === 0) {
                    lessonsContainer.innerHTML = '<em>' + escapeHtml(noLessonsText) + '</em>';
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
    }

    /**
     * Initialise the activity settings form.
     *
     * @param {Object} config Configuration object
     * @param {boolean} [config.missingcourseid] The course has no Skilland Course ID: only hide the form
     * @param {boolean} [config.debug] Whether to enable debug logging (devmode)
     * @param {string} config.skillandcourseid The Skilland course (skill) ID mapped to this course
     * @param {number} config.moodlecourseid The Moodle course ID
     * @param {string} config.currenttopicid The saved topic ID ('' for a new activity)
     * @param {string} config.currentlessonsid Id of the element whose data-lessons holds the saved lessons JSON
     * @param {boolean} config.hasscorm The activity has a provisioned SCORM (SKL-655)
     * @param {number} config.studentattemptcount Students with progress on the saved topic (SKL-697)
     * @param {number} config.skillandinstanceid The skilland record ID (0 for a new activity)
     * @param {number} config.cmid The course module ID (0 for a new activity)
     * @param {string} config.ssourl The sso_redirect.php URL
     * @param {string} config.editlinkhtml Trusted "Edit course settings" link built by html_writer
     */
    var init = function(config) {
        config = config || {};
        debug = !!config.debug;

        if (config.missingcourseid) {
            whenReady(hideUnmappedFormFields);
            return;
        }

        // Nothing is interactive until the strings the handlers show have resolved: the topic
        // select and the Update button stay disabled meanwhile, so a fast click can never
        // reach a handler that has no text to show.
        var topicSelect = document.getElementById('id_skilland_topicid');
        if (topicSelect) {
            topicSelect.disabled = true;
        }
        var updateBtn = document.getElementById('skilland-update-btn');
        if (updateBtn) {
            updateBtn.disabled = true;
        }

        var requests = stringRequests(parseInt(config.studentattemptcount, 10) || 0);
        var started = false;
        var start = function(values) {
            if (started) {
                return;
            }
            started = true;
            var strings = {};
            requests.forEach(function(request, index) {
                strings[request.key] = values && typeof values[index] === 'string' ? values[index] : request.key;
            });
            whenReady(function() {
                initActivityForm(config, strings);
            });
        };

        Str.get_strings(requests).done(start).fail(function(error) {
            // A string failure must not leave the form unusable: report it and carry on with the keys.
            Notification.exception(error);
            start(null);
        });
    };

    return {
        init: init
    };
});
