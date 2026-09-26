<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Atomic, idempotent topic SCORM provisioning through core module APIs (SKL-663).
 */
class locallib_provision_scorm_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    /** @var string[] Temp zips written by a test, removed in tearDown. */
    private $zips = [];

    private const GLOBALS_TO_RESET = [
        '_test_curl_response', '_test_curl_responses', '_test_curl_last', '_test_curl_requests',
        '_test_lock_available', '_test_lock_calls', '_test_create_module_calls', '_test_create_module_throw',
        '_test_create_module_visibleoncoursepage', '_test_scorm_scoes', '_test_events', '_test_deleted_cmids',
        '_test_course_delete_throw', '_test_set_visible_calls', '_test_stored_files', '_test_cm_from_db',
        '_test_get_coursemodule_from_id', '_test_update_topic_scorm', '_test_create_module_throw_after_insert',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['USER'] = (object) ['id' => 2];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        $GLOBALS['_test_scorm_scoes'] = [
            ['identifier' => 'org', 'launch' => ''],
            ['identifier' => 'sco_1', 'launch' => 'l1.html'],
            ['identifier' => 'sco_2', 'launch' => 'l2.html'],
        ];
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        \mod_skilland\logger::reset_cache();

        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://api.skilland.ai/graphql',
        ];

        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic one', 'skilland_topicid' => 'topic1',
                'scormcmid' => null, 'scorm_provisioned' => null, 'snapshotcreatedat' => null],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'skilland_lessonid' => 'L1', 'visible' => 1,
                'scoid' => null, 'sco_identifier' => null],
            (object) ['id' => 2, 'skillandid' => 7, 'skilland_lessonid' => 'L2', 'visible' => 1,
                'scoid' => null, 'sco_identifier' => null],
            (object) ['id' => 3, 'skillandid' => 7, 'skilland_lessonid' => 'L3', 'visible' => 0,
                'scoid' => null, 'sco_identifier' => null],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        foreach ($this->zips as $zip) {
            @unlink($zip);
        }
        parent::tearDown();
    }

    /** A tiny valid zip holding imsmanifest.xml. */
    private function zipbytes(): string {
        return base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
    }

    private function tempzip(): string {
        $path = tempnam(sys_get_temp_dir(), 'skltest');
        file_put_contents($path, $this->zipbytes());
        $this->zips[] = $path;
        return $path;
    }

    private function tempfiles(): array {
        return glob(make_temp_directory('skilland_scorm') . '/skl*') ?: [];
    }

    private function response(array $data): array {
        return ['body' => json_encode(['data' => $data]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    /** Queue the topicScorm GraphQL answer and the package download. */
    private function queue_package(array $mappings = ['L1' => 'sco_1', 'L2' => 'sco_2'], string $hash = '',
            ?int $size = null): void {
        $list = [];
        foreach ($mappings as $lessonid => $scoid) {
            $list[] = ['lessonId' => $lessonid, 'scoId' => $scoid];
        }
        $GLOBALS['_test_curl_responses'][] = $this->response(['topicScorm' => [
            'packageUrl' => 'https://cdn.skilland.ai/topic1.zip',
            'packageSize' => $size ?? strlen($this->zipbytes()),
            'packageHash' => $hash,
            'generatedAt' => '2026-01-02T00:00:00Z',
            'expiresAt' => '',
            'mappings' => $list,
        ]]);
        $GLOBALS['_test_curl_responses'][] = ['body' => $this->zipbytes(), 'http_code' => 200, 'errno' => 0,
            'error' => ''];
    }

    private function skilland(): \stdClass {
        return $this->db->get_record('skilland', ['id' => 7]);
    }

    private function course(): \stdClass {
        return (object) ['id' => 3];
    }

    private function lesson(int $id): \stdClass {
        return $this->db->get_record('skilland_lesson', ['id' => $id]);
    }

    private function lockcalls(string $action): array {
        return array_values(array_filter($GLOBALS['_test_lock_calls'] ?? [], fn($c) => $c['action'] === $action));
    }

    private function expect_code(callable $fn, string $code): \moodle_exception {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            return $e;
        }
        $this->fail('Expected moodle_exception ' . $code);
    }

    private function assert_rolled_back(): void {
        $row = $this->skilland();
        $this->assertEmpty($row->scormcmid);
        $this->assertEmpty($row->scorm_provisioned);
        foreach ([1, 2, 3] as $id) {
            $this->assertNull($this->lesson($id)->scoid);
            $this->assertNull($this->lesson($id)->sco_identifier);
        }
        $this->assertEmpty($this->db->get_records('course_modules'));
    }

    // ---------------------------------------------------------------
    // Idempotency and locking
    // ---------------------------------------------------------------

    public function test_provision_twice_creates_one_module_and_returns_same_cmid(): void {
        $this->queue_package();
        $skilland = $this->skilland();

        $first = skilland_provision_topic_scorm($skilland, $this->course(), 2);
        $second = skilland_provision_topic_scorm($this->skilland(), $this->course(), 2);

        $this->assertSame($first, $second);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertCount(1, $GLOBALS['_test_events']);

        $row = $this->skilland();
        $this->assertSame($first, (int) $row->scormcmid);
        $this->assertNotEmpty($row->scorm_provisioned);
        $this->assertSame(strtotime('2026-01-02T00:00:00Z'), $row->snapshotcreatedat);
        $this->assertSame($first, $skilland->scormcmid, 'Provisioning fields are mirrored onto the caller');

        $scoes = $this->db->get_records('scorm_scoes', [], '', 'identifier');
        $this->assertEquals($scoes['sco_1']->id, $this->lesson(1)->scoid);
        $this->assertSame('sco_1', $this->lesson(1)->sco_identifier);
        $this->assertEquals($scoes['sco_2']->id, $this->lesson(2)->scoid);
        $this->assertNull($this->lesson(3)->scoid, 'Hidden lessons are not mapped');

        $acquires = $this->lockcalls('acquire');
        $this->assertCount(2, $acquires);
        $this->assertSame('mod_skilland/provision_7', $acquires[0]['key']);
        $this->assertSame(10, $acquires[0]['timeout']);
        $this->assertCount(2, $this->lockcalls('release'));
    }

    public function test_lock_unavailable_throws_without_creating_anything(): void {
        $GLOBALS['_test_lock_available'] = false;
        $this->queue_package();

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_provision_in_progress');

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? []);
        $this->assertEmpty($this->lockcalls('release'));
    }

    public function test_lock_is_released_after_a_failure(): void {
        $this->queue_package();
        $GLOBALS['_test_create_module_throw'] = new \moodle_exception('boom', 'mod_skilland');

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0), 'boom');

        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_scormcmid_pointing_at_a_deleted_module_reprovisions(): void {
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 999, 'scorm_provisioned' => 1]));
        $this->db->set_field('skilland_lesson', 'scoid', 555, ['skillandid' => 7]);
        $this->db->set_field('skilland_lesson', 'sco_identifier', 'stale', ['skillandid' => 7]);
        $this->queue_package();

        $cmid = skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $this->assertNotSame(999, $cmid);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertSame($cmid, (int) $this->skilland()->scormcmid);
        $this->assertSame('sco_1', $this->lesson(1)->sco_identifier);
        $this->assertNull($this->lesson(3)->scoid, 'Stale mapping of an unmapped lesson is cleared');
    }

    // ---------------------------------------------------------------
    // Compensating cleanup
    // ---------------------------------------------------------------

    public function test_create_module_failure_writes_nothing_and_removes_temp_file(): void {
        $before = $this->tempfiles();
        $this->queue_package();
        $GLOBALS['_test_create_module_throw'] = new \RuntimeException('create failed');

        try {
            skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);
            $this->fail('Expected create_module failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('create failed', $e->getMessage());
        }

        $skillandwrites = array_filter($this->db->get_calls_for('update_record'), fn($c) => $c['table'] === 'skilland');
        $this->assertEmpty($skillandwrites);
        $this->assertEmpty($this->skilland()->scormcmid);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_no_launchable_sco_rolls_back(): void {
        $before = $this->tempfiles();
        $GLOBALS['_test_scorm_scoes'] = [['identifier' => 'org', 'launch' => '']];
        $this->queue_package();

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_parse_failed');

        $this->assertCount(1, $GLOBALS['_test_deleted_cmids']);
        $this->assertSame(1, $GLOBALS['_test_deleted_cmids'][0]);
        $this->assert_rolled_back();
        $this->assertSame($before, $this->tempfiles());
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_mapped_lesson_with_missing_identifier_rolls_back(): void {
        $this->queue_package(['L1' => 'sco_1', 'L2' => 'sco_missing']);

        $e = $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_parse_failed');

        $this->assertStringContainsString('sco_missing', (string) $e->a);
        $this->assertSame([1], $GLOBALS['_test_deleted_cmids']);
        $this->assert_rolled_back();
        $lessonwrites = array_filter($this->db->get_calls_for('set_field'),
            fn($c) => $c['table'] === 'skilland_lesson' && $c['value'] !== null);
        $this->assertEmpty($lessonwrites, 'No lesson is mapped before every check has passed');
    }

    public function test_hash_mismatch_removes_temp_file(): void {
        $before = $this->tempfiles();
        $this->queue_package(['L1' => 'sco_1'], 'sha256:deadbeef');

        $this->expect_code(fn() => skilland_download_topic_scorm_package('topic1'), 'error_scorm_hash_mismatch');

        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_returns_path_and_info_on_matching_hash(): void {
        $this->queue_package(['L1' => 'sco_1'], 'sha256:' . hash('sha256', $this->zipbytes()));

        $package = skilland_download_topic_scorm_package('topic1');
        $this->zips[] = $package['path'];

        $this->assertFileExists($package['path']);
        $this->assertSame(['L1' => 'sco_1'], $package['info']['mappings']);
    }

    public function test_delete_scorm_module_swallows_its_own_failure(): void {
        $this->db->seed('course_modules', [(object) ['id' => 40, 'instance' => 0]]);
        $GLOBALS['_test_course_delete_throw'] = new \RuntimeException('cannot delete');

        skilland_delete_scorm_module(40);

        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $messages = array_column($GLOBALS['_test_debug_messages'], 'message');
        $this->assertNotEmpty(array_filter($messages, fn($m) => str_contains($m, 'cannot delete')));
    }

    public function test_delete_scorm_module_skips_a_missing_module(): void {
        skilland_delete_scorm_module(41);

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
    }

    // ---------------------------------------------------------------
    // Core module API usage
    // ---------------------------------------------------------------

    public function test_module_name_is_capped_at_255_multibyte_characters(): void {
        $name = skilland_scorm_module_name(str_repeat('é', 255));

        $this->assertLessThanOrEqual(255, mb_strlen($name, 'UTF-8'));
        $this->assertSame(255, mb_strlen($name, 'UTF-8'));
        $this->assertStringEndsWith(' (SCORM)', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertSame('Short (SCORM)', skilland_scorm_module_name('Short'));
    }

    public function test_create_module_receives_a_stealth_scorm_with_a_draft_package(): void {
        $skilland = $this->skilland();
        $skilland->name = str_repeat('á', 300);

        $cmid = skilland_create_topic_scorm_module($skilland, $this->course(), 4, $this->tempzip());

        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $info = $GLOBALS['_test_create_module_calls'][0];
        $this->assertSame('scorm', $info->modulename);
        $this->assertSame(3, $info->course);
        $this->assertSame(4, $info->section);
        $this->assertSame(1, $info->visible);
        $this->assertSame(0, $info->visibleoncoursepage);
        $this->assertSame('skilland_topic_7', $info->idnumber);
        $this->assertSame(SCORM_TYPE_LOCAL, $info->scormtype);
        // SKL-668: the SkilLand activity owns the grade; the hidden SCORM has no grade item.
        $this->assertSame(GRADEHIGHEST, $info->grademethod);
        $this->assertSame(0, $info->maxgrade);
        $this->assertLessThanOrEqual(255, mb_strlen($info->name, 'UTF-8'));
        $this->assertStringEndsWith(' (SCORM)', $info->name);

        $this->assertCount(1, $GLOBALS['_test_stored_files']);
        $stored = $GLOBALS['_test_stored_files'][0];
        $this->assertSame($info->packagefile, $stored['itemid']);
        $this->assertSame('user', $stored['component']);
        $this->assertSame('draft', $stored['filearea']);
        $this->assertSame(\context_user::instance(2)->id, $stored['contextid']);
        $this->assertTrue($stored['exists']);

        $this->assertSame(1, $cmid);
        $this->assertEmpty($GLOBALS['_test_set_visible_calls'] ?? [], 'Stealth already held, nothing to force');
    }

    public function test_stealth_is_forced_when_create_module_shows_the_module(): void {
        $GLOBALS['_test_create_module_visibleoncoursepage'] = 1;

        $cmid = skilland_create_topic_scorm_module($this->skilland(), $this->course(), 0, $this->tempzip());

        $this->assertSame([[$cmid, 1, 0]], $GLOBALS['_test_set_visible_calls']);
        $this->assertEquals(0, $this->db->get_field('course_modules', 'visibleoncoursepage', ['id' => $cmid]));
    }

    public function test_map_topic_scos_writes_only_sco_fields(): void {
        $this->db->seed('scorm_scoes', [
            (object) ['id' => 11, 'scorm' => 5, 'identifier' => 'sco_1', 'launch' => 'a.html'],
            (object) ['id' => 12, 'scorm' => 5, 'identifier' => 'sco_2', 'launch' => 'b.html'],
        ]);

        skilland_map_topic_scos($this->skilland(), 5, ['L1' => 'sco_1', 'L2' => 'sco_2']);

        $this->assertEquals(11, $this->lesson(1)->scoid);
        $this->assertEquals(12, $this->lesson(2)->scoid);
        $lessonupdates = array_filter($this->db->get_calls_for('update_record'),
            fn($c) => $c['table'] === 'skilland_lesson');
        $this->assertEmpty($lessonupdates);
        $fields = array_unique(array_column(array_filter($this->db->get_calls_for('set_field'),
            fn($c) => $c['table'] === 'skilland_lesson'), 'field'));
        sort($fields);
        $this->assertSame(['sco_identifier', 'scoid'], $fields);
    }

    // ---------------------------------------------------------------
    // Update path
    // ---------------------------------------------------------------

    public function test_update_takes_lock_once_and_replaces_the_module(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->seed('scorm', [(object) ['id' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 50, 'scorm_provisioned' => 1]));
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => [['id' => 'L1', 'name' => 'One', 'updatedAt' => '2026-01-03T00:00:00Z']]]]);
        $this->queue_package();
        $skilland = $this->skilland();

        $newcmid = skilland_update_topic_scorm($skilland, $this->course(), 1);

        $this->assertCount(1, $this->lockcalls('acquire'), 'The update never re-enters the locking wrapper');
        $this->assertCount(1, $this->lockcalls('release'));
        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertNotSame(50, $newcmid);
        $this->assertSame($newcmid, (int) $this->skilland()->scormcmid);
        $this->assertSame($newcmid, $skilland->scormcmid);
        $this->assertSame(strtotime('2026-01-03T00:00:00Z'), $this->lesson(1)->updatedat);
        $this->assertSame('sco_1', $this->lesson(1)->sco_identifier);
        $this->assertFalse($this->db->get_record('course_modules', ['id' => 50]));
    }

    /** A provisioned activity whose lessons carry the packaged stamp 1600000000 (SKL-683). */
    private function provisioned_with_stamps(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->seed('scorm', [(object) ['id' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 50, 'scorm_provisioned' => 1]));
        foreach ([1, 2, 3] as $id) {
            $this->db->set_field('skilland_lesson', 'updatedat', 1600000000, ['id' => $id]);
        }
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => [
                ['id' => 'L1', 'name' => 'One', 'updatedAt' => '2026-01-03T00:00:00Z'],
                ['id' => 'L2', 'name' => 'Two', 'updatedAt' => '2026-01-04T00:00:00Z'],
                ['id' => 'L3', 'name' => 'Three', 'updatedAt' => '2026-01-05T00:00:00Z'],
            ]]]);
    }

    public function test_successful_update_stamps_visible_and_hidden_lessons_after_the_build(): void {
        $this->provisioned_with_stamps();
        $this->queue_package();

        skilland_update_topic_scorm($this->skilland(), $this->course(), 1);

        $this->assertSame(strtotime('2026-01-03T00:00:00Z'), $this->lesson(1)->updatedat);
        $this->assertSame(strtotime('2026-01-04T00:00:00Z'), $this->lesson(2)->updatedat);
        $this->assertSame(strtotime('2026-01-05T00:00:00Z'), $this->lesson(3)->updatedat);

        // The stamps are written only once the new module is linked to the activity.
        $firststamp = null;
        $linked = null;
        foreach ($this->db->get_calls() as $i => $call) {
            if ($firststamp === null && $call['method'] === 'set_field' && ($call['field'] ?? '') === 'updatedat'
                    && $i > 0 && $call['value'] !== 1600000000) {
                $firststamp = $i;
            }
            if ($call['method'] === 'update_record' && $call['table'] === 'skilland'
                    && !empty($call['data']->scormcmid) && (int) $call['data']->scormcmid !== 50) {
                $linked = $i;
            }
        }
        $this->assertNotNull($firststamp);
        $this->assertNotNull($linked);
        $this->assertGreaterThan($linked, $firststamp);
    }

    public function test_update_whose_build_fails_leaves_the_stamps_alone(): void {
        $this->provisioned_with_stamps();
        $this->queue_package(['L1' => 'sco_missing']);

        $this->expect_code(fn() => skilland_update_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_parse_failed');

        foreach ([1, 2, 3] as $id) {
            $this->assertSame(1600000000, $this->lesson($id)->updatedat);
        }
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_update_whose_module_creation_throws_leaves_the_stamps_alone(): void {
        $this->provisioned_with_stamps();
        $GLOBALS['_test_create_module_throw_after_insert'] = new \RuntimeException('half way');
        $this->queue_package();

        try {
            skilland_update_topic_scorm($this->skilland(), $this->course(), 0);
            $this->fail('Expected create_module failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('half way', $e->getMessage());
        }

        foreach ([1, 2, 3] as $id) {
            $this->assertSame(1600000000, $this->lesson($id)->updatedat);
        }
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_update_throws_when_lock_is_busy(): void {
        $GLOBALS['_test_lock_available'] = false;

        $this->expect_code(fn() => skilland_update_topic_scorm($this->skilland(), $this->course(), 0),
            'error_provision_in_progress');

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
    }

    // ---------------------------------------------------------------
    // Audit coverage (SKL-663)
    // ---------------------------------------------------------------

    public function test_provision_with_255_char_accented_name_succeeds(): void {
        $this->db->set_field('skilland', 'name', str_repeat('ñ', 255), ['id' => 7]);
        $this->queue_package();

        $cmid = skilland_provision_topic_scorm($this->skilland(), $this->course(), 1);

        $name = $GLOBALS['_test_create_module_calls'][0]->name;
        $this->assertLessThanOrEqual(255, mb_strlen($name, 'UTF-8'));
        $this->assertStringEndsWith(' (SCORM)', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
        $this->assertSame($cmid, (int) $this->skilland()->scormcmid);
        $this->assertSame('sco_2', $this->lesson(2)->sco_identifier);
    }

    public function test_section_defaults_to_zero_and_moduleinfo_carries_the_package(): void {
        $this->queue_package();

        skilland_provision_topic_scorm($this->skilland(), $this->course());

        $info = $GLOBALS['_test_create_module_calls'][0];
        $this->assertSame('scorm', $info->modulename);
        $this->assertSame(0, $info->section);
        $this->assertSame(0, $info->visibleoncoursepage);
        $this->assertSame('skilland_topic_7', $info->idnumber);
        $this->assertSame('skilland_topic_7', $info->cmidnumber);
        $this->assertIsInt($info->packagefile);
        $this->assertSame($info->packagefile, $GLOBALS['_test_stored_files'][0]['itemid']);
        $this->assertTrue($GLOBALS['_test_stored_files'][0]['exists'], 'The zip is staged before it is deleted');
    }

    public function test_temp_file_is_removed_after_a_successful_provision(): void {
        $before = $this->tempfiles();
        $this->queue_package();

        skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $this->assertSame($before, $this->tempfiles());
        $this->assertFileDoesNotExist($GLOBALS['_test_stored_files'][0]['pathname']);
    }

    public function test_provisioning_fields_are_written_after_the_lessons_are_mapped(): void {
        $this->queue_package();

        skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $order = [];
        foreach ($this->db->get_calls() as $i => $call) {
            if (($call['table'] ?? '') === 'skilland_lesson' && $call['method'] === 'set_field') {
                $order['lesson'] = $i;
            }
            if (($call['table'] ?? '') === 'skilland' && $call['method'] === 'update_record'
                    && !empty($call['data']->scormcmid)) {
                $order['skilland'] ??= $i;
            }
        }
        $this->assertArrayHasKey('skilland', $order);
        $this->assertGreaterThan($order['lesson'], $order['skilland']);
    }

    public function test_activity_without_lessons_provisions(): void {
        $this->db->delete_records('skilland_lesson', ['skillandid' => 7]);
        $this->queue_package();

        $cmid = skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $this->assertSame($cmid, (int) $this->skilland()->scormcmid);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_db_row_wins_over_a_stale_caller_record_that_looks_unprovisioned(): void {
        $this->queue_package();
        $stale = $this->skilland();
        $first = skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);
        $requests = count($GLOBALS['_test_curl_requests']);

        $this->assertEmpty($stale->scormcmid);
        $second = skilland_provision_topic_scorm($stale, $this->course(), 0);

        $this->assertSame($first, $second);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertCount($requests, $GLOBALS['_test_curl_requests'], 'The idempotent path downloads nothing');
        $this->assertEquals($first, $stale->scormcmid, 'The caller record is refreshed from the DB');
    }

    public function test_db_row_wins_over_a_stale_caller_record_pointing_at_a_live_module(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $stale = $this->skilland();
        $stale->scormcmid = 50;
        $this->queue_package();

        $cmid = skilland_provision_topic_scorm($stale, $this->course(), 0);

        $this->assertNotSame(50, $cmid);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 50]), 'Another module is not deleted');
        $this->assertSame($cmid, (int) $this->skilland()->scormcmid);
        $this->assertSame($cmid, $stale->scormcmid);
    }

    public function test_deleted_module_reprovision_overwrites_the_provisioning_fields(): void {
        $this->db->update_record('skilland', (object) ['id' => 7, 'scormcmid' => 999, 'scorm_provisioned' => 1,
            'snapshotcreatedat' => 1]);
        $this->queue_package();

        $cmid = skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $row = $this->skilland();
        $this->assertSame($cmid, (int) $row->scormcmid);
        $this->assertGreaterThan(1, (int) $row->scorm_provisioned);
        $this->assertSame(strtotime('2026-01-02T00:00:00Z'), $row->snapshotcreatedat);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? [], 'A missing module is not deleted again');
    }

    public function test_hash_mismatch_during_provision_creates_no_module(): void {
        $before = $this->tempfiles();
        $this->queue_package(['L1' => 'sco_1'], 'sha256:deadbeef');

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_hash_mismatch');

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_stored_files'] ?? []);
        $this->assertSame($before, $this->tempfiles());
        $this->assertEmpty($this->skilland()->scormcmid);
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_unknown_hash_algorithm_fails_closed(): void {
        $before = $this->tempfiles();
        $this->queue_package(['L1' => 'sco_1'], 'nosuchalgo:' . hash('sha256', $this->zipbytes()));

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_hash_mismatch');

        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_unprefixed_hash_is_verified_as_sha256(): void {
        $this->queue_package(['L1' => 'sco_1'], strtoupper(hash('sha256', $this->zipbytes())));

        $package = skilland_download_topic_scorm_package('topic1');
        $this->zips[] = $package['path'];

        $this->assertFileExists($package['path']);
    }

    public function test_missing_package_url_fails_before_downloading(): void {
        $GLOBALS['_test_curl_responses'][] = $this->response(['topicScorm' => ['packageUrl' => '',
            'mappings' => []]]);

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_not_available');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_half_created_module_is_deleted_but_an_older_one_is_kept(): void {
        $this->db->seed('course_modules', [(object) ['id' => 70, 'instance' => 0, 'course' => 3,
            'idnumber' => 'skilland_topic_7']]);
        $GLOBALS['_test_create_module_throw_after_insert'] = new \RuntimeException('half way');
        $before = $this->tempfiles();
        $this->queue_package();

        try {
            skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);
            $this->fail('Expected create_module failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('half way', $e->getMessage());
        }

        $this->assertSame([71], $GLOBALS['_test_deleted_cmids']);
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 70]));
        $this->assertFalse($this->db->get_record('course_modules', ['id' => 71]));
        $this->assertEmpty($this->skilland()->scormcmid);
        $this->assertSame($before, $this->tempfiles());
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_rollback_leaves_a_previous_mapping_of_the_caller_record_unset(): void {
        $this->queue_package(['L1' => 'sco_missing']);
        $skilland = $this->skilland();

        $this->expect_code(fn() => skilland_provision_topic_scorm($skilland, $this->course(), 0),
            'error_scorm_parse_failed');

        $this->assertEmpty($skilland->scormcmid);
        $this->assertEmpty($skilland->scorm_provisioned);
        $this->assertEmpty($this->db->get_records('scorm'), 'The scorm instance goes with its module');
        $this->assertEmpty($this->db->get_records('scorm_scoes'));
    }

    public function test_update_while_lock_busy_keeps_the_old_module(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) ['id' => 7, 'scormcmid' => 50, 'scorm_provisioned' => 1]);
        $this->db->set_field('skilland_lesson', 'scoid', 555, ['id' => 1]);
        $GLOBALS['_test_lock_available'] = false;

        $this->expect_code(fn() => skilland_update_topic_scorm($this->skilland(), $this->course(), 0),
            'error_provision_in_progress');

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 50]));
        $this->assertEquals(50, $this->skilland()->scormcmid);
        $this->assertEquals(555, $this->lesson(1)->scoid);
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? []);
        $this->assertEmpty($this->lockcalls('release'));
    }

    public function test_update_without_an_existing_module_just_provisions(): void {
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => []]]);
        $this->queue_package();

        $cmid = skilland_update_topic_scorm($this->skilland(), $this->course());

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertSame(0, $GLOBALS['_test_create_module_calls'][0]->section);
        $this->assertSame($cmid, (int) $this->skilland()->scormcmid);
        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_update_uses_the_db_scormcmid_not_the_callers(): void {
        $this->db->seed('course_modules', [
            (object) ['id' => 50, 'instance' => 60, 'course' => 3],
            (object) ['id' => 51, 'instance' => 61, 'course' => 3],
        ]);
        $this->db->update_record('skilland', (object) ['id' => 7, 'scormcmid' => 50, 'scorm_provisioned' => 1]);
        $stale = $this->skilland();
        $stale->scormcmid = 51;
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => []]]);
        $this->queue_package();

        skilland_update_topic_scorm($stale, $this->course(), 0);

        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 51]));
    }

    public function test_update_failure_after_the_old_module_is_gone_rolls_back_and_releases(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) ['id' => 7, 'scormcmid' => 50, 'scorm_provisioned' => 1]);
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => []]]);
        $this->queue_package(['L1' => 'sco_missing']);
        $skilland = $this->skilland();

        $this->expect_code(fn() => skilland_update_topic_scorm($skilland, $this->course(), 0),
            'error_scorm_parse_failed');

        $this->assertCount(2, $GLOBALS['_test_deleted_cmids'], 'Old module, then the new one on rollback');
        $this->assertSame(50, $GLOBALS['_test_deleted_cmids'][0]);
        $this->assert_rolled_back();
        $this->assertEmpty($skilland->scormcmid);
        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_ajax_provision_twice_returns_the_existing_cmid(): void {
        $this->db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $this->db->seed('course_modules', [(object) ['id' => 90, 'instance' => 7, 'course' => 3, 'section' => 12]]);
        $this->db->seed('course_sections', [(object) ['id' => 12, 'section' => 2, 'course' => 3]]);
        $this->queue_package();

        $first = \mod_skilland\external\provision_topic_scorm::execute(7, 90);
        $second = \mod_skilland\external\provision_topic_scorm::execute(7, 90);

        $this->assertTrue($first['success'], (string) ($first['error'] ?? ''));
        $this->assertTrue($second['success']);
        $this->assertSame($first['scormcmid'], $second['scormcmid']);
        $this->assertNotSame(90, $first['scormcmid']);
        $this->assertCount(1, $GLOBALS['_test_create_module_calls']);
        $this->assertEquals(2, $GLOBALS['_test_create_module_calls'][0]->section);
    }

    public function test_ajax_reports_a_busy_lock_as_a_failure(): void {
        $this->db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $this->db->seed('course_modules', [(object) ['id' => 90, 'instance' => 7, 'course' => 3, 'section' => 12]]);
        $this->db->seed('course_sections', [(object) ['id' => 12, 'section' => 2, 'course' => 3]]);
        $GLOBALS['_test_lock_available'] = false;

        $result = \mod_skilland\external\provision_topic_scorm::execute(7, 90);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['scormcmid']);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
    }

    // ---------------------------------------------------------------
    // Stored lesson -> SCO map (SKL-655)
    // ---------------------------------------------------------------

    public function test_provisioning_stores_the_full_api_mapping(): void {
        $this->queue_package(['L1' => 'sco_1', 'L2' => 'sco_2', 'L3' => 'sco_2']);
        $skilland = $this->skilland();

        skilland_provision_topic_scorm($skilland, $this->course(), 0);

        $expected = ['L1' => 'sco_1', 'L2' => 'sco_2', 'L3' => 'sco_2'];
        $this->assertSame($expected, json_decode($this->skilland()->scomappings, true));
        $this->assertSame($expected, json_decode($skilland->scomappings, true));
        $this->assertNull($this->lesson(3)->scoid, 'Hidden lessons are still mapped only on demand');
    }

    public function test_scomappings_is_written_with_the_provisioning_fields(): void {
        $this->queue_package();

        skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $writes = array_values(array_filter($this->db->get_calls_for('update_record'),
            fn($c) => $c['table'] === 'skilland' && !empty($c['data']->scormcmid)));
        $this->assertCount(1, $writes);
        $this->assertObjectHasProperty('scomappings', $writes[0]['data']);
    }

    public function test_failed_provision_writes_no_scomappings(): void {
        $this->queue_package(['L1' => 'missing_sco']);

        $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_parse_failed');

        $this->assertEmpty($this->skilland()->scomappings ?? null);
    }

    public function test_update_clears_the_old_mapping_before_writing_the_new_one(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->seed('scorm', [(object) ['id' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 50, 'scorm_provisioned' => 1, 'scomappings' => json_encode(['L1' => 'old'])]));
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => []]]);
        $this->queue_package(['L1' => 'sco_1']);

        skilland_update_topic_scorm($this->skilland(), $this->course(), 0);

        $clears = array_values(array_filter($this->db->get_calls_for('update_record'),
            fn($c) => $c['table'] === 'skilland' && property_exists($c['data'], 'scormcmid')
                && $c['data']->scormcmid === null));
        $this->assertCount(1, $clears);
        $this->assertTrue(property_exists($clears[0]['data'], 'scomappings'));
        $this->assertNull($clears[0]['data']->scomappings);
        $this->assertSame(['L1' => 'sco_1'], json_decode($this->skilland()->scomappings, true));
    }

    public function test_update_failure_leaves_scomappings_cleared(): void {
        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3]]);
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 50, 'scorm_provisioned' => 1, 'scomappings' => json_encode(['L1' => 'old'])]));
        $GLOBALS['_test_curl_responses'][] = $this->response(['topic' => ['id' => 'topic1', 'name' => 'T',
            'lessons' => []]]);
        $GLOBALS['_test_curl_responses'][] = ['body' => 'down', 'http_code' => 500, 'errno' => 0, 'error' => ''];

        try {
            skilland_update_topic_scorm($this->skilland(), $this->course(), 0);
            $this->fail('Expected the update to fail');
        } catch (\moodle_exception $e) {
            $this->assertNotEmpty($e->errorcode);
        }

        $this->assertNull($this->skilland()->scomappings);
        $this->assertNull($this->skilland()->scormcmid);
    }

    public function test_missing_module_reprovision_clears_the_old_mapping(): void {
        $this->db->update_record('skilland', (object) array_merge((array) $this->skilland(),
            ['scormcmid' => 999, 'scorm_provisioned' => 1, 'scomappings' => json_encode(['L1' => 'old'])]));
        $this->queue_package(['L1' => 'sco_1']);

        skilland_provision_topic_scorm($this->skilland(), $this->course(), 0);

        $clears = array_values(array_filter($this->db->get_calls_for('update_record'),
            fn($c) => $c['table'] === 'skilland' && property_exists($c['data'], 'scormcmid')
                && $c['data']->scormcmid === null));
        $this->assertCount(1, $clears);
        $this->assertNull($clears[0]['data']->scomappings);
        $this->assertSame(['L1' => 'sco_1'], json_decode($this->skilland()->scomappings, true));
    }

    public function test_package_size_mismatch_creates_no_module_and_removes_temp_file(): void {
        $before = $this->tempfiles();
        $this->queue_package(['L1' => 'sco_1', 'L2' => 'sco_2'], '', strlen($this->zipbytes()) + 1);

        $e = $this->expect_code(fn() => skilland_provision_topic_scorm($this->skilland(), $this->course(), 0),
            'error_scorm_download_failed');

        $this->assertSame('Size mismatch', $e->a);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($this->skilland()->scormcmid);
        $this->assertSame($before, $this->tempfiles());
    }
}
