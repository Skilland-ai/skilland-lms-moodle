<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Every src/amd/src/**\/*.js has a minified twin in the tracked src/amd/build and in dist (SKL-998:
 * local/string_loader.js was never built, so the update banner and confirm dialog never loaded).
 */
class amd_build_twins_test extends TestCase {

    private function sources(): array {
        $root = __DIR__ . '/../../src/amd/src';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() === 'js') {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        sort($found);
        return $found;
    }

    private function twin(string $source): string {
        return preg_replace('/\.js$/', '.min.js', $source);
    }

    public function test_gruntfile_builds_subdirectories(): void {
        $gruntfile = file_get_contents(__DIR__ . '/../../Gruntfile.js');
        $this->assertStringContainsString("src: ['**/*.js']", $gruntfile);
    }

    public function test_every_source_has_a_tracked_minified_twin(): void {
        $sources = $this->sources();
        $this->assertNotEmpty($sources);
        foreach ($sources as $source) {
            $this->assertFileExists(__DIR__ . '/../../src/amd/build/' . $this->twin($source), $source);
        }
    }

    public function test_every_source_has_a_minified_twin_in_dist(): void {
        $dist = __DIR__ . '/../../dist/amd/build';
        if (!is_dir($dist)) {
            $this->markTestSkipped('dist/ not built; run npm run build to check the release contents.');
        }
        foreach ($this->sources() as $source) {
            $this->assertFileExists($dist . '/' . $this->twin($source), $source);
        }
    }
}
