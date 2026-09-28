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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/skilland/backup/moodle2/restore_skilland_stepslib.php');

/**
 * Skilland restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_skilland_activity_task extends restore_activity_task {
    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        $this->add_step(new restore_skilland_activity_structure_step('skilland_structure', 'skilland.xml'));
    }

    /**
     * Define the contents for this activity
     */
    public static function define_decode_contents() {
        $contents = [];

        $contents[] = new restore_decode_content('skilland', ['intro'], 'skilland');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    public static function define_decode_rules() {
        $rules = [];

        $rules[] = new restore_decode_rule('SKILLANDVIEWBYID', '/mod/skilland/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('SKILLANDINDEX', '/mod/skilland/index.php?id=$1', 'course');

        return $rules;
    }

    /**
     * Define the restoring rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    public static function define_restore_log_rules() {
        $rules = [];

        $rules[] = new restore_log_rule('skilland', 'add', 'view.php?id={course_module}', '{name}');
        $rules[] = new restore_log_rule('skilland', 'update', 'view.php?id={course_module}', '{name}');
        $rules[] = new restore_log_rule('skilland', 'view', 'view.php?id={course_module}', '{name}');

        return $rules;
    }

    /**
     * Define the restoring rules for links belonging to the activity to be executed
     * by the 'restore_decode_interlinks' step
     */
    public static function define_restore_log_rules_for_course() {
        $rules = [];

        $rules[] = new restore_log_rule('skilland', 'view all', 'index.php?id={course}', '{course}');

        return $rules;
    }

    /**
     * Called once every activity of the restore has been restored.
     *
     * The structure step stores the backup's own SCORM course module id (scormcmid) and SCO ids
     * (skilland_lesson.scoid) unmapped, because the linked SCORM activity may come after this one
     * in the section sequence and is then not restored yet. Here both are mapped to the restored
     * SCORM. Only when the SCORM is not part of this restore (a single-activity import or
     * duplicate, or a restore that left it out) is a new package provisioned from the API.
     */
    public function after_restore() {
        global $DB;

        $skillandid = $this->get_activityid();
        $skilland = $DB->get_record('skilland', ['id' => $skillandid]);

        if (!$skilland) {
            return;
        }

        try {
            $scormcmid = $this->map_scorm_cmid($skilland);
        } catch (Exception $e) {
            debugging(
                'Skilland: Could not map the SCORM course module after restore: ' . $e->getMessage(),
                DEBUG_NORMAL
            );
            $scormcmid = null;
        }
        $DB->set_field('skilland', 'scormcmid', $scormcmid, ['id' => $skillandid]);
        $skilland->scormcmid = $scormcmid;

        $this->map_lesson_scos($skilland);

        if (empty($skilland->scormcmid) && !empty($skilland->skilland_topicid)) {
            $this->reprovision_scorm($skilland);
        }
    }

    /**
     * The restored SCORM course module the backup's scormcmid maps to.
     *
     * @param stdClass $skilland The restored activity record, still holding the backup's scormcmid.
     * @return int|null The new course module id, or null when the SCORM is not part of this restore.
     */
    protected function map_scorm_cmid(stdClass $skilland): ?int {
        global $DB;

        if (empty($skilland->scormcmid)) {
            return null;
        }

        $newcmid = $this->get_mapped_id('course_module', $skilland->scormcmid);
        if (!$newcmid) {
            debugging(
                'Skilland: SCORM course module ' . $skilland->scormcmid . ' is not part of this restore',
                DEBUG_DEVELOPER
            );
            return null;
        }
        if (!$DB->record_exists('course_modules', ['id' => $newcmid, 'course' => $skilland->course])) {
            debugging('Skilland: SCORM course module ' . $newcmid . ' not found after restore', DEBUG_DEVELOPER);
            return null;
        }
        return $newcmid;
    }

    /**
     * Map each lesson's backed-up SCO id to the SCO of the restored package, by the SCORM restore's
     * scorm_sco mapping or, failing that, by sco_identifier. Without a restored SCORM every
     * lesson's scoid is cleared (provisioning maps them again).
     *
     * @param stdClass $skilland The restored activity record with its mapped scormcmid.
     */
    protected function map_lesson_scos(stdClass $skilland): void {
        global $DB;

        $scormid = 0;
        if (!empty($skilland->scormcmid)) {
            $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $skilland->scormcmid]);
        }

        foreach ($DB->get_records('skilland_lesson', ['skillandid' => $skilland->id]) as $lesson) {
            if (empty($lesson->scoid)) {
                continue;
            }

            $newscoid = 0;
            if ($scormid) {
                try {
                    $newscoid = $this->get_mapped_id('scorm_sco', $lesson->scoid);
                } catch (Exception $e) {
                    $newscoid = 0;
                }
                if ($newscoid && !$DB->record_exists('scorm_scoes', ['id' => $newscoid, 'scorm' => $scormid])) {
                    $newscoid = 0;
                }
                if (!$newscoid && !empty($lesson->sco_identifier)) {
                    $newscoid = (int) $DB->get_field(
                        'scorm_scoes',
                        'id',
                        ['scorm' => $scormid, 'identifier' => $lesson->sco_identifier]
                    );
                }
            }

            $DB->set_field('skilland_lesson', 'scoid', $newscoid ?: null, ['id' => $lesson->id]);
        }
    }

    /**
     * Provision a new topic SCORM from the API for an activity restored without its SCORM.
     *
     * @param stdClass $skilland The restored activity record.
     */
    protected function reprovision_scorm(stdClass $skilland): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        try {
            $cm = get_coursemodule_from_instance('skilland', $skilland->id, 0, false, MUST_EXIST);
            $course = get_course($cm->course);
            $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

            $hasvisiblelessons = $DB->record_exists('skilland_lesson', [
                'skillandid' => $skilland->id,
                'visible' => 1,
            ]);

            if ($hasvisiblelessons) {
                $scormcmid = skilland_provision_topic_scorm($skilland, $course, $sectionnum);

                if ($scormcmid) {
                    debugging('Skilland: Successfully re-provisioned SCORM (cmid=' . $scormcmid .
                        ') after restore for activity ' . $skilland->id, DEBUG_DEVELOPER);
                }
            } else {
                debugging('Skilland: Skipping SCORM provisioning after restore - no visible lessons for activity ' .
                    $skilland->id, DEBUG_DEVELOPER);
            }
        } catch (Exception $e) {
            // Log the error but don't fail the restore.
            debugging('Skilland: Failed to re-provision SCORM after restore: ' . $e->getMessage(), DEBUG_NORMAL);
        }
    }

    /**
     * The new id this restore gave an item of the backup.
     *
     * @param string $itemname Backup item name (course_module, scorm_sco).
     * @param int|string $oldid The id in the backup.
     * @return int The new id, 0 when the item is not part of this restore.
     */
    protected function get_mapped_id(string $itemname, $oldid): int {
        $record = restore_dbops::get_backup_ids_record($this->get_restoreid(), $itemname, $oldid);
        return $record ? (int) $record->newitemid : 0;
    }
}
