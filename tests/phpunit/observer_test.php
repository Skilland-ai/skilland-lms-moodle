<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * \mod_skilland\observer::course_module_deleted() unlinks a deleted topic SCORM (SKL-673).
 */
class observer_test extends TestCase {

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
        \mod_skilland\logger::reset_cache();

        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'scormcmid' => 40, 'scorm_provisioned' => 1,
                'snapshotid' => 'snap7', 'snapshotcreatedat' => 100, 'name' => 'Linked'],
            (object) ['id' => 8, 'course' => 3, 'scormcmid' => 41, 'scorm_provisioned' => 1,
                'snapshotid' => 'snap8', 'snapshotcreatedat' => 200, 'name' => 'Other'],
            (object) ['id' => 9, 'course' => 4, 'scormcmid' => 40, 'scorm_provisioned' => 1,
                'snapshotid' => 'snap9', 'snapshotcreatedat' => 300, 'name' => 'Other course'],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'scoid' => 11, 'sco_identifier' => 'sco_1'],
            (object) ['id' => 2, 'skillandid' => 7, 'scoid' => 12, 'sco_identifier' => 'sco_2'],
            (object) ['id' => 3, 'skillandid' => 8, 'scoid' => 13, 'sco_identifier' => 'sco_3'],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function event(int $cmid, int $courseid, string $modulename = 'scorm'): \core\event\course_module_deleted {
        return \core\event\course_module_deleted::create([
            'objectid' => $cmid,
            'courseid' => $courseid,
            'contextinstanceid' => $cmid,
            'other' => ['modulename' => $modulename, 'instanceid' => 5],
        ]);
    }

    private function snapshot(): array {
        return [
            'skilland' => $this->db->get_records('skilland'),
            'skilland_lesson' => $this->db->get_records('skilland_lesson'),
        ];
    }

    public function test_linked_scorm_deleted_unlinks_activity_and_lessons(): void {
        \mod_skilland\observer::course_module_deleted($this->event(40, 3));

        $row = $this->db->get_record('skilland', ['id' => 7]);
        $this->assertNull($row->scormcmid);
        $this->assertNull($row->scorm_provisioned);
        $this->assertNull($row->snapshotid);
        $this->assertNull($row->snapshotcreatedat);
        $this->assertSame('Linked', $row->name);
        foreach ([1, 2] as $lessonid) {
            $lesson = $this->db->get_record('skilland_lesson', ['id' => $lessonid]);
            $this->assertNull($lesson->scoid);
            $this->assertNull($lesson->sco_identifier);
        }

        // Only the activity of the event's course is touched.
        $this->assertSame(41, $this->db->get_record('skilland', ['id' => 8])->scormcmid);
        $this->assertSame(40, $this->db->get_record('skilland', ['id' => 9])->scormcmid);
        $this->assertSame(13, $this->db->get_record('skilland_lesson', ['id' => 3])->scoid);
    }

    public function test_observer_never_takes_the_provision_lock(): void {
        $GLOBALS['_test_lock_calls'] = [];

        \mod_skilland\observer::course_module_deleted($this->event(40, 3));

        $acquires = array_filter($GLOBALS['_test_lock_calls'], fn($c) => $c['action'] === 'acquire');
        $this->assertEmpty($acquires);
    }

    public function test_unlinked_scorm_touches_nothing(): void {
        $before = $this->snapshot();

        \mod_skilland\observer::course_module_deleted($this->event(99, 3));

        $this->assertEquals($before, $this->snapshot());
        $this->assertEmpty($this->db->get_calls_for('update_record'));
        $this->assertEmpty($this->db->get_calls_for('set_field'));
    }

    public function test_non_scorm_module_is_ignored(): void {
        $before = $this->snapshot();
        $calls = count($this->db->get_calls());

        \mod_skilland\observer::course_module_deleted($this->event(40, 3, 'forum'));

        $this->assertEquals($before, $this->snapshot());
        $this->assertCount($calls + 2, $this->db->get_calls(), 'Only the two snapshot reads ran');
    }

    public function test_second_delivery_changes_nothing(): void {
        \mod_skilland\observer::course_module_deleted($this->event(40, 3));
        $after = $this->snapshot();

        \mod_skilland\observer::course_module_deleted($this->event(40, 3));

        $this->assertEquals($after, $this->snapshot());
    }

    public function test_course_delete_module_dispatches_to_observer_when_opted_in(): void {
        $GLOBALS['_test_dispatch_observers'] = true;
        $this->db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'modname' => 'scorm'],
        ]);

        course_delete_module(40);

        $events = array_values(array_filter($GLOBALS['_test_events'] ?? [],
            fn($e) => $e instanceof \core\event\course_module_deleted));
        $this->assertCount(1, $events);
        $this->assertSame(40, $events[0]->objectid);
        $this->assertSame(3, $events[0]->courseid);
        $this->assertSame('scorm', $events[0]->other['modulename']);
        $this->assertNull($this->db->get_record('skilland', ['id' => 7])->scormcmid);
    }
}
