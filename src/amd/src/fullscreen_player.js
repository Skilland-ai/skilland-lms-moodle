/**
 * Fullscreen player module for Skilland lessons.
 *
 * Ensures fullscreen mode is always active when viewing lessons, traps the
 * page behind the overlay from assistive technology and the Tab key, and
 * moves focus into the overlay when it opens.
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
     * Hide every element outside the given element's ancestor chain from
     * assistive technology and keyboard/Tab navigation, so the page behind a
     * fullscreen overlay can never be reached while it is open.
     *
     * @param {HTMLElement} target The element that must stay reachable (the overlay).
     */
    var hideBackground = function(target) {
        var node = target;
        while (node && node !== document.body) {
            var parent = node.parentNode;
            if (parent && parent.children) {
                Array.prototype.forEach.call(parent.children, function(sibling) {
                    if (sibling === node) {
                        return;
                    }
                    sibling.setAttribute('aria-hidden', 'true');
                    if ('inert' in sibling) {
                        sibling.inert = true;
                    }
                });
            }
            node = parent;
        }
    };

    /**
     * Initialize the fullscreen player.
     * Ensures the wrapper stays in fullscreen mode.
     *
     * @param {Object} [config] Configuration object
     * @param {boolean} [config.debug] Whether to enable debug logging
     * @param {string} [config.backurl] URL to navigate to on "exit" (Escape outside the iframe)
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

        // Keep the page behind the overlay out of reach of assistive technology
        // and the Tab key, and stop it from scrolling underneath the overlay.
        hideBackground(wrapper);
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';

        // Move focus to the lesson title so screen reader and keyboard users
        // land inside the overlay as soon as it opens.
        var title = document.getElementById('skilland-fullscreen-title');
        if (title) {
            title.focus();
        }

        var iframe = wrapper.querySelector('.skilland-fullscreen-iframe');
        var backUrl = config.backurl;

        document.addEventListener('keydown', function(event) {
            if (event.key !== 'Escape' && event.keyCode !== 27) {
                return;
            }

            // Prevent Escape key from interfering when focus is inside the
            // iframe (let SCORM handle it internally); the learner must use
            // "Back to lessons" to exit from there.
            if (iframe && document.activeElement === iframe) {
                return;
            }

            // Focus is on the header/nav chrome, not the SCORM content: treat
            // Escape as "Back to lessons" so keyboard users are never stuck.
            if (backUrl) {
                event.preventDefault();
                window.location.href = backUrl;
            }
        });
    }

    return {
        init: init
    };
});
