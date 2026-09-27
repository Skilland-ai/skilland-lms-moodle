<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Skilland activity view page - displays lessons for a topic.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/locallib.php');

use mod_skilland\logger;

$id = required_param('id', PARAM_INT); // Course module id.
$play = optional_param('play', 0, PARAM_INT); // Lesson id to play directly in SCORM player.

$cm = get_coursemodule_from_id('skilland', $id, 0, false, MUST_EXIST);
$course = get_course($cm->course);
$skilland = $DB->get_record('skilland', ['id' => $cm->instance], '*', MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/skilland:view', $context);
skilland_view($skilland, $course, $cm, $context);

// A deleted linked SCORM makes the activity unprovisioned for this request (never written here).
$scormmissing = skilland_detect_missing_scorm($skilland);

// Get topic order index for lesson labeling (L1.1, L1.2, etc.).
$topicorderindex = isset($skilland->topic_orderindex) ? $skilland->topic_orderindex : 1;

// Set up page. Activity name already contains "T1 - " prefix from form.
$PAGE->set_url('/mod/skilland/view.php', ['id' => $cm->id]);
$pagetitle = format_string($skilland->name);
if (!empty($skilland->hidelabels)) {
    $pagetitle = preg_replace('/^T\d+\s*-\s*/', '', $pagetitle);
}
$PAGE->set_title($pagetitle);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add CSS for lesson cards and player.
$PAGE->requires->css(new moodle_url('/mod/skilland/styles/view.css'));

// Fetch visible lessons for this activity.
$lessons = $DB->get_records('skilland_lesson', [
    'skillandid' => $skilland->id,
    'visible' => 1
], 'orderindex ASC');

// If playing a lesson, show the SCORM player iframe.
if ($play > 0) {
    // Only a visible lesson of this activity can be played; hidden, foreign or unknown ids go back to the list.
    $lesson = skilland_find_visible_lesson($lessons, $play);
    if ($lesson) {
        echo $OUTPUT->header();
        echo skilland_render_player_view($skilland, $lesson, $cm, $lessons, $topicorderindex);
        echo $OUTPUT->footer();
        exit;
    }
    redirect(new moodle_url('/mod/skilland/view.php', ['id' => $cm->id]),
        get_string('lesson_not_available', 'mod_skilland'), null, \core\output\notification::NOTIFY_WARNING);
}

// Default: Show lesson list.
echo $OUTPUT->header();

// Activity intro/description.
if (!empty($skilland->intro)) {
    echo $OUTPUT->box(format_module_intro('skilland', $skilland, $cm->id), 'generalbox', 'intro');
}

// Check if SCORM is provisioned for this topic.
if (empty($skilland->scormcmid)) {
    // SCORM not yet provisioned - show provision button for teachers.
    $canprovision = has_capability('mod/skilland:provision', $context);
    if ($canprovision) {
        if ($scormmissing) {
            echo $OUTPUT->notification(get_string('scorm_missing_reprovision', 'mod_skilland'), 'warning');
        }
        echo skilland_render_provision_view($skilland, $cm);
    } else {
        echo html_writer::div(
            get_string('content_being_prepared', 'mod_skilland'),
            'alert alert-info'
        );
    }
} else {
    // If autoupdate is enabled and user can provision content, load the update checker.
    $canmanage = has_capability('mod/skilland:provision', $context);
    if (!empty($skilland->autoupdate) && $canmanage && !empty($skilland->skilland_topicid)) {
        $PAGE->requires->js_call_amd('mod_skilland/check_updates', 'init', [[
            'skillandid' => (int)$skilland->id,
            'cmid' => (int)$cm->id,
            'topicid' => $skilland->skilland_topicid,
            'snapshotid' => $skilland->snapshotid ?? '',
        ]]);
    }

    // Tell everyone (students included) when this content was last (re)built: an auto-update or a
    // manual "update now" deletes and recreates the SCORM package, resetting every attempt.
    if (!empty($skilland->snapshotcreatedat)) {
        echo html_writer::div(
            get_string('content_last_updated', 'mod_skilland',
                userdate($skilland->snapshotcreatedat, get_string('strftimedatetimeshort'))),
            'alert alert-info skilland-snapshot-notice'
        );
    }

    // Show lesson list with progress.
    echo skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex);
}

echo $OUTPUT->footer();

/**
 * Find a playable lesson among the visible lessons of this activity.
 *
 * @param array $lessons Visible lessons of this activity, keyed by lesson id.
 * @param int $lessonid The requested lesson id.
 * @return stdClass|null The lesson, or null when it is hidden, belongs to another activity or does not exist.
 */
function skilland_find_visible_lesson(array $lessons, int $lessonid): ?stdClass {
    return $lessons[$lessonid] ?? null;
}

/**
 * Detect a linked SCORM that was deleted (or is being deleted) and treat the activity as
 * unprovisioned for this request by clearing scormcmid in memory only.
 *
 * @param stdClass $skilland The skilland activity record; scormcmid is nulled when missing.
 * @return bool True when scormcmid was set but the SCORM module is gone.
 */
function skilland_detect_missing_scorm(stdClass $skilland): bool {
    if (empty($skilland->scormcmid)) {
        return false;
    }
    if (skilland_get_linked_scorm_cm($skilland)) {
        return false;
    }
    $skilland->scormcmid = null;
    return true;
}

/**
 * The mod_skilland renderer of this page.
 *
 * @return \mod_skilland\output\renderer
 */
function skilland_view_renderer(): \mod_skilland\output\renderer {
    global $PAGE;
    return $PAGE->get_renderer('mod_skilland');
}

/**
 * Render the lesson list view with progress indicators.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param array $lessons Array of lesson records.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @return string HTML output.
 */
function skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex = 1) {
    global $USER;

    $progress = [];
    $canprovision = false;
    if (!empty($lessons)) {
        // Merge the learner's SCORM tracks into the progress store, then read it (SKL-668).
        if (skilland_refresh_progress($skilland, (int) $USER->id)) {
            skilland_recompute_user($skilland, (int) $USER->id);
        }
        $progress = skilland_get_user_progress((int) $skilland->id, (int) $USER->id);

        logger::debug('Progress', 'Progress for user ' . $USER->id . ', skilland ' . $skilland->id . ': ' . json_encode($progress));

        // Teachers see which visible lessons are missing from the installed package (SKL-655).
        $canprovision = !empty($skilland->scormcmid) &&
            has_capability('mod/skilland:provision', context_module::instance($cm->id));
    }

    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_list(
        $skilland, $lessons, $cm, (int) $topicorderindex, $progress, $canprovision));
}

/**
 * Render the SCORM player view with embedded iframe in fullscreen mode.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $lesson The lesson record to play.
 * @param stdClass $cm The course module record.
 * @param array $alllessons All lessons for navigation.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @return string HTML output.
 */
function skilland_render_player_view($skilland, $lesson, $cm, $alllessons, $topicorderindex = 1) {
    global $USER, $DB, $PAGE, $CFG;

    $renderer = skilland_view_renderer();

    // Check the lesson is visible here, has a valid SCO mapping and the SCORM module still exists.
    $found = \mod_skilland\output\lesson_navigation::position_of($lesson, $alllessons) !== null;
    $scormcm = null;
    if ($found && !empty($lesson->scoid) && !empty($skilland->scormcmid)) {
        $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
    }
    if (!$scormcm) {
        return $renderer->render(new \mod_skilland\output\player($skilland, $lesson, $cm, $alllessons,
            (int) $topicorderindex, null));
    }

    // Build the SCORM player URL.
    $scorm = $DB->get_record('scorm', ['id' => $scormcm->instance], '*', MUST_EXIST);

    // Get or create a SCORM attempt for this user.
    require_once($CFG->dirroot . '/mod/scorm/locallib.php');
    $attempt = scorm_get_last_attempt($scorm->id, $USER->id);
    if (empty($attempt)) {
        $attempt = 1;
    }

    // Build the player URL with the specific SCO.
    $scormplayerurl = new moodle_url('/mod/scorm/player.php', [
        'scoid' => $lesson->scoid,
        'cm' => $skilland->scormcmid,
        'mode' => 'normal',
        'newattempt' => 'off',
        'display' => 'popup'
    ]);

    $player = new \mod_skilland\output\player($skilland, $lesson, $cm, $alllessons, (int) $topicorderindex,
        $scormplayerurl);
    $html = $renderer->render($player);

    // Load the fullscreen JavaScript module.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/fullscreen_player', 'init', [[
        'debug' => (bool)$devmode,
        'backurl' => $player->get_back_url()->out(false)
    ]]);

    return $html;
}

/**
 * Render prev/next navigation for the player view.
 *
 * @param stdClass $currentlesson The current lesson record.
 * @param array $alllessons All lessons for this activity.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @param stdClass $skilland The skilland activity record.
 * @return string HTML output.
 */
function skilland_render_player_navigation($currentlesson, $alllessons, $cm, $topicorderindex, $skilland) {
    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_navigation($currentlesson, $alllessons,
        $cm, (int) $topicorderindex, $skilland, \mod_skilland\output\lesson_navigation::STYLE_PLAYER));
}

/**
 * Render the bottom navigation bar for fullscreen player view.
 *
 * @param stdClass $currentlesson The current lesson record.
 * @param array $alllessons All lessons for this activity.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @param stdClass $skilland The skilland activity record.
 * @return string HTML output.
 */
function skilland_render_fullscreen_navigation($currentlesson, $alllessons, $cm, $topicorderindex, $skilland) {
    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_navigation($currentlesson, $alllessons,
        $cm, (int) $topicorderindex, $skilland, \mod_skilland\output\lesson_navigation::STYLE_FULLSCREEN));
}

/**
 * Render the provision view for teachers when SCORM is not yet created.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $cm The course module record.
 * @return string HTML output.
 */
function skilland_render_provision_view($skilland, $cm) {
    global $PAGE;

    $html = skilland_view_renderer()->render(new \mod_skilland\output\provision($skilland, $cm));

    // Load the JavaScript module for provisioning.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/provision_scorm', 'init', [[
        'skillandid' => (int)$skilland->id,
        'cmid' => (int)$cm->id,
        'debug' => (bool)$devmode
    ]]);

    return $html;
}
