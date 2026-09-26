<?php

namespace mod_skilland\tests;

require_once __DIR__ . '/stubs/moodle_stubs.php';
require_once __DIR__ . '/stubs/fake_database.php';
require_once __DIR__ . '/stubs/event_stub.php';
require_once __DIR__ . '/stubs/lock_stub.php';
require_once __DIR__ . '/../../src/locallib.php';
require_once __DIR__ . '/../../src/lib.php';
require_once __DIR__ . '/stubs/view_functions.php';

use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the linked-SCORM lifecycle (SKL-673): lock release on every delete path,
 * delete and observer together, observer guards and skilland_unlink_scorm() on its own.
 */
class scorm_lifecycle_test extends TestCase {

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
        $this->install_db(new \FakeDatabase());
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_cm_from_db'] = true;
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function install_db(\FakeDatabase $db): void {
        $this->db = $db;
        $GLOBALS['DB'] = $db;
        $db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic one', 'scormcmid' => 40,
                'scorm_provisioned' => 1, 'snapshotid' => 'snap7', 'snapshotcreatedat' => 100],
            (object) ['id' => 8, 'course' => 3, 'name' => 'Topic two', 'scormcmid' => 41,
                'scorm_provisioned' => 1, 'snapshotid' => 'snap8', 'snapshotcreatedat' => 200],
        ]);
        $db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'scoid' => 11, 'sco_identifier' => 'sco_1'],
            (object) ['id' => 2, 'skillandid' => 8, 'scoid' => 12, 'sco_identifier' => 'sco_2'],
        ]);
        $db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'modname' => 'scorm',
                'idnumber' => 'skilland_topic_7', 'deletioninprogress' => 0],
            (object) ['id' => 41, 'course' => 3, 'instance' => 6, 'modname' => 'scorm',
                'idnumber' => 'skilland_topic_8', 'deletioninprogress' => 0],
        ]);
        $db->seed('scorm', [(object) ['id' => 5, 'course' => 3], (object) ['id' => 6, 'course' => 3]]);
    }

    private function lock_actions(): array {
        return array_column($GLOBALS['_test_lock_calls'] ?? [], 'action');
    }

    private function event(int $cmid, int $courseid, array $other): \core\event\course_module_deleted {
        return \core\event\course_module_deleted::create([
            'objectid' => $cmid,
            'courseid' => $courseid,
            'contextinstanceid' => $cmid,
            'other' => $other,
        ]);
    }

    // ---------------------------------------------------------------
    // skilland_delete_instance(): lock released on every path
    // ---------------------------------------------------------------

    public function test_lock_released_when_scorm_already_missing(): void {
        $this->db->delete_records('course_modules', ['id' => 40]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame(['acquire', 'release'], $this->lock_actions());
    }

    public function test_lock_released_when_scorm_deletion_in_progress(): void {
        $this->db->set_field('course_modules', 'deletioninprogress', 1, ['id' => 40]);

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame(['acquire', 'release'], $this->lock_actions());
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 40]));
    }

    public function test_lock_released_when_the_scorm_lookup_throws(): void {
        $db = new class extends \FakeDatabase {
            public function get_record(string $table, array $conditions, string $fields = '*', int $strictness = 0) {
                if ($table === 'course_modules') {
                    throw new \dml_exception('db down');
                }
                return parent::get_record($table, $conditions, $fields, $strictness);
            }
        };
        $this->install_db($db);

        try {
            skilland_delete_instance(7);
            $this->fail('The lookup exception should propagate');
        } catch (\dml_exception $e) {
            $this->assertSame(['acquire', 'release'], $this->lock_actions());
        }
    }

    public function test_lock_busy_releases_nothing_and_deletes_lessons_and_activity(): void {
        $GLOBALS['_test_lock_available'] = false;

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame(['acquire'], $this->lock_actions());
        $this->assertEmpty($this->db->get_records('skilland_lesson', ['skillandid' => 7]));
        $this->assertFalse($this->db->get_record('course_modules', ['id' => 40]));
    }

    public function test_delete_leaves_other_activities_and_their_scorm_alone(): void {
        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $this->assertNotFalse($this->db->get_record('course_modules', ['id' => 41]));
        $this->assertSame(41, $this->db->get_record('skilland', ['id' => 8])->scormcmid);
        $this->assertSame(12, $this->db->get_record('skilland_lesson', ['id' => 2])->scoid);
    }

    // ---------------------------------------------------------------
    // Delete and observer together
    // ---------------------------------------------------------------

    public function test_delete_with_observer_dispatched_takes_the_lock_once(): void {
        $GLOBALS['_test_dispatch_observers'] = true;

        $this->assertTrue(skilland_delete_instance(7));

        $this->assertSame(['acquire', 'release'], $this->lock_actions());
        $this->assertSame([40], $GLOBALS['_test_deleted_cmids']);
        $this->assertFalse($this->db->get_record('skilland', ['id' => 7]));
        $this->assertEmpty($this->db->get_records('skilland_lesson', ['skillandid' => 7]));
        $this->assertSame(41, $this->db->get_record('skilland', ['id' => 8])->scormcmid);
    }

    public function test_deleting_the_scorm_directly_unlinks_and_view_sees_unprovisioned(): void {
        $GLOBALS['_test_dispatch_observers'] = true;

        course_delete_module(40);

        $row = $this->db->get_record('skilland', ['id' => 7]);
        $this->assertNull($row->scormcmid);
        $this->assertFalse(skilland_detect_missing_scorm($row));
        $this->assertEmpty($this->lock_actions());
    }

    // ---------------------------------------------------------------
    // Observer guards
    // ---------------------------------------------------------------

    public function test_observer_ignores_event_without_modulename(): void {
        \mod_skilland\observer::course_module_deleted($this->event(40, 3, []));

        $this->assertSame(40, $this->db->get_record('skilland', ['id' => 7])->scormcmid);
        $this->assertEmpty($this->db->get_calls_for('update_record'));
        $this->assertEmpty($this->db->get_calls_for('get_records'));
    }

    public function test_observer_unlinks_every_activity_linked_to_the_cmid_in_the_course(): void {
        $this->db->set_field('skilland', 'scormcmid', 40, ['id' => 8]);

        \mod_skilland\observer::course_module_deleted(
            $this->event(40, 3, ['modulename' => 'scorm', 'instanceid' => 5]));

        $this->assertNull($this->db->get_record('skilland', ['id' => 7])->scormcmid);
        $this->assertNull($this->db->get_record('skilland', ['id' => 8])->scormcmid);
        $this->assertNull($this->db->get_record('skilland_lesson', ['id' => 2])->scoid);
    }

    // ---------------------------------------------------------------
    // skilland_unlink_scorm()
    // ---------------------------------------------------------------

    public function test_unlink_nulls_the_four_fields_and_only_this_activitys_lessons(): void {
        skilland_unlink_scorm(7);

        $row = $this->db->get_record('skilland', ['id' => 7]);
        foreach (['scormcmid', 'scorm_provisioned', 'snapshotid', 'snapshotcreatedat'] as $field) {
            $this->assertNull($row->$field, $field);
        }
        $this->assertSame('Topic one', $row->name);
        $this->assertNull($this->db->get_record('skilland_lesson', ['id' => 1])->scoid);
        $this->assertNull($this->db->get_record('skilland_lesson', ['id' => 1])->sco_identifier);

        $other = $this->db->get_record('skilland', ['id' => 8]);
        $this->assertSame(41, $other->scormcmid);
        $this->assertSame('snap8', $other->snapshotid);
        $this->assertSame('sco_2', $this->db->get_record('skilland_lesson', ['id' => 2])->sco_identifier);
        $this->assertEmpty($this->lock_actions());
    }

    public function test_unlink_is_idempotent(): void {
        skilland_unlink_scorm(7);
        $after = [$this->db->get_records('skilland'), $this->db->get_records('skilland_lesson')];

        skilland_unlink_scorm(7);

        $this->assertEquals($after, [$this->db->get_records('skilland'), $this->db->get_records('skilland_lesson')]);
    }

    // ---------------------------------------------------------------
    // Player view
    // ---------------------------------------------------------------

    public function test_player_with_deleted_scorm_does_not_touch_the_scorm_table(): void {
        $GLOBALS['USER'] = (object) ['id' => 1];
        $this->db->delete_records('course_modules', ['id' => 40]);
        $calls = count($this->db->get_calls());
        $lesson = (object) ['id' => 1, 'title' => 'Lesson', 'scoid' => 11];

        $html = skilland_render_player_view((object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0],
            $lesson, (object) ['id' => 1], [1 => $lesson], 1);

        $this->assertStringContainsString('scorm_not_ready', $html);
        $scormreads = array_filter(array_slice($this->db->get_calls(), $calls), fn($c) => ($c['table'] ?? '') === 'scorm');
        $this->assertEmpty($scormreads);
    }
}
