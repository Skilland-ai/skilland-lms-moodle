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

namespace mod_skilland\task;

use mod_skilland\logger;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');
require_once(__DIR__ . '/../../lib.php');

/**
 * Scheduled task to sync Skilland content for activities with auto-update enabled.
 *
 * Polls the Skilland API to check if content has changed for each activity
 * that has autoupdate=1. If content changed, or the activity lost its SCORM, and the topic
 * has a ready package, re-provisions the SCORM package automatically.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_content extends \core\task\scheduled_task {

    /**
     * Return the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sync_content', 'mod_skilland');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        logger::info('SyncContent', 'Starting auto-update content sync task');

        // Check that plugin is enabled.
        if (!skilland_is_enabled()) {
            logger::info('SyncContent', 'Plugin is disabled — skipping sync');
            return;
        }

        // Local progress backfill first: no network, so it runs whatever the API does (SKL-668).
        // A failure here must not stop the API check below.
        try {
            $this->backfill_all_progress();
        } catch (\Throwable $e) {
            logger::error('SyncContent', 'Progress backfill failed: ' . get_class($e) . ': ' . $e->getMessage());
        }

        // Check that plugin is configured.
        $config = get_config('mod_skilland');
        if (empty($config->apikey) || empty($config->orgid) || empty($config->graphql_endpoint)) {
            logger::warn('SyncContent', 'Plugin not fully configured — skipping sync');
            return;
        }

        // Find all auto-update activities, including ones left without a SCORM by a failed update
        // (SKL-654): check_and_update() rebuilds them once the topic has a ready package.
        $activities = $DB->get_records_select(
            'skilland',
            'autoupdate = 1 AND skilland_topicid IS NOT NULL',
            null,
            'id ASC'
        );

        if (empty($activities)) {
            logger::info('SyncContent', 'No auto-update activities found');
            return;
        }

        logger::info('SyncContent', 'Found ' . count($activities) . ' auto-update activities to check');

        $updated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($activities as $skilland) {
            try {
                $result = $this->check_and_update($skilland);
                if ($result === 'updated') {
                    $updated++;
                } else if ($result === 'skipped') {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                // A TypeError from a malformed API response must not abort the other activities.
                $errors++;
                logger::error('SyncContent', 'Error checking activity ' . $skilland->id . ': ' . get_class($e) . ': ' .
                    $e->getMessage());
            }
        }

        logger::info('SyncContent', "Sync complete: {$updated} updated, {$skipped} skipped, {$errors} errors");
    }

    /**
     * Backfill the progress store of every provisioned activity from its current SCORM tracks.
     */
    private function backfill_all_progress(): void {
        global $DB;

        $provisioned = $DB->get_records_select('skilland', 'scormcmid IS NOT NULL', null, 'id ASC');
        foreach ($provisioned as $skilland) {
            try {
                $this->backfill_progress($skilland);
            } catch (\Throwable $e) {
                logger::error('SyncContent', 'Progress backfill failed for activity ' . $skilland->id . ': ' .
                    get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Merge the tracks of every learner of the activity's current SCORM into the progress store and
     * recompute completion and grade for the learners whose progress changed.
     *
     * @param \stdClass $skilland The skilland activity record.
     * @return int Number of learners whose progress changed.
     */
    private function backfill_progress($skilland): int {
        if (empty($skilland->scormcmid)) {
            return 0;
        }

        $tracksbyuser = skilland_read_scorm_progress($skilland);
        if (!$tracksbyuser) {
            return 0;
        }
        $existingbyuser = skilland_get_progress_rows_by_user((int) $skilland->id);

        $changed = 0;
        foreach ($tracksbyuser as $userid => $tracks) {
            if (skilland_refresh_progress($skilland, (int) $userid, $tracks, $existingbyuser[(int) $userid] ?? [])) {
                skilland_recompute_user($skilland, (int) $userid);
                $changed++;
            }
        }
        if ($changed) {
            logger::info('SyncContent', 'Backfilled progress of ' . $changed . ' learner(s) on activity ' . $skilland->id);
        }
        return $changed;
    }

    /**
     * Check a single activity for content changes and update if needed.
     *
     * @param \stdClass $skilland The skilland activity record.
     * @return string 'updated', 'skipped', or 'current'
     */
    private function check_and_update($skilland) {
        global $DB;

        $topicid = $skilland->skilland_topicid;

        // Cooldown: skip if synced within last 5 minutes.
        if (!empty($skilland->lastsynced) && (time() - $skilland->lastsynced) < 300) {
            logger::debug('SyncContent', 'Activity ' . $skilland->id . ' synced recently — skipping');
            return 'skipped';
        }

        // Check lockafterfirstaccess: if enabled and students have accessed, skip.
        if (!empty($skilland->lockafterfirstaccess) && !empty($skilland->scormcmid)) {
            $hasStudentAccess = $this->has_student_access($skilland);
            if ($hasStudentAccess) {
                logger::info('SyncContent', 'Activity ' . $skilland->id . ' locked (students have accessed) — skipping');
                return 'skipped';
            }
        }

        // Query Skilland API for current content hash.
        $hashinfo = mod_skilland_check_topic_snapshot($topicid);

        if ($hashinfo === null) {
            logger::warn('SyncContent', 'Could not get hash info for topic ' . $topicid);
            return 'skipped';
        }

        // Never build from a topic with no package yet, or whose package is still being
        // regenerated for newer content (SKL-654).
        if (empty($hashinfo['hasPackage'])) {
            logger::info('SyncContent', 'Topic ' . $topicid . ' has no SCORM package yet — skipping activity ' .
                $skilland->id);
            return 'skipped';
        }
        if (!empty($hashinfo['isStale'])) {
            logger::info('SyncContent', 'Package of topic ' . $topicid . ' is stale — skipping activity ' .
                $skilland->id . ' until it is regenerated');
            return 'skipped';
        }

        // Compare with stored snapshot.
        $currentHash = $skilland->snapshotid ?? '';
        $remoteHash = $hashinfo['contentHash'] ?? '';

        // Migration for activities provisioned before snapshotid was stored (SKL-649): an empty
        // stored hash on an already-provisioned activity means "unknown", not "changed". Record
        // the current remote hash once, without re-provisioning, so the next run compares
        // correctly instead of destroying student progress on a false positive.
        if (empty($currentHash) && !empty($skilland->scormcmid)) {
            $DB->update_record('skilland', (object) [
                'id' => $skilland->id,
                'snapshotid' => $remoteHash,
                'snapshotcreatedat' => !empty($hashinfo['generatedAt']) ? strtotime($hashinfo['generatedAt']) : time(),
                'lastsynced' => time(),
            ]);
            logger::info('SyncContent', 'Activity ' . $skilland->id .
                ' had no stored hash — recording current hash without re-provisioning (migration)');
            return 'current';
        }

        // An activity without a SCORM is never "current", whatever hash it still carries.
        if (!empty($skilland->scormcmid) && !empty($currentHash) && $currentHash === $remoteHash) {
            // Content hasn't changed — update lastsynced and move on.
            $DB->set_field('skilland', 'lastsynced', time(), ['id' => $skilland->id]);
            logger::debug('SyncContent', 'Activity ' . $skilland->id . ' is current (hash: ' . $currentHash . ')');
            return 'current';
        }

        // Content has changed — re-provision SCORM.
        logger::info('SyncContent', 'Content changed for activity ' . $skilland->id .
            ' (old: ' . $currentHash . ', new: ' . $remoteHash . ') — updating SCORM');

        $cm = get_coursemodule_from_instance('skilland', $skilland->id, 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

        try {
            // Thread the hash already fetched above through to provisioning, so it isn't queried twice.
            $newcmid = \core\di::get(\mod_skilland\local\topic_scorm_updater::class)->update($skilland, $course, $sectionnum,
                $remoteHash);
        } catch (\moodle_exception $e) {
            if ($e->errorcode === 'error_provision_in_progress') {
                logger::info('SyncContent', 'Activity ' . $skilland->id . ' is being provisioned elsewhere — skipping');
                return 'skipped';
            }
            throw $e;
        }

        // skilland_link_topic_scorm() wrote snapshotid/snapshotcreatedat with the built package,
        // and only once the new SCORM was linked; a failed build threw above and wrote nothing.
        $DB->set_field('skilland', 'lastsynced', time(), ['id' => $skilland->id]);

        logger::info('SyncContent', 'Activity ' . $skilland->id . ' updated successfully (new SCORM cmid: ' . $newcmid . ')');

        return 'updated';
    }

    /**
     * Check if any student has accessed this activity's SCORM content.
     *
     * @param \stdClass $skilland
     * @return bool
     */
    private function has_student_access($skilland) {
        global $DB;

        if (empty($skilland->scormcmid)) {
            return false;
        }

        $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
        if (!$scormcm) {
            return false;
        }

        $scorm = $DB->get_record('scorm', ['id' => $scormcm->instance]);
        if (!$scorm) {
            return false;
        }

        // Check if the newer Moodle 4.x tables exist.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('scorm_attempt')) {
            return $DB->record_exists('scorm_attempt', ['scormid' => $scorm->id]);
        }

        // Fallback for older Moodle versions.
        return $DB->record_exists('scorm_scoes_track', ['scormid' => $scorm->id]);
    }
}
