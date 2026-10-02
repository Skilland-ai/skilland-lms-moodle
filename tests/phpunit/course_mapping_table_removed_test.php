<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/db/upgradelib.php';

/**
 * The skilland_course_id course custom field is the only course mapping (SKL-689): the legacy
 * skilland_course table is migrated into it by the upgrade and no other code knows the table.
 */
class course_mapping_table_removed_test extends TestCase {

    /** Helpers that read or wrote the dropped table. */
    private const REMOVED_HELPERS = [
        'skilland_get_course_mapping', 'skilland_course_has_mapping', 'skilland_set_course_mapping',
        'skilland_update_course_sync', 'skilland_get_skilland_courseid', 'skilland_delete_course_mapping',
        'skilland_render_player_navigation',
    ];

    /** Files allowed to name the legacy table: the upgrade step and its helper. */
    private const ALLOWED = ['db/upgrade.php', 'db/upgradelib.php'];

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_customfield_value'] = [];
        unset($GLOBALS['_test_customfield_saved'], $GLOBALS['_test_mtrace']);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_customfield_value'], $GLOBALS['_test_customfield_saved']);
        parent::tearDown();
    }

    /**
     * Plugin PHP source files, relative to src/, tests excluded.
     *
     * @return string[]
     */
    private function sourceFiles(): array {
        $root = realpath(__DIR__ . '/../../src');
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->getExtension() !== 'php' || str_starts_with($relative, 'tests/')) {
                continue;
            }
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    // ---------------------------------------------------------------
    // Guard: nothing outside the upgrade knows the table
    // ---------------------------------------------------------------

    public function test_no_source_file_outside_the_upgrade_uses_the_legacy_table(): void {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative) {
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            $code = file_get_contents(__DIR__ . '/../../src/' . $relative);
            if (preg_match("/\\{skilland_course\\}|['\"]skilland_course['\"]/", $code)) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders, 'Only the upgrade may name the skilland_course table');
    }

    public function test_no_source_file_calls_a_removed_helper(): void {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative) {
            $code = file_get_contents(__DIR__ . '/../../src/' . $relative);
            foreach (self::REMOVED_HELPERS as $helper) {
                if (preg_match('/\b' . preg_quote($helper, '/') . '\s*\(/', $code)) {
                    $offenders[] = "$relative: $helper";
                }
            }
        }
        $this->assertSame([], $offenders);
        foreach (self::REMOVED_HELPERS as $helper) {
            $this->assertFalse(function_exists($helper), "$helper is removed");
        }
    }

    public function test_the_player_navigation_template_and_style_are_gone(): void {
        $src = __DIR__ . '/../../src';
        $this->assertFileDoesNotExist("$src/templates/player_navigation.mustache");
        $this->assertFalse(defined('mod_skilland\output\lesson_navigation::STYLE_PLAYER'));
        $this->assertStringNotContainsString('skilland-player-nav', file_get_contents("$src/styles/view.css"));
    }

    // ---------------------------------------------------------------
    // skilland_migrate_course_mapping_table()
    // ---------------------------------------------------------------

    private function seedLegacyRows(): void {
        $this->db->get_manager()->set_table_exists('skilland_course', true);
        $this->db->seed('course', [
            (object) ['id' => 10], (object) ['id' => 11], (object) ['id' => 12], (object) ['id' => 13],
        ]);
        $this->db->seed('skilland_course', [
            (object) ['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-a'],  // Empty field: migrated.
            (object) ['id' => 2, 'course' => 11, 'skilland_courseid' => 'skill-b'],  // Other value: field wins.
            (object) ['id' => 3, 'course' => 12, 'skilland_courseid' => 'skill-c'],  // Same value.
            (object) ['id' => 4, 'course' => 99, 'skilland_courseid' => 'skill-d'],  // Deleted course.
            (object) ['id' => 5, 'course' => 13, 'skilland_courseid' => ''],         // No value.
        ]);
        $GLOBALS['_test_customfield_value'] = [10 => null, 11 => 'skill-field', 12 => 'skill-c', 13 => null];
    }

    public function test_migration_fills_only_empty_fields_of_existing_courses(): void {
        $this->seedLegacyRows();

        $counts = skilland_migrate_course_mapping_table();

        $this->assertSame(
            ['migrated' => 1, 'same' => 1, 'conflict' => 1, 'deletedcourse' => 1, 'empty' => 1, 'failed' => 0],
            $counts
        );
        $this->assertSame('skill-a', $GLOBALS['_test_customfield_value'][10]);
        $this->assertSame('skill-field', $GLOBALS['_test_customfield_value'][11]);
        $this->assertSame('skill-c', $GLOBALS['_test_customfield_value'][12]);
        $this->assertNull($GLOBALS['_test_customfield_value'][13]);
        $this->assertSame(['skill-a'], $GLOBALS['_test_customfield_saved']);
    }

    public function test_migration_logs_counts_and_course_ids_only(): void {
        $this->seedLegacyRows();

        skilland_migrate_course_mapping_table();
        $output = implode("\n", $GLOBALS['_test_mtrace'] ?? []);

        $this->assertStringContainsString('migrated=1, same=1, conflict=1, deletedcourse=1, empty=1, failed=0', $output);
        $this->assertStringContainsString('kept the custom field value of course ids 11', $output);
        foreach (['skill-a', 'skill-b', 'skill-c', 'skill-d', 'skill-field'] as $value) {
            $this->assertStringNotContainsString($value, $output);
        }
    }

    public function test_migration_without_the_table_does_nothing(): void {
        $counts = skilland_migrate_course_mapping_table();

        $this->assertSame(0, array_sum($counts));
        $this->assertEmpty($this->db->get_calls_for('get_records'));
    }

    public function test_upgrade_step_bumps_the_version_with_the_release(): void {
        $version = file_get_contents(__DIR__ . '/../../src/version.php');
        $this->assertSame(1, preg_match('/\$plugin->version\s*=\s*(\d+);/', $version, $m));

        $this->assertGreaterThanOrEqual(2026100210, (int) $m[1]);
        $upgrade = file_get_contents(__DIR__ . '/../../src/db/upgrade.php');
        $this->assertStringContainsString('skilland_migrate_course_mapping_table()', $upgrade);
        $this->assertStringContainsString("upgrade_mod_savepoint(true, 2026100210, 'skilland')", $upgrade);
    }
}
