<?php
defined('MOODLE_INTERNAL') || die();

use core\output\action_link;
use mod_skilland\logger;

/**
 * Returns the information on whether the module supports a feature.
 */
function skilland_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        default:
            return null;
    }
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
    global $DB;

    $skilland = $DB->get_record('skilland', ['id' => $coursemodule->instance], '*', IGNORE_MISSING);
    if (!$skilland) {
        return null;
    }

    $info = new cached_cm_info();
    if (!empty($skilland->hidelabels)) {
        $info->name = preg_replace('/^T\d+\s*-\s*/', '', $skilland->name);
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

    logger::debug('Instance', 'skilland_add_instance called');
    logger::debug('Instance', 'skilland_topicid = ' . (isset($skilland->skilland_topicid) ? $skilland->skilland_topicid : 'NOT SET'));
    logger::debug('Instance', 'skilland_topicid_saved = ' . (isset($skilland->skilland_topicid_saved) ? $skilland->skilland_topicid_saved : 'NOT SET'));

    $skilland->timecreated = time();
    $skilland->timemodified = time();

    // Extract selected lessons before cleaning object.
    $selected_lessons = isset($skilland->selected_lessons) ? $skilland->selected_lessons : null;
    unset($skilland->selected_lessons);

    // ALWAYS prefer the hidden field value over the select (Moodle may strip select values
    // that don't match original options defined at PHP time).
    if (!empty($skilland->skilland_topicid_saved)) {
        $skilland->skilland_topicid = $skilland->skilland_topicid_saved;
        logger::debug('Instance', 'Using skilland_topicid_saved: ' . $skilland->skilland_topicid);
    }
    unset($skilland->skilland_topicid_saved);

    logger::debug('Instance', 'Final skilland_topicid = ' . (isset($skilland->skilland_topicid) ? $skilland->skilland_topicid : 'EMPTY'));

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

    $id = $DB->insert_record('skilland', $skilland);

    // Process selected lessons with topic order index for proper numbering.
    if ($selected_lessons && $id) {
        skilland_process_selected_lessons($id, $selected_lessons);
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

    logger::debug('Instance', 'skilland_update_instance called');
    logger::debug('Instance', 'skilland_topicid = ' . (isset($skilland->skilland_topicid) ? $skilland->skilland_topicid : 'NOT SET'));
    logger::debug('Instance', 'skilland_topicid_saved = ' . (isset($skilland->skilland_topicid_saved) ? $skilland->skilland_topicid_saved : 'NOT SET'));

    $skilland->timemodified = time();
    $skilland->id = $skilland->instance;

    // Extract selected lessons before cleaning object.
    $selected_lessons = isset($skilland->selected_lessons) ? $skilland->selected_lessons : null;
    unset($skilland->selected_lessons);

    // ALWAYS prefer the hidden field value over the select (Moodle may strip select values
    // that don't match original options defined at PHP time).
    if (!empty($skilland->skilland_topicid_saved)) {
        $skilland->skilland_topicid = $skilland->skilland_topicid_saved;
        logger::debug('Instance', 'Using skilland_topicid_saved: ' . $skilland->skilland_topicid);
    }
    unset($skilland->skilland_topicid_saved);

    logger::debug('Instance', 'Final skilland_topicid = ' . (isset($skilland->skilland_topicid) ? $skilland->skilland_topicid : 'EMPTY'));

    // Ensure topic_orderindex has a default value.
    if (empty($skilland->topic_orderindex)) {
        $skilland->topic_orderindex = 1;
    }

    // Remove fields that are not in the skilland table.
    unset($skilland->skilland_courseid);
    unset($skilland->skilland_courseid_readonly);

    $result = $DB->update_record('skilland', $skilland);

    // Process selected lessons with topic order index for proper numbering.
    if ($selected_lessons && $result) {
        skilland_process_selected_lessons($skilland->id, $selected_lessons);
    }

    return $result;
}

/**
 * Deletes an instance of the skilland module.
 *
 * @param int $id The id of the instance to be deleted.
 * @return bool True on success, false otherwise.
 */
function skilland_delete_instance($id) {
    global $DB;

    if (!$DB->record_exists('skilland', array('id' => $id))) {
        return false;
    }

    // Delete associated lesson records.
    $DB->delete_records('skilland_lesson', array('skillandid' => $id));

    // Delete the activity instance.
    // Note: We intentionally do NOT delete the Skilland course mapping,
    // as it may be used by other activities in the same Moodle course.
    return $DB->delete_records('skilland', array('id' => $id));
}

/**
 * Process and save selected lessons.
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
    $existing = $DB->get_records('skilland_lesson', array('skillandid' => $skillandid), '', 'skilland_lessonid, id, visible, orderindex');

    $processed_ids = [];
    $orderindex = 1; // Start lesson numbering at 1.

    foreach ($selected as $lessonid => $data) {
        $processed_ids[$lessonid] = true;

        $updatedAt = isset($data['updatedAt']) ? $data['updatedAt'] : 0;
        // Convert ISO8601 string to timestamp if necessary.
        if (!is_numeric($updatedAt)) {
            $updatedAt = strtotime($updatedAt);
        }

        $name = isset($data['name']) ? $data['name'] : '';

        if (isset($existing[$lessonid])) {
            // Update existing record.
            $rec = $existing[$lessonid];
            $rec->updatedat = $updatedAt;
            $rec->visible = 1;
            $rec->orderindex = $orderindex;
            if ($name) {
                $rec->title = $name;
            }
            $DB->update_record('skilland_lesson', $rec);
        } else {
            // Insert new record.
            $rec = new stdClass();
            $rec->skillandid = $skillandid;
            $rec->skilland_lessonid = $lessonid;
            $rec->title = $name;
            $rec->updatedat = $updatedAt;
            $rec->visible = 1;
            $rec->orderindex = $orderindex;
            $DB->insert_record('skilland_lesson', $rec);
        }

        $orderindex++;
    }

    // Mark unselected lessons as hidden.
    foreach ($existing as $lessonid => $rec) {
        if (!isset($processed_ids[$lessonid]) && $rec->visible == 1) {
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

    if (!has_capability('mod/skilland:addinstance', $context)) {
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
            'sesskey' => sesskey()
        ]);
        $action = new action_link($url, $text, null, [
            'target' => '_blank'
        ]);
    }

    $parentnode->add($text, $action);
}
