<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/upgrade_stub.php';
require_once __DIR__ . '/../../src/db/upgradelib.php';
require_once __DIR__ . '/../../src/db/upgrade.php';

/**
 * FakeDatabaseManager that records drop_table() and lets the dropped table disappear.
 */
class upgrade_recording_manager extends \FakeDatabaseManager {
    /** @var string[] Names of the dropped tables. */
    public array $dropped = [];

    public function table_exists($table): bool {
        return parent::table_exists((string) $table);
    }

    public function drop_table($table): void {
        $this->dropped[] = (string) $table;
        $this->set_table_exists((string) $table, false);
    }
}

/**
 * FakeDatabase whose manager records drop_table().
 */
class upgrade_fake_database extends \FakeDatabase {
    /** @var upgrade_recording_manager */
    public upgrade_recording_manager $recordingmanager;

    public function __construct() {
        parent::__construct();
        $this->recordingmanager = new upgrade_recording_manager();
    }

    public function get_manager(): \FakeDatabaseManager {
        return $this->recordingmanager;
    }
}

/**
 * The 2026100210 upgrade step (SKL-689) drops the legacy skilland_course table only when every
 * mapping reached the course custom field, and always moves the savepoint.
 */
class upgrade_course_mapping_step_test extends TestCase {

    /** @var upgrade_fake_database */
    private $db;

    /** @var string A dirroot whose mod/skilland points at src, for upgrade.php's require_once. */
    private static $dirroot;

    /** @var string */
    private $originaldirroot;

    public static function setUpBeforeClass(): void {
        self::$dirroot = sys_get_temp_dir() . '/skl_upgrade_dirroot_' . getmypid();
        if (!is_dir(self::$dirroot . '/mod')) {
            mkdir(self::$dirroot . '/mod', 0777, true);
        }
        if (!file_exists(self::$dirroot . '/mod/skilland')) {
            symlink(realpath(__DIR__ . '/../../src'), self::$dirroot . '/mod/skilland');
        }
    }

    public static function tearDownAfterClass(): void {
        @unlink(self::$dirroot . '/mod/skilland');
        @rmdir(self::$dirroot . '/mod');
        @rmdir(self::$dirroot);
    }

    protected function setUp(): void {
        parent::setUp();
        $this->originaldirroot = $GLOBALS['CFG']->dirroot;
        $GLOBALS['CFG']->dirroot = self::$dirroot;
        $this->db = new upgrade_fake_database();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_customfield_value'] = [];
        unset($GLOBALS['_test_customfield_saved'], $GLOBALS['_test_mtrace'], $GLOBALS['_test_upgrade_savepoints']);
    }

    protected function tearDown(): void {
        $GLOBALS['CFG']->dirroot = $this->originaldirroot;
        unset($GLOBALS['_test_customfield_value'], $GLOBALS['_test_customfield_saved'],
            $GLOBALS['_test_upgrade_savepoints']);
        parent::tearDown();
    }

    /**
     * Seed the legacy table with one mapping per course.
     *
     * @param array $rows Course id => Skilland course id.
     */
    private function seed_legacy(array $rows): void {
        $this->db->get_manager()->set_table_exists('skilland_course', true);
        $courses = [];
        $legacy = [];
        $id = 0;
        foreach ($rows as $courseid => $skillid) {
            $courses[] = (object) ['id' => $courseid];
            $legacy[] = (object) ['id' => ++$id, 'course' => $courseid, 'skilland_courseid' => $skillid];
        }
        $this->db->seed('course', $courses);
        $this->db->seed('skilland_course', $legacy);
    }

    /**
     * Every mapping copied: the table is dropped and the savepoint moves.
     */
    public function test_a_clean_migration_drops_the_table(): void {
        $this->seed_legacy([10 => 'skill-a', 11 => 'skill-b']);
        $GLOBALS['_test_customfield_value'] = [10 => null, 11 => 'skill-b'];

        $this->assertTrue(xmldb_skilland_upgrade(2026100209));

        $this->assertSame(['skilland_course'], $this->db->recordingmanager->dropped);
        $this->assertSame('skill-a', $GLOBALS['_test_customfield_value'][10]);
        $this->assertSame([[2026100210, 'skilland']], $GLOBALS['_test_upgrade_savepoints']);
    }

    /**
     * A field that cannot be written keeps the table, so the mapping is not lost.
     */
    public function test_a_failed_write_keeps_the_table(): void {
        // Course 12 has no custom field data and the field cannot be created: its write fails.
        $this->seed_legacy([10 => 'skill-a', 12 => 'skill-secret']);
        $GLOBALS['_test_customfield_value'] = [10 => null];

        $this->assertTrue(xmldb_skilland_upgrade(2026100209));

        $this->assertSame([], $this->db->recordingmanager->dropped);
        $this->assertTrue($this->db->get_manager()->table_exists('skilland_course'));
        $this->assertSame('skill-a', $GLOBALS['_test_customfield_value'][10], 'The writable course is still migrated');
        $kept = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => str_contains($m['message'], 'kept the skilland_course table')));
        $this->assertCount(1, $kept);
        $this->assertStringContainsString('1 mapping(s)', $kept[0]['message']);
        $this->assertSame(DEBUG_NORMAL, $kept[0]['level']);
        $this->assertStringContainsString('could not write the custom field of course ids 12',
            implode("\n", $GLOBALS['_test_mtrace']));
        $everything = implode("\n", array_merge($GLOBALS['_test_mtrace'],
            array_column($GLOBALS['_test_debug_messages'], 'message')));
        $this->assertStringNotContainsString('skill-secret', $everything);
        $this->assertSame([[2026100210, 'skilland']], $GLOBALS['_test_upgrade_savepoints']);
    }

    /**
     * A fresh install has no legacy table: nothing is dropped, the savepoint still moves.
     */
    public function test_without_the_table_only_the_savepoint_moves(): void {
        $this->assertTrue(xmldb_skilland_upgrade(2026100209));

        $this->assertSame([], $this->db->recordingmanager->dropped);
        $this->assertEmpty($this->db->get_calls_for('get_records'));
        $this->assertSame([[2026100210, 'skilland']], $GLOBALS['_test_upgrade_savepoints']);
    }

    /**
     * A site already at 2026100210 never runs the step again.
     */
    public function test_a_site_past_the_step_does_not_run_it_again(): void {
        $this->seed_legacy([10 => 'skill-a']);
        $GLOBALS['_test_customfield_value'] = [10 => null];

        $this->assertTrue(xmldb_skilland_upgrade(2026100210));

        $this->assertSame([], $this->db->recordingmanager->dropped);
        $this->assertNull($GLOBALS['_test_customfield_value'][10]);
        $this->assertEmpty($GLOBALS['_test_upgrade_savepoints'] ?? []);
    }
}
