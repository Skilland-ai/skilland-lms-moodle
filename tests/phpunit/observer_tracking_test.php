<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/completionlib.php';

/**
 * \mod_skilland\observer::scorm_tracking_submitted() and skilland_handle_scorm_tracking() (SKL-668).
 */
class observer_tracking_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    /** @var array Track rows the fake get_records_sql() answers with. */
    private $tracks = [];

    private const GLOBALS_TO_RESET = [
        '_test_lock_calls', '_test_cm_from_db', '_test_get_coursemodule_from_id',
        '_test_get_coursemodule_from_instance', '_test_get_course', '_test_grade_updates',
        '_test_completion_updates', '_test_completion_enabled',
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
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => 90, 'instance' => 7, 'course' => 3,
            'modname' => 'skilland'];
        \mod_skilland\logger::reset_cache();

        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'name' => 'Topic', 'scormcmid' => 40, 'grade' => 100,
                'completionlessons' => 1],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => 40, 'course' => 3, 'instance' => 5, 'deletioninprogress' => 0],
            (object) ['id' => 41, 'course' => 3, 'instance' => 6, 'deletioninprogress' => 0],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'scoid' => 11, 'visible' => 1],
            (object) ['id' => 2, 'skillandid' => 7, 'scoid' => 12, 'visible' => 1],
        ]);
        $this->tracks = [
            (object) ['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 11,
                'element' => 'cmi.core.lesson_status', 'value' => 'completed'],
        ];
        $this->db->set_records_sql_handler(fn() => $this->tracks);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function event(string $class, int $scormcmid, int $userid = 50): \core\event\base {
        return $class::create([
            'contextinstanceid' => $scormcmid,
            'courseid' => 3,
            'userid' => $userid,
            'relateduserid' => $userid,
            'objectid' => 1000,
        ]);
    }

    public function test_status_submitted_on_the_linked_scorm_refreshes_completion_and_grade(): void {
        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\status_submitted::class, 40));

        $rows = array_values($this->db->get_records('skilland_progress'));
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->lessonid);
        $this->assertSame(50, $rows[0]->userid);
        $this->assertSame('completed', $rows[0]->status);

        $updates = $GLOBALS['_test_completion_updates'] ?? [];
        $this->assertCount(1, $updates);
        $this->assertSame(90, $updates[0]['cm']->id);
        $this->assertSame(50, $updates[0]['userid']);
        $this->assertSame(COMPLETION_UNKNOWN, $updates[0]['state']);

        $grades = $GLOBALS['_test_grade_updates'] ?? [];
        $this->assertCount(1, $grades);
        $this->assertSame(7, $grades[0]['iteminstance']);
        $this->assertEqualsWithDelta(50.0, $grades[0]['grades'][50]->rawgrade, 0.00001);
    }

    public function test_scoreraw_submitted_is_handled_the_same_way(): void {
        $this->tracks = [
            (object) ['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 12,
                'element' => 'cmi.core.score.raw', 'value' => '40'],
        ];

        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\scoreraw_submitted::class, 40));

        $rows = array_values($this->db->get_records('skilland_progress'));
        $this->assertCount(1, $rows);
        $this->assertEquals(40, $rows[0]->score);
        $this->assertCount(1, $GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEqualsWithDelta(20.0, $GLOBALS['_test_grade_updates'][0]['grades'][50]->rawgrade, 0.00001);
    }

    public function test_unlinked_scorm_does_nothing(): void {
        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\status_submitted::class, 41));

        $this->assertSame([], $this->db->get_records('skilland_progress'));
        $this->assertEmpty($this->db->get_calls_for('get_records_sql'));
        $this->assertEmpty($GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_grade_updates'] ?? []);
    }

    public function test_no_provisioning_lock_is_taken(): void {
        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\status_submitted::class, 40));

        $this->assertEmpty($GLOBALS['_test_lock_calls'] ?? []);
    }

    public function test_completion_is_left_alone_when_the_activity_does_not_track_automatically(): void {
        $GLOBALS['_test_completion_enabled'] = COMPLETION_TRACKING_MANUAL;

        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\status_submitted::class, 40));

        $this->assertEmpty($GLOBALS['_test_completion_updates'] ?? []);
        $this->assertCount(1, $GLOBALS['_test_grade_updates'] ?? []);
    }

    public function test_ungraded_activity_updates_completion_only(): void {
        $this->db->set_field('skilland', 'grade', 0, ['id' => 7]);

        \mod_skilland\observer::scorm_tracking_submitted($this->event(\mod_scorm\event\status_submitted::class, 40));

        $this->assertCount(1, $GLOBALS['_test_completion_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_grade_updates'] ?? []);
    }

    public function test_handler_ignores_invalid_ids(): void {
        skilland_handle_scorm_tracking(0, 50);
        skilland_handle_scorm_tracking(40, 0);

        $this->assertEmpty($this->db->get_calls_for('get_records'), 'No activity lookup');
        $this->assertSame([], $this->db->get_records('skilland_progress'));
    }

    public function test_events_file_registers_both_scorm_tracking_events(): void {
        $observers = null;
        require realpath(__DIR__ . '/../../src') . '/db/events.php';

        foreach (['\\mod_scorm\\event\\status_submitted', '\\mod_scorm\\event\\scoreraw_submitted'] as $name) {
            $found = array_values(array_filter($observers, fn($o) => ($o['eventname'] ?? '') === $name));
            $this->assertCount(1, $found, $name);
            $this->assertSame('\\mod_skilland\\observer::scorm_tracking_submitted', $found[0]['callback']);
            $this->assertFalse($found[0]['internal']);
        }
    }
}
