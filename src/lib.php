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
 * Library functions for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\output\action_link;
use mod_skilland\logger;

/**
 * Returns the information on whether the module supports a feature.
 *
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know
 */
function skilland_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
    }
    if (defined('FEATURE_MOD_PURPOSE') && $feature === FEATURE_MOD_PURPOSE) {
        return MOD_PURPOSE_CONTENT;
    }
    return null;
}

/**
 * Logs the activity view and marks it viewed for view-based completion.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $course The course record.
 * @param stdClass|cm_info $cm The course module.
 * @param context_module $context The module context.
 */
function skilland_view(stdClass $skilland, stdClass $course, $cm, context_module $context): void {
    global $CFG;

    $event = \mod_skilland\event\course_module_viewed::create([
        'context' => $context,
        'objectid' => $skilland->id,
    ]);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('skilland', $skilland);
    $event->trigger();

    require_once($CFG->libdir . '/completionlib.php');
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Returns display information for the course page.
 *
 * When hidelabels is enabled, strips the "T1 - " prefix from the activity name
 * so students don't see Skilland codes on the Moodle course page.
 *
 * @param stdClass $coursemodule The course module record.
 * @return cached_cm_info|null Module info to cache, or null on failure.
 */
function skilland_get_coursemodule_info($coursemodule) {
    global $CFG, $DB;

    $skilland = $DB->get_record('skilland', ['id' => $coursemodule->instance], '*', IGNORE_MISSING);
    if (!$skilland) {
        return null;
    }

    $info = new cached_cm_info();
    if (!empty($skilland->hidelabels)) {
        $info->name = preg_replace('/^T\d+\s*-\s*/', '', $skilland->name);
    }

    require_once($CFG->libdir . '/completionlib.php');
    if (($coursemodule->completion ?? 0) == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionlessons'] = (int) ($skilland->completionlessons ?? 0);
    }

    return $info;
}

/**
 * Adds a new instance of the skilland module.
 *
 * @param stdClass $skilland The object containing the data to be inserted.
 * @param mod_skilland_mod_form $mform The form object (optional).
 * @return int The id of the new instance.
 */
function skilland_add_instance($skilland, $mform = null) {
    global $DB;
    require_once(__DIR__ . '/locallib.php');

    $skilland->timecreated = time();
    $skilland->timemodified = time();

    // Extract selected lessons before cleaning object.
    $selectedlessons = isset($skilland->selected_lessons) ? $skilland->selected_lessons : null;
    unset($skilland->selected_lessons);

    // ALWAYS prefer the hidden field value over the select (Moodle may strip select values
    // that don't match original options defined at PHP time).
    if (!empty($skilland->skilland_topicid_saved)) {
        $skilland->skilland_topicid = $skilland->skilland_topicid_saved;
    }
    unset($skilland->skilland_topicid_saved);

    logger::debug('Instance', 'skilland_add_instance topic ' . ($skilland->skilland_topicid ?? ''));

    skilland_require_topic_in_mapped_course((int)($skilland->course ?? 0), (string)($skilland->skilland_topicid ?? ''));

    // Ensure topic_orderindex has a default value.
    if (empty($skilland->topic_orderindex)) {
        $skilland->topic_orderindex = 1;
    }

    // Handle Skilland course mapping.
    // Note: Skilland Course ID is stored in course custom fields, not in the skilland table.
    // The legacy mapping table may still be used for additional metadata.

    // Remove any form fields that are not in the skilland table.
    unset($skilland->skilland_courseid);
    unset($skilland->skilland_courseid_readonly);

    $skilland->grade = (int) ($skilland->grade ?? 0);
    $skilland->completionlessons = empty($skilland->completionlessons) ? 0 : 1;

    $transaction = $DB->start_delegated_transaction();
    try {
        $id = $DB->insert_record('skilland', $skilland);

        // Process selected lessons with topic order index for proper numbering.
        if ($selectedlessons && $id) {
            skilland_process_selected_lessons($id, $selectedlessons);
        }
    } catch (\Throwable $e) {
        $transaction->rollback($e);
    }
    $transaction->allow_commit();

    if ($id && $skilland->grade > 0) {
        $skilland->id = $id;
        skilland_grade_item_update($skilland);
    }

    return $id;
}

/**
 * Updates an instance of the skilland module.
 *
 * @param stdClass $skilland The object containing the data to be updated.
 * @param mod_skilland_mod_form $mform The form object (optional).
 * @return bool True on success, false otherwise.
 */
function skilland_update_instance($skilland, $mform = null) {
    global $DB;
    require_once(__DIR__ . '/locallib.php');

    $skilland->timemodified = time();
    $skilland->id = $skilland->instance;

    // Extract selected lessons before cleaning object.
    $selectedlessons = isset($skilland->selected_lessons) ? $skilland->selected_lessons : null;
    unset($skilland->selected_lessons);

    // ALWAYS prefer the hidden field value over the select (Moodle may strip select values
    // that don't match original options defined at PHP time).
    if (!empty($skilland->skilland_topicid_saved)) {
        $skilland->skilland_topicid = $skilland->skilland_topicid_saved;
    }
    unset($skilland->skilland_topicid_saved);

    logger::debug('Instance', 'skilland_update_instance topic ' . ($skilland->skilland_topicid ?? ''));

    skilland_require_topic_in_mapped_course((int)($skilland->course ?? 0), (string)($skilland->skilland_topicid ?? ''));

    // Ensure topic_orderindex has a default value.
    if (empty($skilland->topic_orderindex)) {
        $skilland->topic_orderindex = 1;
    }

    // Remove fields that are not in the skilland table.
    unset($skilland->skilland_courseid);
    unset($skilland->skilland_courseid_readonly);

    if (isset($skilland->grade)) {
        $skilland->grade = (int) $skilland->grade;
    }
    if (isset($skilland->completionlessons)) {
        $skilland->completionlessons = empty($skilland->completionlessons) ? 0 : 1;
    }

    $old = $DB->get_record('skilland', ['id' => $skilland->id], 'id, skilland_topicid, scormcmid, grade', IGNORE_MISSING);
    $topicchanged = $old && (string) $old->skilland_topicid !== (string) ($skilland->skilland_topicid ?? '');
    if ($topicchanged) {
        // The stored snapshot belongs to the old topic; cron must never compare the new one against it.
        $skilland->snapshotid = null;
    }

    $transaction = $DB->start_delegated_transaction();
    try {
        $result = $DB->update_record('skilland', $skilland);

        // Process selected lessons with topic order index for proper numbering.
        if ($selectedlessons && $result) {
            skilland_process_selected_lessons($skilland->id, $selectedlessons);
        }
    } catch (\Throwable $e) {
        $transaction->rollback($e);
    }
    $transaction->allow_commit();

    if ($result && $old && !empty($old->scormcmid)) {
        skilland_reconcile_scorm_after_update((int) $skilland->id, $topicchanged);
    }

    if ($result && isset($skilland->grade)) {
        skilland_sync_grades_after_update((int) $skilland->id, (int) ($old->grade ?? 0));
    }

    return $result;
}

/**
 * Keep the gradebook in line with a saved maximum grade: none (0) deletes the grade item,
 * otherwise the item is updated, and every learner is regraded when the maximum changed.
 *
 * @param int $skillandid The skilland activity id.
 * @param int $oldgrade The maximum grade before the save.
 */
function skilland_sync_grades_after_update(int $skillandid, int $oldgrade): void {
    global $DB;

    $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', IGNORE_MISSING);
    if (!$skilland) {
        return;
    }
    $grade = (int) ($skilland->grade ?? 0);
    if ($grade <= 0) {
        if ($oldgrade > 0) {
            skilland_grade_item_delete($skilland);
        }
        return;
    }
    skilland_grade_item_update($skilland);
    if ($grade !== $oldgrade) {
        skilland_update_grades($skilland);
    }
}

/**
 * Bring a provisioned activity's SCORM in line with its saved settings. Never throws, so the
 * settings save never fails because the SCORM could not be rebuilt.
 *
 * - Topic changed: rebuild the SCORM for the new topic; when that fails, drop back to the
 *   unprovisioned ("Provision") state with the old module gone and warn the teacher.
 * - Topic unchanged: map lessons ticked after provisioning to the installed package's SCOs.
 *
 * @param int $skillandid The skilland activity id.
 * @param bool $topicchanged Whether the save changed the activity's topic.
 */
function skilland_reconcile_scorm_after_update(int $skillandid, bool $topicchanged): void {
    global $DB;

    try {
        if (!$topicchanged) {
            skilland_resolve_lesson_scos($skillandid);
            return;
        }

        $current = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);
        try {
            $cm = get_coursemodule_from_instance('skilland', $skillandid, $current->course, false, MUST_EXIST);
            $course = get_course($cm->course);
            $sectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $cm->section]);
            \core\di::get(\mod_skilland\local\topic_scorm_updater::class)->update($current, $course, $sectionnum);
        } catch (\Throwable $e) {
            logger::error('SCORM', 'Re-provisioning after a topic change failed for skilland id ' . $skillandid .
                ' - resetting to the unprovisioned state: ' . $e->getMessage());
            try {
                skilland_reset_topic_scorm($current);
            } catch (\Throwable $reseterror) {
                logger::error('SCORM', 'Resetting skilland id ' . $skillandid . ' failed: ' .
                    $reseterror->getMessage());
            }
            \core\notification::warning(get_string('topic_changed_reprovision_failed', 'mod_skilland'));
        }
    } catch (\Throwable $e) {
        logger::error('SCORM', 'Reconciling the SCORM of skilland id ' . $skillandid . ' failed: ' .
            $e->getMessage());
    }
}

/**
 * Require that a topic belongs to the Skilland course mapped to a Moodle course.
 *
 * The topic ID arrives from a hidden form field, so it is checked here on the server
 * rather than trusted from the topic select.
 *
 * @param int $moodlecourseid Moodle course ID
 * @param string $topicid Skilland topic ID
 * @throws moodle_exception When the course is unmapped or the topic belongs to another skill
 */
function skilland_require_topic_in_mapped_course(int $moodlecourseid, string $topicid): void {
    $skillandcourseid = skilland_get_mapped_courseid($moodlecourseid);
    if ($skillandcourseid === null) {
        throw new moodle_exception('error_course_not_mapped', 'mod_skilland');
    }
    if (!skilland_topic_belongs_to_course($topicid, $skillandcourseid)) {
        throw new moodle_exception('error_course_not_mapped_to_skill', 'mod_skilland');
    }
}

/**
 * Deletes an instance of the skilland module, together with its linked topic SCORM.
 *
 * The SCORM is deleted under the provisioning lock so an in-flight provision finishes first.
 * The lock is best-effort: when it stays busy the SCORM is deleted anyway, because a stuck
 * deletion is worse than the rare orphan cli/cleanup_orphaned_scorm.php repairs. A SCORM
 * failure never blocks deleting the activity (or the course).
 *
 * @param int $id The id of the instance to be deleted.
 * @return bool True on success, false otherwise.
 */
function skilland_delete_instance($id) {
    global $DB;

    $skilland = $DB->get_record('skilland', ['id' => $id]);
    if (!$skilland) {
        return false;
    }

    if (!empty($skilland->scormcmid)) {
        require_once(__DIR__ . '/locallib.php');

        $lock = null;
        try {
            $lock = skilland_get_provision_lock((int) $skilland->id);
        } catch (\moodle_exception $e) {
            logger::warn('SCORM', 'Provisioning lock busy while deleting skilland id ' . $skilland->id .
                '; deleting its SCORM anyway');
        }
        try {
            $scormcm = skilland_get_linked_scorm_cm($skilland);
            if ($scormcm) {
                skilland_delete_scorm_module((int) $scormcm->id);
            }
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    // Progress rows and the grade item go with the activity (SKL-668).
    $DB->delete_records('skilland_progress', ['skillandid' => $id]);
    if ((int) ($skilland->grade ?? 0) > 0) {
        skilland_grade_item_delete($skilland);
    }

    // Delete associated lesson records.
    $DB->delete_records('skilland_lesson', ['skillandid' => $id]);

    // Delete the activity instance.
    // Note: We intentionally do NOT delete the Skilland course mapping,
    // as it may be used by other activities in the same Moodle course.
    return $DB->delete_records('skilland', ['id' => $id]);
}

/**
 * Load the gradebook API unless it is already defined (standalone tests stub it).
 */
function skilland_require_gradelib(): void {
    global $CFG;

    if (!function_exists('grade_update')) {
        require_once($CFG->libdir . '/gradelib.php');
    }
}

/**
 * Create or update the activity's grade item, optionally with grades.
 *
 * A maximum grade of 0 (none) or below means the activity is not graded: the item is deleted.
 *
 * @param stdClass $skilland The skilland activity record (course, id, name, grade).
 * @param mixed $grades Grade objects or arrays, 'reset', or null to update only the item.
 * @return int GRADE_UPDATE_OK, GRADE_UPDATE_FAILED, ...
 */
function skilland_grade_item_update($skilland, $grades = null) {
    skilland_require_gradelib();

    if ((int) ($skilland->grade ?? 0) <= 0) {
        return skilland_grade_item_delete($skilland);
    }

    $params = [
        'itemname' => $skilland->name ?? '',
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax' => (int) $skilland->grade,
        'grademin' => 0,
    ];
    if (isset($skilland->cmidnumber)) {
        $params['idnumber'] = $skilland->cmidnumber;
    }
    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/skilland', $skilland->course, 'mod', 'skilland', $skilland->id, 0, $grades, $params);
}

/**
 * Delete the activity's grade item.
 *
 * @param stdClass $skilland The skilland activity record.
 * @return int GRADE_UPDATE_OK, GRADE_UPDATE_FAILED, ...
 */
function skilland_grade_item_delete($skilland) {
    skilland_require_gradelib();

    return grade_update(
        'mod/skilland',
        $skilland->course,
        'mod',
        'skilland',
        $skilland->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Learners' raw grades, from the progress store.
 *
 * Raw grade = grade x mean / 100, the mean taken over the activity's visible lessons of: the SCO
 * raw score clamped to 0-100 when one was reported, else 100 when the lesson is completed or
 * passed, else 0. A learner with no progress row on a visible lesson gets no grade.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $userid One learner, or 0 for all.
 * @return array [userid => (object) ['userid' => int, 'rawgrade' => float]]
 */
function skilland_get_user_grades($skilland, $userid = 0) {
    global $DB;

    $grade = (int) ($skilland->grade ?? 0);
    if ($grade <= 0) {
        return [];
    }

    $visible = [];
    foreach ($DB->get_records('skilland_lesson', ['skillandid' => $skilland->id, 'visible' => 1], '', 'id') as $lesson) {
        $visible[(int) $lesson->id] = true;
    }
    if (!$visible) {
        return [];
    }

    $conditions = ['skillandid' => $skilland->id];
    if ($userid) {
        $conditions['userid'] = (int) $userid;
    }
    $points = [];
    foreach ($DB->get_records('skilland_progress', $conditions) as $row) {
        if (!isset($visible[(int) $row->lessonid])) {
            continue;
        }
        if ($row->score !== null && $row->score !== '') {
            $value = max(0.0, min(100.0, (float) $row->score));
        } else if (in_array($row->status, ['completed', 'passed'], true)) {
            $value = 100.0;
        } else {
            $value = 0.0;
        }
        $points[(int) $row->userid][(int) $row->lessonid] = $value;
    }

    $grades = [];
    foreach ($points as $uid => $lessons) {
        $mean = array_sum($lessons) / count($visible);
        $grades[$uid] = (object) ['userid' => $uid, 'rawgrade' => round($grade * $mean / 100, 5)];
    }
    return $grades;
}

/**
 * Push learners' grades to the gradebook.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $userid One learner, or 0 for all.
 * @param bool $nullifnone Write an empty grade for a learner who has none.
 */
function skilland_update_grades($skilland, $userid = 0, $nullifnone = true) {
    if ((int) ($skilland->grade ?? 0) <= 0) {
        return;
    }

    $grades = skilland_get_user_grades($skilland, $userid);
    if ($grades) {
        skilland_grade_item_update($skilland, $grades);
    } else if ($userid && $nullifnone) {
        skilland_grade_item_update($skilland, (object) ['userid' => (int) $userid, 'rawgrade' => null]);
    } else {
        skilland_grade_item_update($skilland);
    }
}

/**
 * Recompute a learner's activity completion and grade after their progress changed.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $userid The learner.
 */
function skilland_recompute_user(stdClass $skilland, int $userid): void {
    global $CFG;

    require_once($CFG->libdir . '/completionlib.php');

    $cm = get_coursemodule_from_instance('skilland', $skilland->id, $skilland->course ?? 0, false, IGNORE_MISSING);
    if ($cm) {
        $completion = new completion_info(get_course($cm->course));
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }
    skilland_update_grades($skilland, $userid);
}

/**
 * A learner's tracks changed on a SCORM: when it is an activity's topic SCORM, refresh that
 * learner's progress store and recompute their completion and grade. Never takes the
 * provisioning lock.
 *
 * @param int $scormcmid The SCORM course module id.
 * @param int $userid The learner.
 */
function skilland_handle_scorm_tracking(int $scormcmid, int $userid): void {
    global $DB;

    if ($scormcmid <= 0 || $userid <= 0) {
        return;
    }

    require_once(__DIR__ . '/locallib.php');

    foreach ($DB->get_records('skilland', ['scormcmid' => $scormcmid]) as $skilland) {
        try {
            skilland_refresh_progress($skilland, $userid);
            skilland_recompute_user($skilland, $userid);
        } catch (\Throwable $e) {
            logger::error('Progress', 'Recomputing progress of user ' . $userid . ' on skilland id ' .
                $skilland->id . ' failed - ' . $e->getMessage());
        }
    }
}

/**
 * Returns the activity's linked SCORM course module when it is still live.
 *
 * @param stdClass $skilland The skilland activity record.
 * @return stdClass|null The SCORM course module, or null when scormcmid is empty, the module is
 *         missing, or it is being deleted (deletioninprogress).
 */
function skilland_get_linked_scorm_cm(stdClass $skilland): ?stdClass {
    if (empty($skilland->scormcmid)) {
        return null;
    }
    $cm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
    if (!$cm || !empty($cm->deletioninprogress)) {
        return null;
    }
    return $cm;
}

/**
 * Unlinks the activity from its (deleted) SCORM: clears the provisioning fields and every
 * lesson's SCO mapping. Idempotent. Never takes the provisioning lock, because it runs from the
 * course_module_deleted observer, which also fires while that lock is held.
 *
 * @param int $skillandid The skilland activity id.
 */
function skilland_unlink_scorm(int $skillandid): void {
    global $DB;

    require_once(__DIR__ . '/locallib.php');

    $DB->update_record('skilland', (object) [
        'id' => $skillandid,
        'scormcmid' => null,
        'scorm_provisioned' => null,
        'scomappings' => null,
        'snapshotid' => null,
        'snapshotcreatedat' => null,
    ]);
    skilland_clear_lesson_scos($skillandid);
}

/**
 * The Skilland position a selected lesson was submitted with: an integer >= 1, else 0 (unknown).
 *
 * @param mixed $value The submitted `position`.
 * @return int
 */
function skilland_submitted_lesson_position($value): int {
    if (is_string($value) && preg_match('/^[0-9]{1,9}$/', $value)) {
        $value = (int) $value;
    }
    return is_int($value) && $value >= 1 && $value <= 999999999 ? $value : 0;
}

/**
 * Process and save selected lessons.
 *
 * `updatedat` is owned by the SCORM build (skilland_update_topic_scorm()): it is the version of
 * the lesson inside the installed package. Existing rows (visible or hidden) never take the
 * submitted `updatedAt`; only newly inserted lessons do.
 *
 * `skillandposition` is the lesson's 1-based position in the topic in Skilland (its lesson code,
 * skilland_lesson_label()), as the form shows it. A missing or invalid one is stored as 0 on a
 * new row and leaves an existing row's position unchanged.
 *
 * @param int $skillandid The Skilland activity instance ID.
 * @param string $json The JSON string containing selected lessons.
 */
function skilland_process_selected_lessons($skillandid, $json) {
    global $DB;

    $selected = json_decode($json, true);
    if (!is_array($selected)) {
        return;
    }

    // Get existing lessons for this activity.
    $existing = $DB->get_records(
        'skilland_lesson',
        ['skillandid' => $skillandid],
        '',
        'skilland_lessonid, id, visible, orderindex, skillandposition'
    );

    $processedids = [];
    $orderindex = 1; // Start lesson numbering at 1.

    foreach ($selected as $lessonid => $data) {
        $malformed = !is_array($data);
        if (!$malformed) {
            $badname = isset($data['name']) && !is_string($data['name']);
            $baddate = isset($data['updatedAt']) && !is_string($data['updatedAt']) && !is_int($data['updatedAt']);
            $malformed = $badname || $baddate;
        }
        if ($malformed) {
            debugging('mod_skilland: skipped a selected lesson with malformed data', DEBUG_DEVELOPER);
            continue;
        }

        $processedids[$lessonid] = true;

        $name = isset($data['name']) ? core_text::substr($data['name'], 0, 255) : '';
        $position = skilland_submitted_lesson_position($data['position'] ?? null);

        if (isset($existing[$lessonid])) {
            // Update existing record.
            $rec = $existing[$lessonid];
            $rec->visible = 1;
            $rec->orderindex = $orderindex;
            if ($name) {
                $rec->title = $name;
            }
            if ($position > 0) {
                $rec->skillandposition = $position;
            }
            $DB->update_record('skilland_lesson', $rec);
        } else {
            // Insert new record.
            $updatedat = skilland_parse_timestamp($data['updatedAt'] ?? 0);

            $rec = new stdClass();
            $rec->skillandid = $skillandid;
            $rec->skilland_lessonid = $lessonid;
            $rec->title = $name;
            $rec->updatedat = $updatedat;
            $rec->visible = 1;
            $rec->orderindex = $orderindex;
            $rec->skillandposition = $position;
            $DB->insert_record('skilland_lesson', $rec);
        }

        $orderindex++;
    }

    // Mark unselected lessons as hidden.
    foreach ($existing as $lessonid => $rec) {
        if (!isset($processedids[$lessonid]) && $rec->visible == 1) {
            $rec->visible = 0;
            $DB->update_record('skilland_lesson', $rec);
        }
    }
}

/**
 * Adds the Skilland link to the course navigation menu.
 *
 * @param navigation_node $parentnode The course navigation node.
 * @param stdClass $course The course object.
 * @param context_course $context The course context.
 */
function mod_skilland_extend_navigation_course(
    navigation_node $parentnode,
    stdClass $course,
    context_course $context
) {
    require_once(__DIR__ . '/locallib.php');
    if (!skilland_is_enabled()) {
        return;
    }

    if (!has_capability('mod/skilland:accessstudio', $context)) {
        return;
    }

    $categoryid = skilland_get_course_customfield()->get('categoryid');
    $skillandcourseid = skilland_get_course_customfield_value($course->id);

    if (empty($skillandcourseid)) {
        $text = get_string('configure_skilland', 'mod_skilland');
        $action = new \moodle_url('/course/edit.php', ['id' => $course->id]);
        $action->set_anchor("id_category_$categoryid");
    } else {
        $text = get_string('edit_in_skilland_header', 'mod_skilland');
        $url = new \moodle_url('/mod/skilland/sso_redirect.php', [
            'courseid' => $course->id,
            'sesskey' => sesskey(),
        ]);
        $action = new action_link($url, $text, null, [
            'target' => '_blank',
        ]);
    }

    $parentnode->add($text, $action);
}
