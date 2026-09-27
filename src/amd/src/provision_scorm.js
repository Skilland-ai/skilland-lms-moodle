/**
 * SCORM provisioning module for Skilland.
 *
 * Handles the provision button click and AJAX call to create SCORM activities.
 * Provisions one topic-level SCORM package per activity.
 *
 * @module     mod_skilland/provision_scorm
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {

    /** @var {boolean} debug Whether debug logging is enabled */
    var debug = false;

    /** @var {number} PROVISION_TIMEOUT_MS Client-side "this is taking a while" warning (SKL-697). */
    var PROVISION_TIMEOUT_MS = 150000;

    /** @var {number|null} elapsedTimer Interval id of the elapsed-time ticker */
    var elapsedTimer = null;

    /** @var {number|null} warnTimer Timeout id of the client-side "still running" warning */
    var warnTimer = null;

    /** @var {number|null} provisionStartTime Timestamp the current provisioning call started */
    var provisionStartTime = null;

    /** @var {string|null} elapsedTemplate The "Preparing content… {$a}" string, fetched once */
    var elapsedTemplate = null;

    /** @var {string} noValidIdText The 'error_no_valid_id_provisioning' string, fetched once at init */
    var noValidIdText = 'No valid ID provided for provisioning';

    /** @var {string} networkErrorText The 'error_network' string, fetched once at init */
    var networkErrorText = 'Network error occurred';

    /** @var {string} unknownErrorText The 'error_unknown' string, fetched once at init */
    var unknownErrorText = 'Unknown error occurred';

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
     * @param {number} config.skillandid The skilland activity ID
     * @param {number} config.cmid The course module ID
     * @param {boolean} [config.debug] Whether to enable debug logging
     */
    var init = function(config) {
        debug = config.debug || false;
        log('Skilland provision_scorm init called with config:', config);

        var skillandId = config.skillandid ? parseInt(config.skillandid, 10) : 0;
        var cmId = parseInt(config.cmid, 10);

        log('Skilland provision_scorm: skillandId=' + skillandId + ', cmId=' + cmId);

        Str.get_strings([
            {key: 'error_no_valid_id_provisioning', component: 'mod_skilland'},
            {key: 'error_network', component: 'mod_skilland'},
            {key: 'error_unknown', component: 'mod_skilland'}
        ]).done(function(strings) {
            noValidIdText = strings[0];
            networkErrorText = strings[1];
            unknownErrorText = strings[2];
        });

        // Bind click handler to provision button.
        $('#skilland-provision-btn').on('click', function(e) {
            e.preventDefault();
            log('Skilland provision_scorm: Provision button clicked');

            if (skillandId > 0) {
                provisionTopicContent(skillandId, cmId);
            } else {
                showError(noValidIdText);
            }
        });
    };

    /**
     * Format a duration in milliseconds as "m:ss".
     *
     * @param {number} ms Elapsed milliseconds
     * @return {string}
     */
    var formatElapsed = function(ms) {
        var totalSeconds = Math.max(0, Math.floor(ms / 1000));
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        return minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
    };

    /**
     * Update the elapsed-time text from the already-fetched template.
     */
    var updateElapsedDisplay = function() {
        if (!elapsedTemplate || provisionStartTime === null) {
            return;
        }
        var text = elapsedTemplate.replace('{$a}', formatElapsed(Date.now() - provisionStartTime));
        $('#skilland-provision-elapsed').text(text);
    };

    /**
     * Start the elapsed-time ticker and the "still running" client-side warning.
     */
    var startProvisionTimers = function() {
        provisionStartTime = Date.now();

        Str.get_string('provisioning_elapsed', 'mod_skilland').done(function(template) {
            elapsedTemplate = template;
            updateElapsedDisplay();
        }).fail(function() {
            // No template, no elapsed display — the rest of the flow still works.
            elapsedTemplate = null;
        });

        elapsedTimer = setInterval(updateElapsedDisplay, 1000);

        warnTimer = setTimeout(function() {
            Str.get_string('provisioning_timeout_message', 'mod_skilland').done(function(message) {
                // The server-side job keeps running; this only tells the teacher not to retry yet.
                $('#skilland-provision-elapsed').text(message);
            });
        }, PROVISION_TIMEOUT_MS);
    };

    /**
     * Stop both provisioning timers. Never cancels the underlying AJAX/server-side work.
     */
    var clearProvisionTimers = function() {
        if (elapsedTimer !== null) {
            clearInterval(elapsedTimer);
            elapsedTimer = null;
        }
        if (warnTimer !== null) {
            clearTimeout(warnTimer);
            warnTimer = null;
        }
        provisionStartTime = null;
        elapsedTemplate = null;
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
        startProvisionTimers();

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
                clearProvisionTimers();
                if (response.success) {
                    // Show the success notification when the string loads, but the reload must
                    // happen regardless of that — a failed string fetch must never strand the
                    // teacher on the loading screen (SKL-697).
                    Str.get_string('provision_success', 'mod_skilland').done(function(successMsg) {
                        Notification.addNotification({
                            message: successMsg,
                            type: 'success'
                        });
                    });

                    // Reload the page after a short delay to show the lessons.
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    // Error from the server.
                    showError(response.error || unknownErrorText);
                    $button.prop('disabled', false).removeClass('d-none');
                    $loading.addClass('d-none');
                }
            })
            .fail(function(error) {
                clearProvisionTimers();
                // AJAX error.
                var errorMsg = error.message || error.error || networkErrorText;
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
