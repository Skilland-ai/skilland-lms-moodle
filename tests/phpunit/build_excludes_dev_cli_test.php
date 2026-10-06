<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * The release build (npm run build → dist/) must not ship the development-only
 * src/cli/configure_api.php, while keeping the other CLI scripts and the docs.
 */
class build_excludes_dev_cli_test extends TestCase {

    public function test_gruntfile_excludes_configure_api(): void {
        $gruntfile = file_get_contents(__DIR__ . '/../../Gruntfile.js');
        $this->assertStringContainsString("'!cli/configure_api.php'", $gruntfile);
    }

    public function test_cli_scripts_live_under_src(): void {
        $root = __DIR__ . '/../..';
        $this->assertFileExists($root . '/src/cli/cleanup_orphaned_scorm.php');
        $this->assertFileExists($root . '/src/cli/configure_api.php');
        $this->assertDirectoryDoesNotExist($root . '/cli');
    }

    public function test_dist_has_no_configure_api(): void {
        $dist = __DIR__ . '/../../dist';
        if (!is_dir($dist)) {
            $this->markTestSkipped('dist/ not built; run npm run build to check the release contents.');
        }
        $this->assertFileDoesNotExist($dist . '/cli/configure_api.php');
        $this->assertFileExists($dist . '/cli/cleanup_orphaned_scorm.php');
    }

    public function test_dist_ships_readme_and_changes(): void {
        $dist = __DIR__ . '/../../dist';
        if (!is_dir($dist)) {
            $this->markTestSkipped('dist/ not built; run npm run build to check the release contents.');
        }
        $this->assertFileExists($dist . '/README.md');
        $this->assertFileExists($dist . '/CHANGES.md');
    }
}
