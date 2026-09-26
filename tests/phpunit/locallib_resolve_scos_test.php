<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * skilland_resolve_lesson_scos() and skilland_reset_topic_scorm() (SKL-655).
 */
class locallib_resolve_scos_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_lock_available', '_test_lock_calls', '_test_deleted_cmids', '_test_course_delete_throw',
        '_test_events', '_test_cm_from_db', '_test_get_coursemodule_from_id', '_test_create_module_calls',
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

        $this->db->seed('course_modules', [(object) ['id' => 50, 'instance' => 60, 'course' => 3,
            'deletioninprogress' => 0]]);
        $this->db->seed('scorm', [(object) ['id' => 60, 'course' => 3]]);
        $this->db->seed('scorm_scoes', [
            (object) ['id' => 100, 'scorm' => 60, 'identifier' => 'org', 'launch' => ''],
            (object) ['id' => 101, 'scorm' => 60, 'identifier' => 'sco_1', 'launch' => 'l1.html'],
            (object) ['id' => 102, 'scorm' => 60, 'identifier' => 'sco_2', 'launch' => 'l2.html'],
            (object) ['id' => 103, 'scorm' => 60, 'identifier' => 'sco_3', 'launch' => 'l3.html'],
            (object) ['id' => 201, 'scorm' => 61, 'identifier' => 'sco_4', 'launch' => 'l4.html'],
        ]);
        $this->db->seed('skilland', [
            (object) ['id' => 7, 'course' => 3, 'skilland_topicid' => 'topic1', 'scormcmid' => 50,
                'scorm_provisioned' => 1000, 'snapshotid' => 'snap1', 'snapshotcreatedat' => 900,
                'scomappings' => json_encode(['L1' => 'sco_1', 'L2' => 'sco_2', 'L3' => 'sco_3', 'L4' => 'sco_4'])],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'skilland_lessonid' => 'L1', 'visible' => 1,
                'scoid' => 101, 'sco_identifier' => 'sco_1'],
            (object) ['id' => 2, 'skillandid' => 7, 'skilland_lessonid' => 'L2', 'visible' => 1,
                'scoid' => null, 'sco_identifier' => null],
            (object) ['id' => 3, 'skillandid' => 7, 'skilland_lessonid' => 'L3', 'visible' => 0,
                'scoid' => null, 'sco_identifier' => null],
            (object) ['id' => 4, 'skillandid' => 7, 'skilland_lessonid' => 'L4', 'visible' => 1,
                'scoid' => null, 'sco_identifier' => null],
            (object) ['id' => 5, 'skillandid' => 8, 'skilland_lessonid' => 'L2', 'visible' => 1,
                'scoid' => null, 'sco_identifier' => null],
        ]);
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function lesson(int $id): \stdClass {
        return $this->db->get_record('skilland_lesson', ['id' => $id]);
    }

    private function lesson_writes(): array {
        return array_values(array_filter($this->db->get_calls_for('set_field'),
            fn($c) => $c['table'] === 'skilland_lesson'));
    }

    private function lockcalls(string $action): array {
        return array_values(array_filter($GLOBALS['_test_lock_calls'] ?? [], fn($c) => $c['action'] === $action));
    }

    // ---------------------------------------------------------------
    // skilland_resolve_lesson_scos()
    // ---------------------------------------------------------------

    public function test_resolves_a_visible_lesson_from_the_stored_mapping(): void {
        $unresolved = skilland_resolve_lesson_scos(7);

        $this->assertSame(102, $this->lesson(2)->scoid);
        $this->assertSame('sco_2', $this->lesson(2)->sco_identifier);
        $this->assertSame(['L4'], $unresolved, 'sco_4 belongs to another package');
        $this->assertNull($this->lesson(3)->scoid, 'Hidden lessons are left alone');
        $this->assertNull($this->lesson(4)->scoid);
        $this->assertNull($this->lesson(5)->scoid, 'Other activities are left alone');
        $this->assertSame(101, $this->lesson(1)->scoid);
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
    }

    public function test_lesson_sco_identifier_wins_over_the_mapping(): void {
        $this->db->set_field('skilland_lesson', 'sco_identifier', 'sco_3', ['id' => 2]);

        skilland_resolve_lesson_scos(7);

        $this->assertSame(103, $this->lesson(2)->scoid);
        $this->assertSame('sco_3', $this->lesson(2)->sco_identifier);
    }

    public function test_null_mapping_leaves_lessons_unresolved_and_writes_nothing(): void {
        $this->db->set_field('skilland', 'scomappings', null, ['id' => 7]);

        $this->assertSame(['L2', 'L4'], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
    }

    public function test_identifier_missing_from_the_package_writes_nothing(): void {
        $this->db->set_field('skilland', 'scomappings', json_encode(['L2' => 'gone', 'L4' => 'gone']), ['id' => 7]);

        $this->assertSame(['L2', 'L4'], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
    }

    public function test_missing_scorm_module_writes_nothing(): void {
        $this->db->delete_records('course_modules', ['id' => 50]);

        $this->assertSame([], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_scorm_being_deleted_writes_nothing(): void {
        $this->db->set_field('course_modules', 'deletioninprogress', 1, ['id' => 50]);

        $this->assertSame([], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
    }

    public function test_unprovisioned_activity_writes_nothing(): void {
        $this->db->set_field('skilland', 'scormcmid', null, ['id' => 7]);

        $this->assertSame([], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
    }

    public function test_busy_lock_skips_without_error(): void {
        $GLOBALS['_test_lock_available'] = false;

        $this->assertSame([], skilland_resolve_lesson_scos(7));
        $this->assertEmpty($this->lesson_writes());
        $acquire = $this->lockcalls('acquire');
        $this->assertSame('mod_skilland/provision_7', $acquire[0]['key']);
        $this->assertSame(2, $acquire[0]['timeout']);
    }

    public function test_lock_is_taken_and_released_once(): void {
        skilland_resolve_lesson_scos(7);

        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_missing_activity_returns_empty(): void {
        $this->assertSame([], skilland_resolve_lesson_scos(999));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    // ---------------------------------------------------------------
    // skilland_reset_topic_scorm()
    // ---------------------------------------------------------------

    public function test_reset_deletes_the_module_and_clears_every_field(): void {
        $skilland = $this->db->get_record('skilland', ['id' => 7]);

        skilland_reset_topic_scorm($skilland);

        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $row = $this->db->get_record('skilland', ['id' => 7]);
        foreach (['scormcmid', 'scorm_provisioned', 'scomappings', 'snapshotid'] as $field) {
            $this->assertNull($row->$field, $field);
            $this->assertNull($skilland->$field, "caller record $field");
        }
        foreach ([1, 2, 3, 4] as $id) {
            $this->assertNull($this->lesson($id)->scoid);
            $this->assertNull($this->lesson($id)->sco_identifier);
        }
        $this->assertCount(1, $this->lockcalls('acquire'));
        $this->assertCount(1, $this->lockcalls('release'));
    }

    public function test_reset_twice_is_a_no_op_the_second_time(): void {
        skilland_reset_topic_scorm($this->db->get_record('skilland', ['id' => 7]));
        $after = [$this->db->get_records('skilland'), $this->db->get_records('skilland_lesson')];

        skilland_reset_topic_scorm($this->db->get_record('skilland', ['id' => 7]));

        $this->assertSame([50], $GLOBALS['_test_deleted_cmids']);
        $this->assertEquals($after, [$this->db->get_records('skilland'), $this->db->get_records('skilland_lesson')]);
    }

    public function test_reset_with_a_busy_lock_throws_and_changes_nothing(): void {
        $GLOBALS['_test_lock_available'] = false;

        try {
            skilland_reset_topic_scorm($this->db->get_record('skilland', ['id' => 7]));
            $this->fail('Expected error_provision_in_progress');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_provision_in_progress', $e->errorcode);
        }
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
        $this->assertSame(50, $this->db->get_record('skilland', ['id' => 7])->scormcmid);
    }

    public function test_reset_releases_the_lock_when_deletion_throws(): void {
        $GLOBALS['_test_course_delete_throw'] = new \RuntimeException('boom');

        skilland_reset_topic_scorm($this->db->get_record('skilland', ['id' => 7]));

        $this->assertCount(1, $this->lockcalls('release'));
        $this->assertNull($this->db->get_record('skilland', ['id' => 7])->scormcmid);
    }

    // ---------------------------------------------------------------
    // skilland_unlink_scorm() clears the stored mapping too
    // ---------------------------------------------------------------

    public function test_unlink_clears_scomappings(): void {
        skilland_unlink_scorm(7);

        $this->assertNull($this->db->get_record('skilland', ['id' => 7])->scomappings);
    }
}
