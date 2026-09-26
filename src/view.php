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
    $lesson = $DB->get_record('skilland_lesson', ['id' => $play, 'skillandid' => $skilland->id]);
    if ($lesson) {
        echo $OUTPUT->header();
        echo skilland_render_player_view($skilland, $lesson, $cm, $lessons, $topicorderindex);
        echo $OUTPUT->footer();
        exit;
    } else {
        // Lesson not found, fall through to lesson list.
        redirect(new moodle_url('/mod/skilland/view.php', ['id' => $cm->id]),
            get_string('lesson_not_found', 'mod_skilland'), null, \core\output\notification::NOTIFY_ERROR);
    }
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

    // Show lesson list with progress.
    echo skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex);
}

echo $OUTPUT->footer();

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
 * Render the lesson list view with progress indicators.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param array $lessons Array of lesson records.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @return string HTML output.
 */
function skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex = 1) {
    global $OUTPUT, $USER;

    $html = '';

    if (empty($lessons)) {
        $html .= html_writer::div(
            get_string('no_lessons_configured', 'mod_skilland'),
            'alert alert-info'
        );
        return $html;
    }

    // Get progress for all lessons.
    $progress = skilland_get_lessons_progress($USER->id, $skilland->id);

    logger::debug('Progress', 'Progress for user ' . $USER->id . ', skilland ' . $skilland->id . ': ' . json_encode($progress));

    $html .= html_writer::start_div('skilland-lessons-container');
    $html .= html_writer::tag('h3', get_string('lessons', 'mod_skilland'), ['class' => 'skilland-lessons-header']);

    $html .= html_writer::start_div('skilland-lessons-list');

    // Teachers see which visible lessons are missing from the installed package (SKL-655).
    $canprovision = !empty($skilland->scormcmid) &&
        has_capability('mod/skilland:provision', context_module::instance($cm->id));

    $lessonindex = 1;
    foreach ($lessons as $lesson) {
        // Determine if lesson can be played (has SCO mapped).
        $canplay = !empty($lesson->scoid) && !empty($skilland->scormcmid);

        if ($canplay) {
            // Direct link to SCORM player.
            $lessonurl = new moodle_url('/mod/skilland/view.php', [
                'id' => $cm->id,
                'play' => $lesson->id
            ]);
        } else {
            // No link if not playable.
            $lessonurl = null;
        }

        // Get progress status for this lesson.
        $lessonprogress = isset($progress[$lesson->id]) ? $progress[$lesson->id] : null;
        $status = $lessonprogress['status'] ?? 'not_started';
        $score = $lessonprogress['score'] ?? null;

        // Determine styling based on progress status.
        switch ($status) {
            case 'completed':
            case 'passed':
                $completionclass = 'skilland-lesson-completed';
                $completionicon = 'fa-check-circle';
                $statustext = get_string('completed', 'mod_skilland');
                break;
            case 'incomplete':
            case 'browsed':
                $completionclass = 'skilland-lesson-in-progress';
                $completionicon = 'fa-clock-o';
                $statustext = get_string('in_progress', 'mod_skilland');
                break;
            case 'failed':
                $completionclass = 'skilland-lesson-failed';
                $completionicon = 'fa-times-circle';
                $statustext = get_string('failed', 'mod_skilland');
                break;
            default:
                if ($canplay) {
                    $completionclass = 'skilland-lesson-available';
                    $completionicon = 'fa-play-circle';
                    $statustext = get_string('ready_to_start', 'mod_skilland');
                } else {
                    $completionclass = 'skilland-lesson-pending';
                    $completionicon = 'fa-circle-o';
                    $statustext = get_string('not_started', 'mod_skilland');
                }
                break;
        }

        // Card element - link if playable, div otherwise.
        if ($lessonurl) {
            $html .= html_writer::start_tag('a', [
                'href' => $lessonurl->out(false),
                'class' => 'skilland-lesson-card ' . $completionclass
            ]);
        } else {
            $html .= html_writer::start_div('skilland-lesson-card skilland-lesson-disabled ' . $completionclass);
        }

        // Lesson number badge (L1.1, L1.2, etc.).
        $lessonlabel = 'L' . $topicorderindex . '.' . $lessonindex;
        if (empty($skilland->hidelabels)) {
            $html .= html_writer::div($lessonlabel, 'skilland-lesson-number');
        }

        // Lesson content.
        $html .= html_writer::start_div('skilland-lesson-content');
        $html .= html_writer::tag('span', format_string($lesson->title), ['class' => 'skilland-lesson-title']);

        // Meta info.
        $meta = [];
        if (!empty($lesson->updatedat)) {
            $meta[] = get_string('updated', 'mod_skilland') . ': ' . userdate($lesson->updatedat, get_string('strftimedateshort'));
        }
        // Show score if available.
        if ($score !== null && $score !== '') {
            $meta[] = get_string('score', 'mod_skilland') . ': ' . $score . '%';
        }
        if (!empty($meta)) {
            $html .= html_writer::div(implode(' · ', $meta), 'skilland-lesson-meta');
        }
        if ($canprovision && empty($lesson->scoid)) {
            $html .= html_writer::div(get_string('lesson_sco_missing', 'mod_skilland'),
                'alert alert-warning skilland-lesson-sco-missing small py-1 px-2 mt-1 mb-0');
        }
        $html .= html_writer::end_div(); // lesson-content.

        // Status indicator.
        $html .= html_writer::div(
            html_writer::tag('i', '', ['class' => 'fa ' . $completionicon . ' skilland-status-icon']) .
            html_writer::tag('span', $statustext, ['class' => 'skilland-status-text']),
            'skilland-lesson-status'
        );

        if ($lessonurl) {
            $html .= html_writer::end_tag('a');
        } else {
            $html .= html_writer::end_div();
        }
        $lessonindex++;
    }

    $html .= html_writer::end_div(); // lessons-list.
    $html .= html_writer::end_div(); // lessons-container.

    return $html;
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
    global $OUTPUT, $USER, $DB, $PAGE;

    $html = '';

    // Calculate lesson index within the topic.
    $lessonindex = 1;
    foreach ($alllessons as $l) {
        if ($l->id == $lesson->id) {
            break;
        }
        $lessonindex++;
    }
    $lessonlabel = 'L' . $topicorderindex . '.' . $lessonindex;

    // Check if lesson has a valid SCO mapping and the SCORM module still exists.
    $scormcm = null;
    if (!empty($lesson->scoid) && !empty($skilland->scormcmid)) {
        $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
    }
    if (!$scormcm) {
        $html .= html_writer::div(
            get_string('scorm_not_ready', 'mod_skilland'),
            'alert alert-warning'
        );
        $backurl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id]);
        $html .= html_writer::div(
            html_writer::link($backurl, get_string('back_to_lessons', 'mod_skilland'), ['class' => 'btn btn-secondary']),
            'mt-3'
        );
        return $html;
    }

    // Build the SCORM player URL.
    global $CFG;
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

    // Prepare lesson title.
    $backurl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id]);
    $lessontitle = empty($skilland->hidelabels)
        ? $lessonlabel . ' - ' . format_string($lesson->title)
        : format_string($lesson->title);

    // Start fullscreen wrapper (auto-enabled on load).
    $html .= html_writer::start_div('skilland-fullscreen-wrapper', [
        'id' => 'skilland-fullscreen-wrapper',
        'data-fullscreen' => 'true'
    ]);

    // Fixed header bar with back link, title, and toggle button.
    $html .= html_writer::start_div('skilland-fullscreen-header');

    // Left: Back to lessons link.
    $html .= html_writer::link($backurl, '← ' . get_string('back_to_lessons', 'mod_skilland'), [
        'class' => 'skilland-fullscreen-back'
    ]);

    // Center: Lesson title (absolutely positioned for true centering).
    $html .= html_writer::tag('span', $lessontitle, ['class' => 'skilland-fullscreen-title']);

    // Right: Close button (X) - same action as back to lessons.
    $html .= html_writer::link($backurl, '×', [
        'class' => 'skilland-fullscreen-close',
        'title' => get_string('back_to_lessons', 'mod_skilland'),
        'aria-label' => get_string('back_to_lessons', 'mod_skilland')
    ]);

    $html .= html_writer::end_div(); // End header.

    // Iframe container.
    $html .= html_writer::start_div('skilland-fullscreen-content');
    $html .= html_writer::tag('iframe', '', [
        'src' => $scormplayerurl->out(false),
        'class' => 'skilland-fullscreen-iframe',
        'allowfullscreen' => 'true',
        'allow' => 'fullscreen',
        'title' => format_string($lesson->title)
    ]);
    $html .= html_writer::end_div(); // End content.

    // Bottom navigation bar (prev/next lesson).
    $html .= skilland_render_fullscreen_navigation($lesson, $alllessons, $cm, $topicorderindex, $skilland);

    $html .= html_writer::end_div(); // End fullscreen wrapper.

    // Load the fullscreen JavaScript module.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/fullscreen_player', 'init', [[
        'debug' => (bool)$devmode
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
    $html = '';
    $lessonsarray = array_values($alllessons);
    $currentindex = null;

    foreach ($lessonsarray as $i => $l) {
        if ($l->id == $currentlesson->id) {
            $currentindex = $i;
            break;
        }
    }

    if ($currentindex === null) {
        return '';
    }

    $html .= html_writer::start_div('skilland-player-nav');

    // Previous lesson link.
    if ($currentindex > 0) {
        $prev = $lessonsarray[$currentindex - 1];
        // Only link if the previous lesson has a SCO mapped.
        if (!empty($prev->scoid) && !empty($skilland->scormcmid)) {
            $prevurl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id, 'play' => $prev->id]);
            $prevlabel = 'L' . $topicorderindex . '.' . $currentindex;
            $prevtext = empty($skilland->hidelabels)
                ? '← ' . $prevlabel . ' - ' . format_string($prev->title)
                : '← ' . format_string($prev->title);
            $html .= html_writer::link($prevurl, $prevtext, [
                'class' => 'skilland-nav-prev'
            ]);
        } else {
            $html .= html_writer::span('', 'skilland-nav-prev');
        }
    } else {
        $html .= html_writer::span('', 'skilland-nav-prev');
    }

    // Next lesson link.
    if ($currentindex < count($lessonsarray) - 1) {
        $next = $lessonsarray[$currentindex + 1];
        // Only link if the next lesson has a SCO mapped.
        if (!empty($next->scoid) && !empty($skilland->scormcmid)) {
            $nexturl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id, 'play' => $next->id]);
            $nextlabel = 'L' . $topicorderindex . '.' . ($currentindex + 2);
            $nexttext = empty($skilland->hidelabels)
                ? $nextlabel . ' - ' . format_string($next->title) . ' →'
                : format_string($next->title) . ' →';
            $html .= html_writer::link($nexturl, $nexttext, [
                'class' => 'skilland-nav-next'
            ]);
        } else {
            $html .= html_writer::span('', 'skilland-nav-next');
        }
    } else {
        $html .= html_writer::span('', 'skilland-nav-next');
    }

    $html .= html_writer::end_div();

    return $html;
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
    $html = '';
    $lessonsarray = array_values($alllessons);
    $currentindex = null;

    foreach ($lessonsarray as $i => $l) {
        if ($l->id == $currentlesson->id) {
            $currentindex = $i;
            break;
        }
    }

    if ($currentindex === null) {
        return '';
    }

    $html .= html_writer::start_div('skilland-fullscreen-nav');

    // Previous lesson link.
    if ($currentindex > 0) {
        $prev = $lessonsarray[$currentindex - 1];
        if (!empty($prev->scoid) && !empty($skilland->scormcmid)) {
            $prevurl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id, 'play' => $prev->id]);
            $prevlabel = 'L' . $topicorderindex . '.' . $currentindex;
            $prevtext = empty($skilland->hidelabels)
                ? $prevlabel . ' - ' . format_string($prev->title)
                : format_string($prev->title);
            $html .= html_writer::link($prevurl,
                html_writer::tag('span', '←', ['class' => 'skilland-fullscreen-nav-arrow']) .
                html_writer::tag('span', $prevtext, ['class' => 'skilland-fullscreen-nav-text']),
                ['class' => 'skilland-fullscreen-nav-prev']
            );
        } else {
            $html .= html_writer::span('', 'skilland-fullscreen-nav-prev skilland-fullscreen-nav-disabled');
        }
    } else {
        $html .= html_writer::span('', 'skilland-fullscreen-nav-prev skilland-fullscreen-nav-disabled');
    }

    // Next lesson link.
    if ($currentindex < count($lessonsarray) - 1) {
        $next = $lessonsarray[$currentindex + 1];
        if (!empty($next->scoid) && !empty($skilland->scormcmid)) {
            $nexturl = new moodle_url('/mod/skilland/view.php', ['id' => $cm->id, 'play' => $next->id]);
            $nextlabel = 'L' . $topicorderindex . '.' . ($currentindex + 2);
            $nexttext = empty($skilland->hidelabels)
                ? $nextlabel . ' - ' . format_string($next->title)
                : format_string($next->title);
            $html .= html_writer::link($nexturl,
                html_writer::tag('span', $nexttext, ['class' => 'skilland-fullscreen-nav-text']) .
                html_writer::tag('span', '→', ['class' => 'skilland-fullscreen-nav-arrow']),
                ['class' => 'skilland-fullscreen-nav-next']
            );
        } else {
            $html .= html_writer::span('', 'skilland-fullscreen-nav-next skilland-fullscreen-nav-disabled');
        }
    } else {
        $html .= html_writer::span('', 'skilland-fullscreen-nav-next skilland-fullscreen-nav-disabled');
    }

    $html .= html_writer::end_div();

    return $html;
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

    $html = '';

    $html .= html_writer::start_div('skilland-provision-container text-center py-5', [
        'id' => 'skilland-provision-container',
        'data-skillandid' => $skilland->id,
        'data-cmid' => $cm->id
    ]);

    $html .= html_writer::tag('div', '📦', ['class' => 'skilland-placeholder-icon']);
    $html .= html_writer::tag('h4', get_string('content_not_provisioned', 'mod_skilland'));
    $html .= html_writer::tag('p', get_string('provision_topic_desc', 'mod_skilland'), ['class' => 'text-muted mb-3']);

    // Provision button.
    $html .= html_writer::tag('button', get_string('provision_topic', 'mod_skilland'), [
        'class' => 'btn btn-primary btn-lg skilland-provision-btn',
        'id' => 'skilland-provision-btn',
        'data-skillandid' => $skilland->id,
        'data-cmid' => $cm->id
    ]);

    // Loading spinner (hidden by default).
    $html .= html_writer::div(
        html_writer::tag('i', '', ['class' => 'fa fa-spinner fa-spin fa-2x']) .
        html_writer::tag('p', get_string('provisioning', 'mod_skilland'), ['class' => 'mt-2']),
        'skilland-provision-loading d-none',
        ['id' => 'skilland-provision-loading']
    );

    // Error message area (hidden by default).
    $html .= html_writer::div('', 'alert alert-danger d-none mt-3', ['id' => 'skilland-provision-error']);

    $html .= html_writer::end_div();

    // Load the JavaScript module for provisioning.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/provision_scorm', 'init', [[
        'skillandid' => (int)$skilland->id,
        'cmid' => (int)$cm->id,
        'debug' => (bool)$devmode
    ]]);

    return $html;
}

/**
 * Get progress for all lessons in a Skilland activity.
 *
 * Queries SCORM tracking data to determine completion status for each lesson.
 *
 * @param int $userid The user ID.
 * @param int $skillandid The skilland activity ID.
 * @return array Array of [lessonid => ['status' => string, 'score' => int|null]]
 */
function skilland_get_lessons_progress($userid, $skillandid) {
    global $DB;

    // Check if Moodle 4.x SCORM tracking tables exist.
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('scorm_scoes_value') || !$dbman->table_exists('scorm_attempt')) {
        logger::warn('Progress', 'SCORM tracking tables (scorm_scoes_value/scorm_attempt) do not exist');
        return [];
    }

    // Get the skilland activity to find its SCORM cmid.
    $skilland = $DB->get_record('skilland', ['id' => $skillandid]);
    if (!$skilland || empty($skilland->scormcmid)) {
        logger::debug('Progress', 'skilland record not found or no scormcmid set. skillandid=' . $skillandid);
        return [];
    }
    logger::debug('Progress', 'skilland.scormcmid = ' . $skilland->scormcmid);

    // Get the SCORM instance.
    $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
    if (!$scormcm) {
        logger::debug('Progress', 'No SCORM course module found for cmid ' . $skilland->scormcmid);
        return [];
    }
    $scorm = $DB->get_record('scorm', ['id' => $scormcm->instance]);
    if (!$scorm) {
        logger::debug('Progress', 'No SCORM record found for instance ' . $scormcm->instance);
        return [];
    }
    logger::debug('Progress', 'Querying SCORM id=' . $scorm->id . ' (cmid=' . $skilland->scormcmid . ') for user ' . $userid);

    // Debug: Check what SCOs exist in this SCORM.
    $allScos = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id], 'id ASC', 'id, identifier, title');
    $scoIds = [];
    foreach ($allScos as $s) {
        $scoIds[] = $s->id;
    }
    logger::debug('Progress', 'SCOs in SCORM ' . $scorm->id . ': ' . implode(', ', $scoIds));

    // Check if ANY attempts exist for this SCORM (any user).
    $anyAttempts = $DB->count_records('scorm_attempt', ['scormid' => $scorm->id]);
    logger::debug('Progress', 'Total attempts for SCORM ' . $scorm->id . ' (any user): ' . $anyAttempts);

    // Get all lessons with their SCO IDs.
    $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $skillandid, 'visible' => 1]);
    if (empty($lessons)) {
        return [];
    }

    // Build array of SCO IDs to lesson IDs.
    $scoToLesson = [];
    foreach ($lessons as $lesson) {
        if (!empty($lesson->scoid)) {
            $scoToLesson[$lesson->scoid] = $lesson->id;
            logger::debug('Progress', 'Lesson ' . $lesson->id . ' (' . $lesson->title . ') mapped to SCO ' . $lesson->scoid);
        } else {
            logger::debug('Progress', 'Lesson ' . $lesson->id . ' (' . $lesson->title . ') has NO scoid');
        }
    }

    if (empty($scoToLesson)) {
        logger::debug('Progress', 'No lessons have scoid values - cannot track progress');
        return [];
    }

    logger::debug('Progress', 'Looking for SCO IDs: ' . implode(', ', array_keys($scoToLesson)));

    // Query SCORM tracking for these SCOs.
    // Moodle 4.x uses normalized tables: scorm_attempt, scorm_element, scorm_scoes_value
    // We look for cmi.core.lesson_status (SCORM 1.2) or cmi.completion_status (SCORM 2004).
    $scoIds = array_keys($scoToLesson);
    list($insql, $params) = $DB->get_in_or_equal($scoIds, SQL_PARAMS_NAMED);
    $params['userid'] = $userid;
    $params['scormid'] = $scorm->id;

    // Get the user's attempt(s) for this SCORM.
    $attempt = $DB->get_record_sql(
        "SELECT id FROM {scorm_attempt} WHERE userid = :userid AND scormid = :scormid ORDER BY attempt DESC LIMIT 1",
        ['userid' => $userid, 'scormid' => $scorm->id]
    );

    $tracks = [];
    if ($attempt) {
        logger::debug('Progress', 'Found attempt id=' . $attempt->id . ' for user ' . $userid . ' scorm ' . $scorm->id);

        // Query the new normalized tables.
        // Use ssv.id as first column to avoid duplicate key issues in get_records_sql.
        $sql = "SELECT ssv.id, ssv.scoid, e.element, ssv.value, ssv.timemodified
                FROM {scorm_scoes_value} ssv
                JOIN {scorm_element} e ON ssv.elementid = e.id
                WHERE ssv.attemptid = :attemptid
                  AND ssv.scoid $insql
                  AND e.element IN ('cmi.core.lesson_status', 'cmi.completion_status', 'cmi.success_status', 'cmi.core.score.raw', 'cmi.score.raw')
                ORDER BY ssv.timemodified DESC";

        $params['attemptid'] = $attempt->id;

        // Wrap in try-catch in case SCORM tables don't exist.
        try {
            $tracks = $DB->get_records_sql($sql, $params);
            logger::debug('Progress', 'Found ' . count($tracks) . ' tracking records for user ' . $userid);
        } catch (Exception $e) {
            // SCORM tracking table doesn't exist or query failed - return empty progress.
            logger::error('Progress', 'Exception querying tracking data - ' . $e->getMessage());
            $progress = [];
            foreach ($lessons as $lesson) {
                $progress[$lesson->id] = ['status' => 'not_started', 'score' => null];
            }
            return $progress;
        }
    } else {
        logger::debug('Progress', 'No attempt found for user ' . $userid . ' scorm ' . $scorm->id);
    }

    // Build progress array.
    $progress = [];
    foreach ($lessons as $lesson) {
        $progress[$lesson->id] = ['status' => 'not_started', 'score' => null];
    }

    // Process tracking data.
    $processedScos = [];
    foreach ($tracks as $track) {
        if (!isset($scoToLesson[$track->scoid])) {
            continue;
        }
        $lessonid = $scoToLesson[$track->scoid];

        // Update status.
        if (in_array($track->element, ['cmi.core.lesson_status', 'cmi.completion_status'])) {
            // Only update if not already processed (we want the latest).
            if (!isset($processedScos[$track->scoid]['status'])) {
                $progress[$lessonid]['status'] = strtolower($track->value);
                $processedScos[$track->scoid]['status'] = true;
            }
        }

        // Update score.
        if (in_array($track->element, ['cmi.core.score.raw', 'cmi.score.raw'])) {
            if (!isset($processedScos[$track->scoid]['score'])) {
                $progress[$lessonid]['score'] = $track->value;
                $processedScos[$track->scoid]['score'] = true;
            }
        }
    }

    logger::debug('Progress', 'Final progress data: ' . json_encode($progress));

    return $progress;
}
