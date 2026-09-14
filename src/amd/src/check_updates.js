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
                    showUpdateBanner(skillandId, cmId, response.contenthash);
                }
            },
            fail: function() {
                // Silently ignore — this is a background check.
            }
        }]);
    };

    /**
     * Display the update available banner above the lesson list.
     *
     * @param {number} skillandId
     * @param {number} cmId
     * @param {string} newHash
     */
    var showUpdateBanner = function(skillandId, cmId, newHash) {
        Str.get_strings([
            {key: 'update_available', component: 'mod_skilland'},
            {key: 'update_available_desc', component: 'mod_skilland'},
            {key: 'update_from_skilland', component: 'mod_skilland'},
            {key: 'update_confirm_title', component: 'mod_skilland'},
            {key: 'update_confirm_message', component: 'mod_skilland'},
            {key: 'updating', component: 'mod_skilland'}
        ]).done(function(strings) {
            var title = strings[0];
            var desc = strings[1];
            var buttonText = strings[2];
            var confirmTitle = strings[3];
            var confirmMessage = strings[4];
            var updatingText = strings[5];

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

                Notification.confirm(confirmTitle, confirmMessage, buttonText, null, function() {
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
                                    message: response.error || 'Update failed',
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
        });
    };

    return {
        init: init
    };
});
