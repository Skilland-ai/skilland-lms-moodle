<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * skilland_delete_instance() deletes the linked topic SCORM (SKL-673).
 */
class lib_delete_instance_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_lock_available', '_test_lock_calls', '_test_events', '_test_deleted_cmids',
        '_test_course_delete_throw', '_test_cm_from_db', '_test_get_coursemodule_from_id',
        '_test_dispatch_observers',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        \mod_skilland\logger::reset_cache();

        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic one', 'scormcmid' => 40,
                'scorm_provisioned' => 1, 'snapshotid' => 'snap', 'snapshotcreatedat' => 100],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'scoid' => 11, 'sco_identifier' => 'sco_1'],
            (object) ['id' => 2, 'skillandid' => 7, 'scoid' => 12, 'sco_identifier' => 'sco_2'],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'idnumber' => 'skilland_topic_7',
                'deletioninprogress' => 0],
        ]);
        $this->db->seed('scorm', [(object) ['id' => 5, 'course' => 3]]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function lock_actions(): array {
        return array_column($GLOBALS['_test_lock_calls'] ?? [], 'action');
    }

    public function test_deletes_linked_scorm_lessons_and_activity(): void {
        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $this->assertEmpty($this->db->get_records('course_modules', ['idnumber' => 'skilland_topic_7']));
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
        $this->assertEmpty($this->db->get_records('skilland_lesson', ['skillandid' => 7]));
        $this->assertSame(['acquire', 'release'], $this->lock_actions());
        $this->assertSame('mod_skilland/provision_7', $GLOBALS['_test_lock_calls'][0]['key']);
    }

    public function test_scorm_already_missing_skips_delete_and_still_deletes_activity(): void {
        $this->db->delete_records('course_modules', ['id' => 40]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
        $this->assertEmpty($this->db->get_records('skilland_lesson', ['skillandid' => 7]));
    }

    public function test_scorm_deletion_in_progress_skips_delete(): void {
        $this->db->set_field('course_modules', 'deletioninprogress', 1, ['id' => 40]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
    }

    public function test_scorm_delete_failure_does_not_block_activity_delete(): void {
        $GLOBALS['_test_course_delete_throw'] = new \moodle_exception('boom');

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
        $this->assertEmpty($this->db->get_records('skilland_lesson', ['skillandid' => 7]));
        $this->assertSame(['acquire', 'release'], $this->lock_actions());
    }

    public function test_lock_busy_still_deletes_scorm_and_logs_warning(): void {
        $GLOBALS['_test_lock_available'] = false;

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
        $this->assertSame(['acquire'], $this->lock_actions());
        $messages = array_column($GLOBALS['_test_debug_messages'], 'message');
        $this->assertNotEmpty(array_filter($messages, fn($m) => strpos($m, 'lock busy') !== false));
    }

    public function test_missing_id_returns_false_and_deletes_nothing(): void {
        $this->assertFalse(skilland_delete_instance(999));

        $this->assertEmpty($this->db->get_calls_for('delete_records'));
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
    }

    public function test_unprovisioned_activity_takes_no_lock(): void {
        $this->db->set_field('skilland', 'scormcmid', null, ['id' => 7]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        // skilland_progress (SKL-668), skilland_lesson, skilland.
        $this->assertCount(3, $this->db->get_calls_for('delete_records'));
    }

    // ---------------------------------------------------------------
    // skilland_get_linked_scorm_cm()
    // ---------------------------------------------------------------

    public function test_linked_scorm_cm_null_for_empty_id(): void {
        $this->assertNull(skilland_get_linked_scorm_cm((object) ['id' => 7, 'scormcmid' => null]));
        $this->assertNull(skilland_get_linked_scorm_cm((object) ['id' => 7]));
    }

    public function test_linked_scorm_cm_null_for_missing_module(): void {
        $this->assertNull(skilland_get_linked_scorm_cm((object) ['id' => 7, 'scormcmid' => 999]));
    }

    public function test_linked_scorm_cm_null_when_deletion_in_progress(): void {
        $this->db->set_field('course_modules', 'deletioninprogress', 1, ['id' => 40]);

        $this->assertNull(skilland_get_linked_scorm_cm((object) ['id' => 7, 'scormcmid' => 40]));
    }

    public function test_linked_scorm_cm_returns_live_module(): void {
        $cm = skilland_get_linked_scorm_cm((object) ['id' => 7, 'scormcmid' => 40]);

        $this->assertNotNull($cm);
        $this->assertSame(40, $cm->id);
    }
}
