/**
 * Content update checker for Skilland activities with auto-update enabled.
 *
 * When loaded on the activity view page, queries the Skilland API via a
 * Moodle web service to compare the current content hash against the
 * stored snapshot. If content has changed, displays a banner with a
 * manual update button.
 *
 * @module     mod_skilland/check_updates
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['jquery', 'core/ajax', 'core/notification', 'core/str'], function($, Ajax, Notification, Str) {

    /**
     * Initialize the update checker.
     *
     * @param {Object} config Configuration object
     * @param {number} config.skillandid The skilland activity record ID
     * @param {number} config.cmid The course module ID
     * @param {string} config.topicid The Skilland topic ID
     * @param {string} config.snapshotid The current stored snapshot hash
     */
    var init = function(config) {
        var skillandId = parseInt(config.skillandid, 10);
        var cmId = parseInt(config.cmid, 10);
        var snapshotId = config.snapshotid || '';

        // Call the check_topic_snapshot web service.
        Ajax.call([{
            methodname: 'mod_skilland_check_topic_snapshot',
            args: {
                skillandid: skillandId
            },
            done: function(response) {
                if (response.isstale) {
                    showUpdateBanner(skillandId, cmId, response.contenthash, response.studentattemptcount || 0);
                }
            },
            fail: function() {
                // Silently ignore — this is a background check.
            }
        }]);
    };

    /**
     * Show a Notification.confirm dialog styled as a destructive action: the confirm button gets
     * btn-danger (or btn-outline-danger as a fallback while the modal renders) instead of the
     * default btn-primary (SKL-697).
     *
     * @param {string} title
     * @param {string} message
     * @param {string} actionLabel Clear action label, e.g. "Replace content and delete progress"
     * @param {string} cancelLabel
     * @param {Function} onConfirm
     */
    var showDangerConfirm = function(title, message, actionLabel, cancelLabel, onConfirm) {
        Notification.confirm(title, message, actionLabel, cancelLabel, onConfirm).then(function(modal) {
            if (modal && typeof modal.getRoot === 'function') {
                modal.getRoot().find('[data-action="save"]')
                    .removeClass('btn-primary btn-outline-danger')
                    .addClass('btn-danger');
            }
            return modal;
        }).catch(function() {
            // Notification.confirm already reports its own failures; nothing else to do here.
        });
    };

    /**
     * Display the update available banner above the lesson list.
     *
     * @param {number} skillandId
     * @param {number} cmId
     * @param {string} newHash
     * @param {number} studentCount Students with progress on this topic that an update would delete.
     */
    var showUpdateBanner = function(skillandId, cmId, newHash, studentCount) {
        Str.get_strings([
            {key: 'update_available', component: 'mod_skilland'},
            {key: 'update_available_desc', component: 'mod_skilland'},
            {key: 'update_from_skilland', component: 'mod_skilland'},
            {key: 'update_confirm_title', component: 'mod_skilland'},
            {key: 'update_confirm_message', component: 'mod_skilland'},
            {key: 'update_confirm_message_students', component: 'mod_skilland', param: studentCount},
            {key: 'updating', component: 'mod_skilland'},
            {key: 'destructive_confirm_action', component: 'mod_skilland'},
            {key: 'lockafterfirstaccess', component: 'mod_skilland'},
            {key: 'update_error', component: 'mod_skilland'}
        ]).done(function(strings) {
            var title = strings[0];
            var desc = strings[1];
            var buttonText = strings[2];
            var confirmTitle = strings[3];
            var updatingText = strings[6];
            var destructiveActionLabel = strings[7];
            var lockLabel = strings[8];
            var updateErrorText = strings[9];

            var hasStudents = studentCount > 0;
            var confirmMessage = hasStudents ? strings[5] : strings[4];
            var confirmActionLabel = hasStudents ? destructiveActionLabel : buttonText;

            Str.get_string('lockafterfirstaccess_hint', 'mod_skilland', lockLabel).done(function(lockHint) {
                confirmMessage = confirmMessage + ' ' + lockHint;
                renderBanner(confirmMessage);
            }).fail(function() {
                renderBanner(confirmMessage);
            });

            function renderBanner(message) {
                var banner = $('<div class="alert alert-info skilland-update-banner" role="alert">' +
                    '<div class="d-flex align-items-center justify-content-between">' +
                    '<div>' +
                    '<strong>' + title + '</strong><br>' +
                    '<small>' + desc + '</small>' +
                    '</div>' +
                    '<button class="btn btn-primary btn-sm skilland-update-btn">' + buttonText + '</button>' +
                    '</div>' +
                    '</div>');

                // Insert before the lesson list.
                var container = $('.skilland-lessons-container');
                if (container.length) {
                    container.before(banner);
                } else {
                    // Fallback: insert at top of content.
                    $('#region-main .activity-header, #region-main-box').first().after(banner);
                }

                // Bind update button.
                banner.find('.skilland-update-btn').on('click', function(e) {
                    e.preventDefault();

                    showDangerConfirm(confirmTitle, message, confirmActionLabel, null, function() {
                        var btn = banner.find('.skilland-update-btn');
                        btn.prop('disabled', true).text(updatingText);

                        Ajax.call([{
                            methodname: 'mod_skilland_update_topic_scorm_ajax',
                            args: {
                                skillandid: skillandId,
                                cmid: cmId
                            },
                            done: function(response) {
                                if (response.success) {
                                    window.location.reload();
                                } else {
                                    Notification.addNotification({
                                        message: response.error || updateErrorText,
                                        type: 'error'
                                    });
                                    btn.prop('disabled', false).text(buttonText);
                                }
                            },
                            fail: function(error) {
                                Notification.exception(error);
                                btn.prop('disabled', false).text(buttonText);
                            }
                        }]);
                    });
                });
            }
        });
    };

    return {
        init: init
    };
});
