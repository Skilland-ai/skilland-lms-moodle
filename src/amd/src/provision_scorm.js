/**
 * SCORM provisioning module for Skilland.
 *
 * Handles the provision button click and AJAX call to create SCORM activities.
 * Supports both topic-level (new) and lesson-level (legacy) provisioning.
 *
 * @module     mod_skilland/provision_scorm
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {

    /** @var {boolean} debug Whether debug logging is enabled */
    var debug = false;

    /**
     * Log a message to console if debug mode is enabled.
     *
     * @param {string} message The message to log
     * @param {*} [data] Optional data to log
     */
    var log = function(message, data) {
        if (debug && window.console) {
            if (data !== undefined) {
                console.log(message, data);
            } else {
                console.log(message);
            }
        }
    };

    /**
     * Initialize the provision button handler.
     *
     * @param {Object} config Configuration object
     * @param {number} [config.skillandid] The skilland activity ID (for topic-level provisioning)
     * @param {number} [config.lessonid] The lesson ID (for legacy lesson-level provisioning)
     * @param {number} config.cmid The course module ID
     * @param {boolean} [config.debug] Whether to enable debug logging
     */
    var init = function(config) {
        debug = config.debug || false;
        log('Skilland provision_scorm init called with config:', config);

        var skillandId = config.skillandid ? parseInt(config.skillandid, 10) : 0;
        var lessonId = config.lessonid ? parseInt(config.lessonid, 10) : 0;
        var cmId = parseInt(config.cmid, 10);

        log('Skilland provision_scorm: skillandId=' + skillandId + ', lessonId=' + lessonId + ', cmId=' + cmId);

        // Bind click handler to provision button.
        $('#skilland-provision-btn').on('click', function(e) {
            e.preventDefault();
            log('Skilland provision_scorm: Provision button clicked');

            if (skillandId > 0) {
                // Topic-level provisioning (new).
                provisionTopicContent(skillandId, cmId);
            } else if (lessonId > 0) {
                // Lesson-level provisioning (legacy).
                provisionLessonContent(lessonId, cmId);
            } else {
                showError('No valid ID provided for provisioning');
            }
        });
    };

    /**
     * Provision topic-level SCORM content (new architecture).
     *
     * @param {number} skillandId The skilland activity record ID
     * @param {number} cmId The course module ID
     */
    var provisionTopicContent = function(skillandId, cmId) {
        var $button = $('#skilland-provision-btn');
        var $loading = $('#skilland-provision-loading');
        var $error = $('#skilland-provision-error');

        // Disable button and show loading.
        $button.prop('disabled', true).addClass('d-none');
        $loading.removeClass('d-none');
        $error.addClass('d-none').text('');

        // Make the AJAX call for topic-level provisioning.
        var request = {
            methodname: 'mod_skilland_provision_topic_scorm_ajax',
            args: {
                skillandid: skillandId,
                cmid: cmId
            }
        };

        Ajax.call([request])[0]
            .done(function(response) {
                if (response.success) {
                    // Success! Show success message and reload the page.
                    Str.get_string('provision_success', 'mod_skilland').done(function(successMsg) {
                        Notification.addNotification({
                            message: successMsg,
                            type: 'success'
                        });

                        // Reload the page after a short delay to show the lessons.
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    });
                } else {
                    // Error from the server.
                    showError(response.error || 'Unknown error occurred');
                    $button.prop('disabled', false).removeClass('d-none');
                    $loading.addClass('d-none');
                }
            })
            .fail(function(error) {
                // AJAX error.
                var errorMsg = error.message || error.error || 'Network error occurred';
                showError(errorMsg);
                $button.prop('disabled', false).removeClass('d-none');
                $loading.addClass('d-none');
            });
    };

    /**
     * Provision lesson-level SCORM content (legacy).
     *
     * @param {number} lessonId The lesson record ID
     * @param {number} cmId The course module ID
     */
    var provisionLessonContent = function(lessonId, cmId) {
        var $button = $('#skilland-provision-btn');
        var $loading = $('#skilland-provision-loading');
        var $error = $('#skilland-provision-error');

        // Disable button and show loading.
        $button.prop('disabled', true).addClass('d-none');
        $loading.removeClass('d-none');
        $error.addClass('d-none').text('');

        // Make the AJAX call for lesson-level provisioning.
        var request = {
            methodname: 'mod_skilland_provision_lesson_scorm_ajax',
            args: {
                lessonid: lessonId,
                cmid: cmId
            }
        };

        Ajax.call([request])[0]
            .done(function(response) {
                if (response.success) {
                    // Success! Show success message and reload the page.
                    Str.get_string('provision_success', 'mod_skilland').done(function(successMsg) {
                        Notification.addNotification({
                            message: successMsg,
                            type: 'success'
                        });

                        // Reload the page after a short delay to show the SCORM launch button.
                        setTimeout(function() {
                            window.location.reload();
                        }, 1000);
                    });
                } else {
                    // Error from the server.
                    showError(response.error || 'Unknown error occurred');
                    $button.prop('disabled', false).removeClass('d-none');
                    $loading.addClass('d-none');
                }
            })
            .fail(function(error) {
                // AJAX error.
                var errorMsg = error.message || error.error || 'Network error occurred';
                showError(errorMsg);
                $button.prop('disabled', false).removeClass('d-none');
                $loading.addClass('d-none');
            });
    };

    /**
     * Show an error message.
     *
     * @param {string} message The error message
     */
    var showError = function(message) {
        var $error = $('#skilland-provision-error');
        Str.get_string('provision_failed', 'mod_skilland', message).done(function(errorMsg) {
            $error.text(errorMsg).removeClass('d-none');
        }).fail(function() {
            // Fallback if string loading fails.
            $error.text('Failed to provision content: ' + message).removeClass('d-none');
        });
    };

    return {
        init: init
    };
});
