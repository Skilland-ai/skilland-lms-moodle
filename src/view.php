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
 * @copyright  2024 Skilland <https://skilland.ai>
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
    $pagetitle = skilland_strip_topic_label($pagetitle);
}
$PAGE->set_title($pagetitle);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Add CSS for lesson cards and player.
$PAGE->requires->css(new moodle_url('/mod/skilland/styles/view.css'));

// Fetch visible lessons for this activity.
$lessons = $DB->get_records('skilland_lesson', [
    'skillandid' => $skilland->id,
    'visible' => 1,
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
    redirect(
        new moodle_url('/mod/skilland/view.php', ['id' => $cm->id]),
        get_string('lesson_not_available', 'mod_skilland'),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
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
            get_string(
                'content_last_updated',
                'mod_skilland',
                userdate($skilland->snapshotcreatedat, get_string('strftimedatetimeshort'))
            ),
            'alert alert-info skilland-snapshot-notice'
        );
    }

    // Show lesson list with progress.
    echo skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex);
}

echo $OUTPUT->footer();
