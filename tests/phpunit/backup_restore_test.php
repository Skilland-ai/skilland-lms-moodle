<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/backup_stub.php';
require_once __DIR__ . '/stubs/filelib.php';

/**
 * FakeDatabase that, like Moodle's insert_record(), ignores the id of the inserted object, so a
 * restore inserts the backup's rows under new ids instead of overwriting the originals.
 */
class restore_fake_database extends \FakeDatabase {
    public function insert_record(string $table, $dataobject, bool $returnid = true): int {
        $obj = is_object($dataobject) ? clone $dataobject : (object) $dataobject;
        unset($obj->id);
        return parent::insert_record($table, $obj, $returnid);
    }
}

/**
 * Backup, restore, import, course copy and duplicate of a Skilland activity and its topic SCORM
 * (SKL-651), run through the plugin's real backup/restore classes in Moodle's order: every
 * activity task's structure step in section order, then every task's after_restore().
 */
class backup_restore_test extends TestCase {

    private const SOURCE_COURSE = 3;
    private const SKILLAND_ID = 7;
    private const SKILLAND_CMID = 40;
    private const SCORM_CMID = 50;
    private const SCORM_ID = 60;

    private const GLOBALS_TO_RESET = [
        '_test_curl_response', '_test_curl_responses', '_test_curl_last', '_test_curl_requests',
        '_test_lock_available', '_test_lock_calls', '_test_create_module_calls', '_test_create_module_throw',
        '_test_scorm_scoes', '_test_events', '_test_cm_from_db', '_test_get_coursemodule_from_instance',
        '_test_create_module_throw_after_insert', '_test_get_coursemodule_from_id',
    ];

    /** @var string A dirroot whose mod/skilland points at src, for the task files' require_once. */
    private static $dirroot;

    /** @var string */
    private $originaldirroot;

    /** @var restore_fake_database */
    private $db;

    /** @var int */
    private $restorecount = 0;

    public static function setUpBeforeClass(): void {
        // The task files read $CFG at file scope, which require_once runs in this method's scope.
        global $CFG;

        self::$dirroot = sys_get_temp_dir() . '/skl_backup_dirroot_' . getmypid();
        if (!is_dir(self::$dirroot . '/mod')) {
            mkdir(self::$dirroot . '/mod', 0777, true);
        }
        if (!file_exists(self::$dirroot . '/mod/skilland')) {
            symlink(realpath(__DIR__ . '/../../src'), self::$dirroot . '/mod/skilland');
        }
        $original = $GLOBALS['CFG']->dirroot;
        $GLOBALS['CFG']->dirroot = self::$dirroot;
        require_once self::$dirroot . '/mod/skilland/backup/moodle2/backup_skilland_activity_task.class.php';
        require_once self::$dirroot . '/mod/skilland/backup/moodle2/restore_skilland_activity_task.class.php';
        $GLOBALS['CFG']->dirroot = $original;
    }

    public static function tearDownAfterClass(): void {
        @unlink(self::$dirroot . '/mod/skilland');
        @rmdir(self::$dirroot . '/mod');
        @rmdir(self::$dirroot);
    }

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        \core\di::reset_container();
        $this->originaldirroot = $GLOBALS['CFG']->dirroot;
        $GLOBALS['CFG']->dirroot = self::$dirroot;
        \restore_dbops::$mappings = [];

        $this->db = new restore_fake_database();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['USER'] = (object) ['id' => 2];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        \fake_api_client::topic_snapshot(null);
        $GLOBALS['_test_scorm_scoes'] = [
            ['identifier' => 'org', 'launch' => ''],
            ['identifier' => 'sco_1', 'launch' => 'l1.html'],
            ['identifier' => 'sco_2', 'launch' => 'l2.html'],
        ];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://api.skilland.ai/graphql',
        ]];
        \mod_skilland\logger::reset_cache();

        // Source course 3: section 2 holds the Skilland activity (cm 40) and its hidden topic
        // SCORM (cm 50, scorm 60, SCOs 501/502).
        $this->db->seed('skilland', [(object) [
            'id' => self::SKILLAND_ID, 'course' => self::SOURCE_COURSE, 'name' => 'T4 - Topic four',
            'intro' => '', 'introformat' => 1, 'skilland_topicid' => 'topic1', 'snapshotid' => 'hash1',
            'snapshotcreatedat' => 1700000000, 'lastsynced' => 1700000100, 'autoupdate' => 1,
            'lockafterfirstaccess' => 0, 'hidelabels' => 1, 'topic_orderindex' => 4,
            'scormcmid' => self::SCORM_CMID, 'scorm_provisioned' => 1700000200,
            'scomappings' => '{"L1":"sco_1","L2":"sco_2"}', 'completionlessons' => 1, 'grade' => 100,
            'timecreated' => 1690000000, 'timemodified' => 1700000300,
        ]]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => self::SKILLAND_ID, 'skilland_lessonid' => 'L1', 'title' => 'One',
                'orderindex' => 1, 'scoid' => 501, 'sco_identifier' => 'sco_1', 'snapshotid' => null,
                'snapshotcreatedat' => null, 'updatedat' => 1700000000, 'visible' => 1],
            (object) ['id' => 2, 'skillandid' => self::SKILLAND_ID, 'skilland_lessonid' => 'L2', 'title' => 'Two',
                'orderindex' => 2, 'scoid' => 502, 'sco_identifier' => 'sco_2', 'snapshotid' => null,
                'snapshotcreatedat' => null, 'updatedat' => 1700000000, 'visible' => 1],
            (object) ['id' => 3, 'skillandid' => self::SKILLAND_ID, 'skilland_lessonid' => 'L3', 'title' => 'Three',
                'orderindex' => 3, 'scoid' => null, 'sco_identifier' => null, 'snapshotid' => null,
                'snapshotcreatedat' => null, 'updatedat' => 1700000000, 'visible' => 0],
        ]);
        $this->db->seed('skilland_course', [(object) [
            'id' => 1, 'course' => self::SOURCE_COURSE, 'skilland_courseid' => 'skill-a', 'skilland_orgid' => 'org1',
        ]]);
        $this->db->seed('course_sections', [
            (object) ['id' => 20, 'course' => self::SOURCE_COURSE, 'section' => 2],
            (object) ['id' => 21, 'course' => 8, 'section' => 2],
            (object) ['id' => 22, 'course' => 9, 'section' => 2],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => self::SKILLAND_CMID, 'course' => self::SOURCE_COURSE, 'module' => 1,
                'instance' => self::SKILLAND_ID, 'section' => 20],
            (object) ['id' => self::SCORM_CMID, 'course' => self::SOURCE_COURSE, 'module' => 99,
                'instance' => self::SCORM_ID, 'section' => 20],
        ]);
        $this->db->seed('scorm', [(object) ['id' => self::SCORM_ID, 'course' => self::SOURCE_COURSE,
            'name' => 'Topic four']]);
        $this->db->seed('scorm_scoes', [
            (object) ['id' => 500, 'scorm' => self::SCORM_ID, 'identifier' => 'org', 'launch' => ''],
            (object) ['id' => 501, 'scorm' => self::SCORM_ID, 'identifier' => 'sco_1', 'launch' => 'l1.html'],
            (object) ['id' => 502, 'scorm' => self::SCORM_ID, 'identifier' => 'sco_2', 'launch' => 'l2.html'],
        ]);
        $this->db->seed('scorm_scoes_track', [
            (object) ['id' => 900, 'userid' => 5, 'scormid' => self::SCORM_ID, 'scoid' => 501,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        \core\di::reset_container();
        $GLOBALS['CFG']->dirroot = $this->originaldirroot;
        \restore_dbops::$mappings = [];
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Harness: backup, and a restore plan run in Moodle's order
    // ---------------------------------------------------------------

    /** The activity as backup_skilland_activity_structure_step writes it to skilland.xml. */
    private function backup_activity(bool $userinfo = false): array {
        $step = new \backup_skilland_activity_structure_step('skilland_structure', 'skilland.xml',
            ['userinfo' => $userinfo]);
        $root = $step->build_structure();
        $lessonel = $root->children['lessons']->children['lesson'];

        $record = $this->db->get_record('skilland', ['id' => self::SKILLAND_ID]);
        $map = $this->db->get_record('skilland_course', ['course' => $record->course]);
        $record->skilland_courseid = $map->skilland_courseid ?? null;
        $record->skilland_orgid = $map->skilland_orgid ?? null;

        $activity = ['id' => $record->id];
        foreach ($root->finalelements as $field) {
            $activity[$field] = $record->$field ?? null;
        }
        $lessons = [];
        foreach ($this->db->get_records('skilland_lesson', ['skillandid' => self::SKILLAND_ID]) as $lesson) {
            $row = ['id' => $lesson->id];
            foreach ($lessonel->finalelements as $field) {
                $row[$field] = $lesson->$field ?? null;
            }
            $lessons[] = $row;
        }
        return ['skilland' => $activity, 'lessons' => $lessons];
    }

    /**
     * Restore the activities of $sequence ('skilland', 'scorm') into $courseid in that order,
     * then run every task's after_restore(), as restore_plan::execute() does.
     *
     * @return array ['skilland' => new instance id, 'skillandcmid' => ..., 'scormcmid' => ..., 'scoes' => [old => new]]
     */
    private function restore(array $sequence, int $courseid, array $backup, bool $userinfo = false,
            bool $mapscoes = true): array {
        $restoreid = 'restore' . (++$this->restorecount);
        $result = ['scoes' => []];
        $tasks = [];
        $section = (int) $this->db->get_field('course_sections', 'id', ['course' => $courseid, 'section' => 2]);

        foreach ($sequence as $activity) {
            if ($activity === 'scorm') {
                // What mod_scorm's restore does: new module, instance and SCOs, each mapped.
                $cmid = $this->db->insert_record('course_modules', (object) ['course' => $courseid,
                    'module' => 99, 'instance' => 0, 'section' => $section]);
                \restore_dbops::set_backup_ids_record($restoreid, 'course_module', self::SCORM_CMID, $cmid);
                $scormid = $this->db->insert_record('scorm', (object) ['course' => $courseid, 'name' => 'Topic four']);
                $this->db->set_field('course_modules', 'instance', $scormid, ['id' => $cmid]);
                foreach ($this->db->get_records('scorm_scoes', ['scorm' => self::SCORM_ID]) as $sco) {
                    $newsco = $this->db->insert_record('scorm_scoes', (object) ['scorm' => $scormid,
                        'identifier' => $sco->identifier, 'launch' => $sco->launch]);
                    $result['scoes'][$sco->id] = $newsco;
                    if ($mapscoes) {
                        \restore_dbops::set_backup_ids_record($restoreid, 'scorm_sco', $sco->id, $newsco);
                    }
                }
                if ($userinfo) {
                    foreach ($this->db->get_records('scorm_scoes_track', ['scormid' => self::SCORM_ID]) as $track) {
                        $this->db->insert_record('scorm_scoes_track', (object) ['userid' => $track->userid,
                            'scormid' => $scormid, 'scoid' => $result['scoes'][$track->scoid],
                            'element' => $track->element, 'value' => $track->value]);
                    }
                }
                $result['scormcmid'] = $cmid;
                continue;
            }

            $task = new \restore_skilland_activity_task($restoreid, $courseid, self::SKILLAND_ID,
                ['userinfo' => $userinfo]);
            $task->build();
            $step = $task->get_steps()[0];
            $paths = array_map(fn($p) => $p->name, $step->build_structure());
            $this->assertSame(['skilland', 'skilland_lesson'], $paths);

            $step->process_element('skilland', $backup['skilland']);
            foreach ($backup['lessons'] as $lesson) {
                $step->process_element('skilland_lesson', $lesson);
            }
            $step->finish();

            $newid = $task->get_activityid();
            $cmid = $this->db->insert_record('course_modules', (object) ['course' => $courseid, 'module' => 1,
                'instance' => $newid, 'section' => $section]);
            \restore_dbops::set_backup_ids_record($restoreid, 'course_module', self::SKILLAND_CMID, $cmid);
            $result['skilland'] = $newid;
            $result['skillandcmid'] = $cmid;
            $tasks[] = $task;
        }

        foreach ($tasks as $task) {
            $GLOBALS['_test_get_coursemodule_from_instance'] = $this->db->get_record('course_modules',
                ['id' => $result['skillandcmid']]);
            $task->after_restore();
        }
        return $result;
    }

    private function restored(array $result): \stdClass {
        return $this->db->get_record('skilland', ['id' => $result['skilland']]);
    }

    private function restored_lesson(array $result, string $lessonid): \stdClass {
        return $this->db->get_record('skilland_lesson', ['skillandid' => $result['skilland'],
            'skilland_lessonid' => $lessonid]);
    }

    private function response(array $data): array {
        return ['body' => json_encode(['data' => $data]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    /** Queue the topicScorm GraphQL answer and the package download a re-provision makes. */
    private function queue_package(): void {
        $zip = base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
        $GLOBALS['_test_curl_responses'][] = $this->response(['topicScorm' => [
            'packageUrl' => 'https://cdn.skilland.ai/topic1.zip',
            'packageSize' => strlen($zip),
            'packageHash' => '',
            'generatedAt' => '2026-01-02T00:00:00Z',
            'expiresAt' => '',
            'mappings' => [['lessonId' => 'L1', 'scoId' => 'sco_1'], ['lessonId' => 'L2', 'scoId' => 'sco_2']],
        ]]);
        $GLOBALS['_test_curl_responses'][] = ['body' => $zip, 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    private function assert_linked_to_restored_scorm(array $result, int $courseid): void {
        $row = $this->restored($result);
        $this->assertSame($courseid, (int) $row->course);
        $this->assertSame($result['scormcmid'], (int) $row->scormcmid,
            'The activity links the SCORM restored alongside it');
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? [], 'No SCORM is provisioned from the API');
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? []);

        $this->assertSame($result['scoes'][501], (int) $this->restored_lesson($result, 'L1')->scoid);
        $this->assertSame($result['scoes'][502], (int) $this->restored_lesson($result, 'L2')->scoid);
        $this->assertNull($this->restored_lesson($result, 'L3')->scoid);

        $this->assertSame(4, (int) $row->topic_orderindex, 'Lesson numbering keeps the topic position');
        $this->assertSame('{"L1":"sco_1","L2":"sco_2"}', $row->scomappings);

        $source = $this->db->get_record('skilland', ['id' => self::SKILLAND_ID]);
        $this->assertSame(self::SCORM_CMID, (int) $source->scormcmid, 'The source activity is untouched');
        $this->assertSame(501, (int) $this->db->get_record('skilland_lesson', ['id' => 1])->scoid);
    }

    // ---------------------------------------------------------------
    // Feature and backup structure
    // ---------------------------------------------------------------

    public function test_activity_declares_moodle2_backup_support(): void {
        $this->assertTrue(skilland_supports(FEATURE_BACKUP_MOODLE2));
    }

    public function test_backup_carries_topic_orderindex_scomappings_and_sco_identifiers(): void {
        $backup = $this->backup_activity();

        $this->assertSame(4, $backup['skilland']['topic_orderindex']);
        $this->assertSame('{"L1":"sco_1","L2":"sco_2"}', $backup['skilland']['scomappings']);
        $this->assertSame(self::SCORM_CMID, $backup['skilland']['scormcmid']);
        $this->assertSame('skill-a', $backup['skilland']['skilland_courseid']);
        $this->assertSame([501, 502, null], array_column($backup['lessons'], 'scoid'));
        $this->assertSame(['sco_1', 'sco_2', null], array_column($backup['lessons'], 'sco_identifier'));
    }

    // ---------------------------------------------------------------
    // Full backup/restore: both section orders, with and without user data
    // ---------------------------------------------------------------

    public static function section_orders(): array {
        return [
            'SCORM before Skilland, no user data' => [['scorm', 'skilland'], false],
            'SCORM after Skilland, no user data' => [['skilland', 'scorm'], false],
            'SCORM before Skilland, with user data' => [['scorm', 'skilland'], true],
            'SCORM after Skilland, with user data' => [['skilland', 'scorm'], true],
        ];
    }

    /**
     * @dataProvider section_orders
     */
    public function test_restore_links_the_restored_scorm_in_either_section_order(array $sequence,
            bool $userinfo): void {
        $result = $this->restore($sequence, 8, $this->backup_activity($userinfo), $userinfo);

        $this->assert_linked_to_restored_scorm($result, 8);
        $newscormid = (int) $this->db->get_field('course_modules', 'instance', ['id' => $result['scormcmid']]);
        $tracks = $this->db->get_records('scorm_scoes_track', ['scormid' => $newscormid]);
        $this->assertCount($userinfo ? 1 : 0, $tracks);
        if ($userinfo) {
            $this->assertSame($result['scoes'][501], (int) reset($tracks)->scoid,
                'Restored learner attempts sit on a SCO the activity links');
        }
    }

    public function test_structure_step_keeps_backup_ids_until_after_restore(): void {
        $task = new \restore_skilland_activity_task('restore-x', 8, self::SKILLAND_ID);
        $task->build();
        $step = $task->get_steps()[0];
        $backup = $this->backup_activity();
        $step->process_element('skilland', $backup['skilland']);
        foreach ($backup['lessons'] as $lesson) {
            $step->process_element('skilland_lesson', $lesson);
        }

        $row = $this->db->get_record('skilland', ['id' => $task->get_activityid()]);
        $this->assertSame(self::SCORM_CMID, (int) $row->scormcmid, 'Not resolved before the SCORM is restored');
        $lesson = $this->db->get_record('skilland_lesson', ['skillandid' => $row->id, 'skilland_lessonid' => 'L1']);
        $this->assertSame(501, (int) $lesson->scoid);
        $this->assertEmpty(array_filter($this->db->get_calls_for('record_exists'),
            fn($c) => $c['table'] === 'course_modules'), 'No course_modules check before the SCORM exists');
    }

    public function test_sco_falls_back_to_identifier_when_scorm_sco_is_not_mapped(): void {
        $result = $this->restore(['skilland', 'scorm'], 8, $this->backup_activity(), false, false);

        $this->assertSame($result['scormcmid'], (int) $this->restored($result)->scormcmid);
        $this->assertSame($result['scoes'][501], (int) $this->restored_lesson($result, 'L1')->scoid);
        $this->assertSame($result['scoes'][502], (int) $this->restored_lesson($result, 'L2')->scoid);
    }

    // ---------------------------------------------------------------
    // Course copy and import
    // ---------------------------------------------------------------

    public function test_course_copy_links_restored_scorm_and_maps_new_course(): void {
        $result = $this->restore(['skilland', 'scorm'], 8, $this->backup_activity());

        $this->assert_linked_to_restored_scorm($result, 8);
        $map = $this->db->get_record('skilland_course', ['course' => 8]);
        $this->assertSame('skill-a', $map->skilland_courseid);
        $this->assertSame('org1', $map->skilland_orgid);
    }

    public function test_import_into_mapped_course_keeps_its_mapping(): void {
        $this->db->insert_record('skilland_course', (object) ['course' => 9, 'skilland_courseid' => 'skill-a',
            'skilland_orgid' => 'org1']);

        $result = $this->restore(['skilland', 'scorm'], 9, $this->backup_activity());

        $this->assert_linked_to_restored_scorm($result, 9);
        $this->assertSame(1, $this->db->count_records('skilland_course', ['course' => 9]));
    }

    public function test_import_without_the_scorm_provisions_a_new_package(): void {
        $this->queue_package();

        $result = $this->restore(['skilland'], 9, $this->backup_activity());

        $this->assertCount(1, $GLOBALS['_test_create_module_calls'] ?? []);
        $row = $this->restored($result);
        $this->assertNotEmpty($row->scormcmid);
        $this->assertNotEquals(self::SCORM_CMID, (int) $row->scormcmid);
        $cm = $this->db->get_record('course_modules', ['id' => $row->scormcmid]);
        $this->assertSame(9, (int) $cm->course);

        $newscoes = $this->db->get_records('scorm_scoes', ['scorm' => $cm->instance], '', 'identifier');
        $this->assertEquals($newscoes['sco_1']->id, $this->restored_lesson($result, 'L1')->scoid);
        $this->assertEquals($newscoes['sco_2']->id, $this->restored_lesson($result, 'L2')->scoid);
        $this->assertSame(self::SCORM_CMID,
            (int) $this->db->get_record('skilland', ['id' => self::SKILLAND_ID])->scormcmid);
    }

    public function test_duplicate_in_same_course_provisions_its_own_package(): void {
        $this->queue_package();

        $result = $this->restore(['skilland'], self::SOURCE_COURSE, $this->backup_activity());

        $this->assertCount(1, $GLOBALS['_test_create_module_calls'] ?? []);
        $row = $this->restored($result);
        $this->assertNotEquals(self::SCORM_CMID, (int) $row->scormcmid,
            'The duplicate never shares the original SCORM');
        $this->assertTrue($this->db->record_exists('course_modules', ['id' => self::SCORM_CMID]));
        $this->assertSame(self::SCORM_CMID,
            (int) $this->db->get_record('skilland', ['id' => self::SKILLAND_ID])->scormcmid);
    }

    public function test_restore_without_scorm_or_visible_lessons_clears_the_links(): void {
        $backup = $this->backup_activity();
        foreach ($backup['lessons'] as &$lesson) {
            $lesson['visible'] = 0;
        }
        unset($lesson);

        $result = $this->restore(['skilland'], 9, $backup);

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertNull($this->restored($result)->scormcmid);
        $this->assertNull($this->restored_lesson($result, 'L1')->scoid);
        $this->assertNull($this->restored_lesson($result, 'L2')->scoid);
    }

    /**
     * SKL-667: index.php is now a real listing page (was a redirect), reached from a restored
     * course via the SKILLANDINDEX link tag. Untouched by SKL-667, asserted here as a regression
     * guard: a backup that embeds that tag must still decode to index.php, not view.php.
     */
    public function test_skillandindex_decode_rule_still_points_at_index_php(): void {
        $rules = \restore_skilland_activity_task::define_decode_rules();

        $names = array_map(fn($rule) => $rule->linkname, $rules);
        $this->assertContains('SKILLANDINDEX', $names);

        $rule = $rules[array_search('SKILLANDINDEX', $names, true)];
        $this->assertSame('/mod/skilland/index.php?id=$1', $rule->urltemplate);
        $this->assertSame('course', $rule->mappings);
    }
}
