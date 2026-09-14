/**
 * Fullscreen player module for Skilland lessons.
 *
 * Ensures fullscreen mode is always active when viewing lessons.
 * The only way to exit is via the "Back to lessons" link.
 *
 * @module     mod_skilland/fullscreen_player
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([], function() {
    'use strict';

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
     * Initialize the fullscreen player.
     * Ensures the wrapper stays in fullscreen mode.
     *
     * @param {Object} [config] Configuration object
     * @param {boolean} [config.debug] Whether to enable debug logging
     */
    function init(config) {
        config = config || {};
        debug = config.debug || false;

        var wrapper = document.getElementById('skilland-fullscreen-wrapper');

        if (!wrapper) {
            log('Skilland fullscreen_player: wrapper element not found');
            return;
        }

        log('Skilland fullscreen_player: initializing');

        // Ensure fullscreen mode is always active.
        wrapper.setAttribute('data-fullscreen', 'true');

        // Prevent Escape key from interfering (let SCORM handle it internally).
        // User must use "Back to lessons" link to exit.
    }

    return {
        init: init
    };
});
