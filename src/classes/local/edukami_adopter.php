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

namespace mod_skilland\local;

/**
 * Turns mod_edukami activities into mod_skilland activities that keep their topic SCORM (SKL-997).
 *
 * For each Edukami activity whose topic the Edukami migration brought into Skilland, a Skilland
 * activity is created right before it, in the same section, linked to the same SCORM module with the
 * same snapshot hash and lesson->SCO mapping, so learners keep their attempts and the sync task sees
 * no update. The Edukami activity is then hidden, never deleted; the SCORM module is never touched.
 * An activity whose topic Skilland does not list is skipped and left alone. A second run is a no-op.
 *
 * Skilland has no route that turns Edukami ObjectIds into the migration's ids, so the candidates are
 * computed ({@see edukami_ids}) and the one the topic or lesson listing returns is kept. When none is
 * listed, a topic or lesson whose normalised name alone matches the activity name or lesson title is
 * kept, which covers a course mapped to a skill the import assembled from several Edukami skills.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edukami_adopter {
    /** @var string The activity was adopted (or would be, in a dry run). */
    public const ADOPTED = 'adopted';

    /** @var string A Skilland activity already uses the Edukami activity's SCORM. */
    public const ALREADY = 'already adopted';

    /** @var string Left alone: not in the migration, or the course maps another skill. */
    public const SKIPPED = 'skipped';

    /** @var string The adoption failed and was rolled back. */
    public const FAILED = 'failed';

    /** @var bool Write the changes; false is a dry run that only reads. */
    private bool $apply;

    /** @var int|null Only this Moodle course, or null for every course. */
    private ?int $courseid;

    /**
     * Create the adopter.
     *
     * @param bool $apply Write the changes; false is a dry run.
     * @param int|null $courseid Only adopt the activities of this Moodle course.
     */
    public function __construct(bool $apply, ?int $courseid = null) {
        $this->apply = $apply;
        $this->courseid = $courseid;
    }

    /**
     * Adopt every Edukami activity (of the course, when one was given).
     *
     * @return array[] One report per course: courseid, shortname, skillid (the Skilland skill used, or
     *     null), note (why the activities are matched against another skill than the Edukami one, or
     *     null), activities (each with outcome, edukamiid, cmid, name, reason, skillandid, matchedby
     *     ('name' when the topic matched by name, else null) and warnings) and counts (outcome => number).
     * @throws \moodle_exception When mod_edukami's tables or module are missing.
     * @throws \coding_exception When called inside a database transaction in apply mode.
     */
    public function run(): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/skilland/lib.php');
        require_once($CFG->dirroot . '/mod/skilland/locallib.php');

        $dbman = $DB->get_manager();
        foreach (['edukami', 'edukami_lesson'] as $table) {
            if (!$dbman->table_exists($table)) {
                throw new \moodle_exception(
                    'generalexceptionmessage',
                    'error',
                    '',
                    "the {$table} table does not exist: mod_edukami is not installed on this site"
                );
            }
        }
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'edukami']);
        if (!$moduleid) {
            throw new \moodle_exception(
                'generalexceptionmessage',
                'error',
                '',
                'mod_edukami is not registered in the modules table'
            );
        }
        if ($this->apply && $DB->is_transaction_started()) {
            // Each activity rolls back on its own; inside an outer transaction a failure would undo them all.
            throw new \coding_exception('edukami_adopter must not run inside a database transaction');
        }

        $params = ['moduleid' => $moduleid];
        $coursefilter = '';
        if ($this->courseid !== null) {
            $coursefilter = 'AND e.course = :courseid';
            $params['courseid'] = $this->courseid;
        }
        $rows = $DB->get_records_sql(
            "SELECT e.*, cm.id AS cmid, cm.section AS cmsection, cm.visible AS cmvisible,
                    cm.visibleoncoursepage AS cmvisibleoncoursepage, cm.availability AS cmavailability,
                    cm.showdescription AS cmshowdescription
               FROM {edukami} e
               JOIN {course_modules} cm ON cm.instance = e.id AND cm.module = :moduleid AND cm.course = e.course
              WHERE cm.deletioninprogress = 0 {$coursefilter}
           ORDER BY e.course, e.id",
            $params
        );

        $bycourse = [];
        foreach ($rows as $row) {
            $bycourse[(int) $row->course][] = $row;
        }

        $reports = [];
        foreach ($bycourse as $courseid => $courserows) {
            $reports[] = $this->adopt_course($courseid, $courserows);
        }
        return $reports;
    }

    /**
     * Adopt the Edukami activities of one course.
     *
     * A course already mapped to another Skilland skill than the one derived from its Edukami skill (a
     * microcredential built on import from several Edukami skills, say) is adopted into the mapped
     * skill: its activities are matched against the mapped skill's topics, by id and then by name. It
     * is skipped whole only when none of its activities matches a topic of the mapped skill.
     *
     * @param int $courseid Moodle course id.
     * @param \stdClass[] $rows Its Edukami activities, with their course module fields.
     * @return array The course report.
     */
    private function adopt_course(int $courseid, array $rows): array {
        global $DB;

        $report = [
            'courseid' => $courseid,
            'shortname' => (string) $DB->get_field('course', 'shortname', ['id' => $courseid]),
            'skillid' => null,
            'note' => null,
            'activities' => [],
        ];

        $skillvalue = $this->edukami_skill($courseid);
        if ($skillvalue === null) {
            return $this->all($report, $rows, self::SKIPPED, 'the course has no Edukami skill');
        }
        $skilloid = edukami_ids::is_object_id($skillvalue) ? strtolower($skillvalue) : null;
        // UUIDs and ObjectIds are case-insensitive: compare and ask Skilland in lower case.
        $skillid = $skilloid !== null ? edukami_ids::skill_id($skilloid) : strtolower($skillvalue);

        $mapped = skilland_get_mapped_courseid($courseid);
        $othermapping = $mapped !== null && !self::same_skill($mapped, $skillid, $skilloid);
        $report['skillid'] = $mapped ?? $skillid;

        try {
            $listed = mod_skilland_fetch_topics(strtolower($report['skillid']))['topics'] ?? [];
        } catch (\Throwable $e) {
            return $this->all($report, $rows, self::FAILED, 'Skilland did not list the topics: ' . $e->getMessage());
        }
        $topics = self::index($listed);

        $plans = [];
        foreach ($rows as $row) {
            $plans[] = $this->plan_activity($row, $skilloid, $topics);
        }
        self::drop_shared_name_matches($plans);

        if ($othermapping) {
            if (!self::any_match($plans)) {
                $report['skillid'] = null;
                return $this->all(
                    $report,
                    $rows,
                    self::SKIPPED,
                    "the course is mapped to another Skilland skill ({$mapped}), not {$skillid}, " .
                        'and none of its activities matches a topic of it'
                );
            }
            $report['note'] = "the course is mapped to Skilland skill {$mapped}, not to {$skillid} (the Edukami " .
                'skill\'s): its activities are matched against the mapped skill';
        }

        $ismapped = $mapped !== null;
        foreach ($plans as $plan) {
            $report['activities'][] = $this->adopt_activity($plan, $skilloid, $skillid, $ismapped);
        }
        return $this->with_counts($report);
    }

    /**
     * Work out, without writing anything, what to do with one Edukami activity: whether a Skilland
     * activity already keeps its SCORM, whether it has a SCORM to keep, and which listed topic it is.
     *
     * The topic is matched by its migrated id candidates first, then by a unique normalised name
     * ({@see normalise_name()}) among the listed topics.
     *
     * @param \stdClass $row The edukami row with its course module fields.
     * @param string|null $skilloid The course's Edukami skill ObjectId, null when it is not one.
     * @param array $topics The listed topics, as {@see index()} returns them.
     * @return array row, entry (the report entry so far), existing (skilland id keeping the SCORM, or
     *     null), scormcm, topicid (null when unmatched) and byname (whether the topic matched by name).
     */
    private function plan_activity(\stdClass $row, ?string $skilloid, array $topics): array {
        global $DB;

        $plan = [
            'row' => $row,
            'entry' => self::entry($row, self::SKIPPED, ''),
            'existing' => null,
            'scormcm' => null,
            'topicid' => null,
            'byname' => false,
        ];
        $courseid = (int) $row->course;
        $scormcmid = (int) ($row->scormcmid ?? 0);

        if ($scormcmid > 0) {
            $existing = $DB->get_record_select(
                'skilland',
                'course = ? AND scormcmid = ?',
                [$courseid, $scormcmid],
                'id',
                IGNORE_MULTIPLE
            );
            if ($existing) {
                $plan['existing'] = (int) $existing->id;
                $plan['entry']['outcome'] = self::ALREADY;
                $plan['entry']['skillandid'] = (int) $existing->id;
                return $plan;
            }
        }

        $scormcm = $scormcmid > 0 ? get_coursemodule_from_id('scorm', $scormcmid, $courseid, false, IGNORE_MISSING) : false;
        if (!$scormcm || !empty($scormcm->deletioninprogress)) {
            $plan['entry']['reason'] = 'the Edukami activity has no topic SCORM to keep';
            return $plan;
        }
        $plan['scormcm'] = $scormcm;

        $topicid = self::first_listed(self::candidates((string) $row->edukami_topicid, $skilloid, 'topic'), $topics['ids']);
        if ($topicid !== null) {
            $plan['topicid'] = $topicid;
            return $plan;
        }

        [$topicid, $why] = self::unique_name((string) $row->name, $topics['names'], 'topic');
        if ($topicid !== null) {
            $plan['topicid'] = $topicid;
            $plan['byname'] = true;
            $plan['entry']['matchedby'] = 'name';
            return $plan;
        }
        $plan['entry']['reason'] = 'topic ' . $row->edukami_topicid . ' is not in the migration, and ' . $why;
        return $plan;
    }

    /**
     * Unmatch the activities that matched a topic by name when another activity of the course matched
     * the same topic: which of them the topic belongs to is ambiguous.
     *
     * @param array[] $plans The plan_activity() results of one course, changed in place.
     */
    private static function drop_shared_name_matches(array &$plans): void {
        $claims = [];
        foreach ($plans as $plan) {
            if ($plan['topicid'] !== null) {
                $claims[$plan['topicid']] = ($claims[$plan['topicid']] ?? 0) + 1;
            }
        }
        foreach ($plans as &$plan) {
            if ($plan['byname'] && $claims[$plan['topicid']] > 1) {
                $plan['entry']['reason'] = 'its name matches topic ' . $plan['topicid'] .
                    ', which another activity of the course matches too: ambiguous';
                $plan['entry']['matchedby'] = null;
                $plan['topicid'] = null;
                $plan['byname'] = false;
            }
        }
        unset($plan);
    }

    /**
     * Whether any activity of the course is already adopted or matches a listed topic.
     *
     * @param array[] $plans The plan_activity() results of one course.
     * @return bool
     */
    private static function any_match(array $plans): bool {
        foreach ($plans as $plan) {
            if ($plan['existing'] !== null || $plan['topicid'] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Adopt one Edukami activity.
     *
     * @param array $plan Its plan_activity() result.
     * @param string|null $skilloid The course's Edukami skill ObjectId, null when it is not one.
     * @param string $skillid Skilland skill id the course is mapped to when it is not yet.
     * @param bool $ismapped Whether the course is mapped to the skill; set once this call maps it.
     * @return array The activity's report entry.
     */
    private function adopt_activity(array $plan, ?string $skilloid, string $skillid, bool &$ismapped): array {
        global $DB;

        $row = $plan['row'];
        $entry = $plan['entry'];
        $courseid = (int) $row->course;
        $scormcmid = (int) ($row->scormcmid ?? 0);

        if ($plan['existing'] !== null) {
            if ((int) $row->cmvisible === 1) {
                if ($this->apply) {
                    set_coursemodule_visible((int) $row->cmid, 0);
                    $entry['reason'] = 'Edukami activity hidden';
                } else {
                    $entry['reason'] = 'the Edukami activity would be hidden';
                }
            }
            return $entry;
        }
        $topicid = $plan['topicid'];
        if ($topicid === null) {
            return $entry;
        }

        try {
            $listed = mod_skilland_fetch_lessons($topicid);
        } catch (\Throwable $e) {
            $entry['outcome'] = self::FAILED;
            $entry['reason'] = 'Skilland did not list the lessons of topic ' . $topicid . ': ' . $e->getMessage();
            return $entry;
        }
        $index = self::index($listed);

        $edukamilessons = $DB->get_records('edukami_lesson', ['edukamiid' => $row->id, 'visible' => 1], 'orderindex ASC, id ASC');
        $matches = $this->match_lessons($edukamilessons, $index, $skilloid);
        $lessons = [];
        $lessonsbyname = 0;
        foreach ($edukamilessons as $lesson) {
            if (!isset($matches['ids'][$lesson->id])) {
                $entry['warnings'][] = 'lesson ' . $lesson->edukami_lessonid . ' (' . $lesson->title .
                    ') is not in the migration and is left out' . $matches['why'][$lesson->id];
                continue;
            }
            $lessonid = $matches['ids'][$lesson->id];
            if (!empty($matches['byname'][$lesson->id])) {
                $lessonsbyname++;
            }
            $sco = $this->lesson_sco($lesson, (int) $plan['scormcm']->instance);
            if ($sco === null) {
                $entry['warnings'][] = 'lesson ' . $lesson->edukami_lessonid . ' (' . $lesson->title .
                    ') has no SCO in the SCORM: its progress is not read';
            }
            $lessons[$lessonid] = ['edukami' => $lesson, 'sco' => $sco];
        }
        if ($edukamilessons && !$lessons) {
            $entry['reason'] = 'none of its lessons is in the migration (topic ' . $topicid . ')';
            return $entry;
        }

        $summary = 'topic ' . $topicid . ($plan['byname'] ? ' (matched by name)' : '') . ', ' . count($lessons) .
            ' lesson(s)' . ($lessonsbyname > 0 ? ' (' . $lessonsbyname . ' matched by name)' : '') .
            ', SCORM cm ' . $scormcmid;
        if (!$this->apply) {
            $entry['outcome'] = self::ADOPTED;
            $entry['reason'] = 'would adopt: ' . $summary;
            return $entry;
        }

        if (!$ismapped) {
            if (!skilland_set_course_customfield_value($courseid, $skillid)) {
                $entry['outcome'] = self::FAILED;
                $entry['reason'] = 'could not map the course to skill ' . $skillid;
                return $entry;
            }
            $ismapped = true;
        }

        try {
            $entry['skillandid'] = $this->create_activity($row, $topicid, $lessons, $scormcmid);
        } catch (\Throwable $e) {
            $entry['outcome'] = self::FAILED;
            $entry['reason'] = 'rolled back: ' . $e->getMessage();
            rebuild_course_cache($courseid, true);
            return $entry;
        }
        rebuild_course_cache($courseid, true);

        $entry['outcome'] = self::ADOPTED;
        $entry['reason'] = 'skilland ' . $entry['skillandid'] . ': ' . $summary;
        $warning = $this->backfill_progress($entry['skillandid']);
        if ($warning !== null) {
            $entry['warnings'][] = $warning;
        }
        return $entry;
    }

    /**
     * Match the Edukami lessons of an activity to the lessons Skilland lists for its topic: by their
     * migrated id candidates first, then by a unique normalised title among the listed lessons no other
     * Edukami lesson of the activity matched.
     *
     * @param \stdClass[] $edukamilessons The activity's visible edukami_lesson rows.
     * @param array $index The listed lessons, as {@see index()} returns them.
     * @param string|null $skilloid The course's Edukami skill ObjectId, or null.
     * @return array ids (edukami_lesson id => Skilland lesson id), byname (edukami_lesson id => true)
     *     and why (edukami_lesson id => why it did not match, '' when it did).
     */
    private function match_lessons(array $edukamilessons, array $index, ?string $skilloid): array {
        $ids = [];
        $byname = [];
        $why = [];
        foreach ($edukamilessons as $lesson) {
            $why[$lesson->id] = '';
            $candidates = self::candidates((string) $lesson->edukami_lessonid, $skilloid, 'content');
            $lessonid = self::first_listed($candidates, $index['ids']);
            if ($lessonid !== null) {
                $ids[$lesson->id] = $lessonid;
            }
        }
        $claims = array_count_values($ids);
        $bynamecandidates = [];
        foreach ($edukamilessons as $lesson) {
            if (isset($ids[$lesson->id])) {
                continue;
            }
            [$lessonid, $reason] = self::unique_name((string) $lesson->title, $index['names'], 'lesson');
            if ($lessonid === null) {
                $why[$lesson->id] = ' (' . $reason . ')';
                continue;
            }
            if (isset($claims[$lessonid])) {
                $why[$lesson->id] = ' (its title matches lesson ' . $lessonid . ', which another lesson matches by id)';
                continue;
            }
            $bynamecandidates[$lesson->id] = $lessonid;
        }
        $nameclaims = array_count_values($bynamecandidates);
        foreach ($bynamecandidates as $edukamilessonid => $lessonid) {
            if ($nameclaims[$lessonid] > 1) {
                $why[$edukamilessonid] = ' (its title matches lesson ' . $lessonid . ', which another lesson matches too)';
                continue;
            }
            $ids[$edukamilessonid] = $lessonid;
            $byname[$edukamilessonid] = true;
        }
        return ['ids' => $ids, 'byname' => $byname, 'why' => $why];
    }

    /**
     * Index listed topics or lessons by lower-case id and by normalised name.
     *
     * @param array[] $listed Topics or lessons as listed, each with an id and a name.
     * @return array ids (lower-case id => id as listed) and names (normalised name => ids as listed).
     */
    private static function index(array $listed): array {
        $ids = [];
        $names = [];
        foreach ($listed as $item) {
            $id = (string) $item['id'];
            $ids[strtolower($id)] = $id;
            $name = self::normalise_name((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[$name][] = $id;
            }
        }
        return ['ids' => $ids, 'names' => $names];
    }

    /**
     * The one listed topic or lesson whose normalised name is this one's.
     *
     * @param string $name The Edukami activity name or lesson title.
     * @param array $names Normalised name => ids as listed.
     * @param string $kind topic or lesson, for the reason.
     * @return array [id or null, why there is none].
     */
    private static function unique_name(string $name, array $names, string $kind): array {
        $key = self::normalise_name($name);
        $ids = $key === '' ? [] : array_values(array_unique($names[$key] ?? []));
        if (count($ids) === 1) {
            return [$ids[0], ''];
        }
        if (!$ids) {
            return [null, "no {$kind} is named \"{$name}\""];
        }
        return [null, count($ids) . " {$kind}s are named \"{$name}\": ambiguous"];
    }

    /**
     * A topic or lesson name as compared across Edukami and Skilland: Unicode NFC, whitespace collapsed
     * and trimmed, a leading "T<n> - " numbering prefix removed, lower case.
     *
     * @param string $name
     * @return string
     */
    public static function normalise_name(string $name): string {
        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($name, \Normalizer::FORM_C);
            if (is_string($normalised)) {
                $name = $normalised;
            }
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = preg_replace('/^T\s*\d+\s*[-\x{2013}\x{2014}:.]\s*/iu', '', $name) ?? $name;
        return mb_strtolower(trim($name), 'UTF-8');
    }

    /**
     * A report entry for an Edukami activity.
     *
     * @param \stdClass $row The edukami row with its course module fields.
     * @param string $outcome Outcome.
     * @param string $reason Reason.
     * @return array
     */
    private static function entry(\stdClass $row, string $outcome, string $reason): array {
        return [
            'outcome' => $outcome,
            'edukamiid' => (int) $row->id,
            'cmid' => (int) $row->cmid,
            'name' => (string) $row->name,
            'reason' => $reason,
            'skillandid' => null,
            'matchedby' => null,
            'warnings' => [],
        ];
    }

    /**
     * Create the Skilland activity, link it to the Edukami SCORM and hide the Edukami activity, all in
     * one transaction that is rolled back whole on any failure.
     *
     * @param \stdClass $row The edukami row with its course module fields.
     * @param string $topicid Skilland topic id.
     * @param array $lessons Skilland lesson id => ['edukami' => edukami_lesson row, 'sco' => scorm_scoes row or null].
     * @param int $scormcmid The SCORM course module id the activity keeps.
     * @return int The new skilland id.
     * @throws \Throwable Whatever failed, after the rollback.
     */
    private function create_activity(\stdClass $row, string $topicid, array $lessons, int $scormcmid): int {
        global $DB;

        $selected = [];
        $mappings = [];
        foreach ($lessons as $lessonid => $lesson) {
            $selected[$lessonid] = ['name' => (string) $lesson['edukami']->title,
                'updatedAt' => (int) ($lesson['edukami']->updatedat ?? 0)];
            if ($lesson['sco'] !== null) {
                $mappings[$lessonid] = (string) $lesson['sco']->identifier;
            }
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $info = create_module((object) [
                'modulename' => 'skilland',
                'course' => (int) $row->course,
                'section' => (int) $DB->get_field('course_sections', 'section', ['id' => $row->cmsection], MUST_EXIST),
                'beforemod' => (int) $row->cmid,
                'visible' => (int) $row->cmvisible,
                'visibleoncoursepage' => (int) $row->cmvisibleoncoursepage,
                'availability' => $row->cmavailability,
                'showdescription' => (int) $row->cmshowdescription,
                'name' => (string) $row->name,
                'introeditor' => ['text' => (string) ($row->intro ?? ''), 'format' => (int) $row->introformat,
                    'itemid' => file_get_unused_draft_itemid()],
                'skilland_topicid' => $topicid,
                'skilland_topicid_saved' => $topicid,
                'topic_orderindex' => max(1, (int) $row->topic_orderindex),
                'autoupdate' => (int) $row->autoupdate,
                'lockafterfirstaccess' => (int) $row->lockafterfirstaccess,
                'hidelabels' => (int) $row->hidelabels,
                'grade' => 0,
                'completionlessons' => 0,
                'selected_lessons' => json_encode($selected),
            ]);
            $skillandid = (int) $info->instance;
            // Core add_moduleinfo() drops the availability while $CFG->enableavailability is off: keep the
            // Edukami activity's restrictions verbatim, so they still apply once it is switched on.
            $DB->set_field('course_modules', 'availability', $row->cmavailability, ['id' => $info->coursemodule]);

            // The same fields skilland_link_topic_scorm() writes, with the Edukami snapshot hash kept
            // verbatim so the sync task finds the topic current (SKL-966).
            $DB->update_record('skilland', (object) [
                'id' => $skillandid,
                'scormcmid' => $scormcmid,
                'scorm_provisioned' => !empty($row->scorm_provisioned) ? (int) $row->scorm_provisioned : time(),
                'scomappings' => json_encode((object) $mappings),
                'snapshotid' => $row->snapshotid,
                'snapshotcreatedat' => $row->snapshotcreatedat,
                'lastsynced' => null,
                'updateavailable' => null,
            ]);

            foreach ($lessons as $lessonid => $lesson) {
                $lessonrow = $DB->get_record(
                    'skilland_lesson',
                    ['skillandid' => $skillandid, 'skilland_lessonid' => $lessonid],
                    'id',
                    MUST_EXIST
                );
                $DB->update_record('skilland_lesson', (object) [
                    'id' => $lessonrow->id,
                    'scoid' => $lesson['sco'] !== null ? (int) $lesson['sco']->id : null,
                    'sco_identifier' => $lesson['sco'] !== null ? (string) $lesson['sco']->identifier : null,
                    'snapshotid' => $lesson['edukami']->snapshotid,
                    'snapshotcreatedat' => $lesson['edukami']->snapshotcreatedat,
                ]);
            }

            set_coursemodule_visible((int) $row->cmid, 0, 1, false);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $this->rollback($transaction, $e);
            throw $e;
        }
        return $skillandid;
    }

    /**
     * Roll back the activity's transaction, including a delegated transaction create_module() left open.
     *
     * @param \moodle_transaction $transaction The activity's transaction (the outermost one).
     * @param \Throwable $e What failed.
     */
    private function rollback(\moodle_transaction $transaction, \Throwable $e): void {
        global $DB;

        try {
            $transaction->rollback($e);
        } catch (\Throwable $rethrown) {
            // Moodle rethrows the exception given to rollback(); the caller reports the original one.
            unset($rethrown);
        }
        // With an inner transaction still open, rollback() only marks the outer one: undo everything.
        if ($DB->is_transaction_started()) {
            $DB->force_transaction_rollback();
        }
        \context_helper::reset_caches();
    }

    /**
     * Read the learners' existing SCORM attempts into the adopted activity's progress store, as the sync
     * task would on its next run.
     *
     * @param int $skillandid The new skilland id.
     * @return string|null A warning when it failed (the sync task retries), else null.
     */
    private function backfill_progress(int $skillandid): ?string {
        global $DB;

        try {
            $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);
            foreach (skilland_read_scorm_progress($skilland) as $userid => $tracks) {
                if (skilland_refresh_progress($skilland, (int) $userid, $tracks)) {
                    skilland_recompute_user($skilland, (int) $userid);
                }
            }
        } catch (\Throwable $e) {
            return 'progress not read yet (the sync task retries): ' . $e->getMessage();
        }
        return null;
    }

    /**
     * The SCO of an Edukami lesson in the topic SCORM: by its scoid, else by its SCO identifier.
     *
     * @param \stdClass $lesson The edukami_lesson row.
     * @param int $scormid The SCORM instance id.
     * @return \stdClass|null The scorm_scoes row (id, identifier), or null when the SCORM has none.
     */
    private function lesson_sco(\stdClass $lesson, int $scormid): ?\stdClass {
        global $DB;

        if (!empty($lesson->scoid)) {
            $sco = $DB->get_record('scorm_scoes', ['id' => $lesson->scoid, 'scorm' => $scormid], 'id, identifier');
            if ($sco) {
                return $sco;
            }
        }
        if (!empty($lesson->sco_identifier)) {
            $sco = $DB->get_record(
                'scorm_scoes',
                ['scorm' => $scormid, 'identifier' => $lesson->sco_identifier],
                'id, identifier',
                IGNORE_MULTIPLE
            );
            if ($sco) {
                return $sco;
            }
        }
        return null;
    }

    /**
     * The Edukami skill a course was mapped to: its edukami_course_id custom field, else the legacy
     * edukami_course table.
     *
     * @param int $courseid Moodle course id.
     * @return string|null The Edukami skill id (an ObjectId), or null when the course has none.
     */
    private function edukami_skill(int $courseid): ?string {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT d.id, d.charvalue, d.value
               FROM {customfield_data} d
               JOIN {customfield_field} f ON f.id = d.fieldid
               JOIN {customfield_category} c ON c.id = f.categoryid
              WHERE f.shortname = :shortname AND c.component = :component AND c.area = :area
                    AND d.instanceid = :courseid",
            ['shortname' => 'edukami_course_id', 'component' => 'core_course', 'area' => 'course', 'courseid' => $courseid]
        );
        foreach ($rows as $row) {
            foreach ([$row->charvalue, $row->value] as $value) {
                if (trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        if ($DB->get_manager()->table_exists('edukami_course')) {
            $legacy = $DB->get_field('edukami_course', 'edukami_courseid', ['course' => $courseid], IGNORE_MULTIPLE);
            if (trim((string) $legacy) !== '') {
                return trim((string) $legacy);
            }
        }
        return null;
    }

    /**
     * Candidate Skilland ids of an Edukami topic or content; an id that is no ObjectId is its own candidate.
     *
     * @param string $id Edukami id.
     * @param string|null $skilloid Edukami skill ObjectId, or null.
     * @param string $kind topic or content.
     * @return string[]
     */
    private static function candidates(string $id, ?string $skilloid, string $kind): array {
        if (!edukami_ids::is_object_id($id)) {
            return [strtolower($id)];
        }
        return $kind === 'topic' ? edukami_ids::topic_id_candidates($id, $skilloid)
            : edukami_ids::content_id_candidates($id, $skilloid);
    }

    /**
     * The first candidate Skilland lists, compared in lower case.
     *
     * @param string[] $candidates Candidate ids, best first.
     * @param array $listed Listed ids: lower-case id => id as listed.
     * @return string|null The id as Skilland listed it.
     */
    private static function first_listed(array $candidates, array $listed): ?string {
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($listed[$key])) {
                return $listed[$key];
            }
        }
        return null;
    }

    /**
     * Whether a course mapping names the Edukami skill (by its migrated id or its ObjectId).
     *
     * @param string $mapped The course's skilland_course_id.
     * @param string $skillid The skill's Skilland id.
     * @param string|null $skilloid The skill's Edukami ObjectId, or null.
     * @return bool
     */
    private static function same_skill(string $mapped, string $skillid, ?string $skilloid): bool {
        return strcasecmp($mapped, $skillid) === 0 || ($skilloid !== null && strcasecmp($mapped, $skilloid) === 0);
    }

    /**
     * Give every activity of a course the same outcome and reason.
     *
     * @param array $report The course report.
     * @param \stdClass[] $rows The course's Edukami activities.
     * @param string $outcome Outcome.
     * @param string $reason Reason.
     * @return array The course report.
     */
    private function all(array $report, array $rows, string $outcome, string $reason): array {
        foreach ($rows as $row) {
            $report['activities'][] = self::entry($row, $outcome, $reason);
        }
        return $this->with_counts($report);
    }

    /**
     * Add the per-outcome counts to a course report.
     *
     * @param array $report The course report.
     * @return array
     */
    private function with_counts(array $report): array {
        $report['counts'] = [self::ADOPTED => 0, self::ALREADY => 0, self::SKIPPED => 0, self::FAILED => 0];
        foreach ($report['activities'] as $activity) {
            $report['counts'][$activity['outcome']]++;
        }
        return $report;
    }

    /**
     * Render the reports as lines of plain text (ids and names only).
     *
     * @param array[] $reports The run() result.
     * @param bool $apply Whether the run wrote anything.
     * @return string[]
     */
    public static function format(array $reports, bool $apply): array {
        $lines = [];
        $totals = [self::ADOPTED => 0, self::ALREADY => 0, self::SKIPPED => 0, self::FAILED => 0];
        foreach ($reports as $report) {
            $lines[] = sprintf(
                'Course %d (%s), Skilland skill %s',
                $report['courseid'],
                $report['shortname'],
                $report['skillid'] ?? 'none'
            );
            if (!empty($report['note'])) {
                $lines[] = '  note: ' . $report['note'];
            }
            foreach ($report['activities'] as $activity) {
                $lines[] = sprintf(
                    '  [%s] edukami %d (cm %d) "%s"%s',
                    $activity['outcome'],
                    $activity['edukamiid'],
                    $activity['cmid'],
                    $activity['name'],
                    $activity['reason'] !== '' ? ': ' . $activity['reason'] : ''
                );
                foreach ($activity['warnings'] as $warning) {
                    $lines[] = '      warning: ' . $warning;
                }
            }
            $counts = [];
            foreach ($report['counts'] as $outcome => $count) {
                $totals[$outcome] += $count;
                $counts[] = $outcome . ' ' . $count;
            }
            $lines[] = '  ' . implode(', ', $counts);
        }
        $total = [];
        foreach ($totals as $outcome => $count) {
            $total[] = $outcome . ' ' . $count;
        }
        $lines[] = sprintf(
            '%s: %d course(s); %s',
            $apply ? 'Done' : 'Dry run, nothing written',
            count($reports),
            implode(', ', $total)
        );
        return $lines;
    }
}
