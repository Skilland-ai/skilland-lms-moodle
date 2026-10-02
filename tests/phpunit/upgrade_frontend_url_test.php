<?php

namespace {
    if (!function_exists('upgrade_mod_savepoint')) {
        function upgrade_mod_savepoint($result, $version, $modname, $allowabort = true) {
            $GLOBALS['_test_savepoints'][] = $version;
            return true;
        }
    }
}

namespace mod_skilland\tests {

use PHPUnit\Framework\TestCase;

/**
 * The SKL-991 upgrade step clears a Frontend URL that still holds the old default.
 */
class upgrade_frontend_url_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_savepoints'] = [];
        require_once __DIR__ . '/../../src/db/upgrade.php';
    }

    private function upgrade(?string $frontendurl): ?string {
        if ($frontendurl !== null) {
            set_config('frontend_url', $frontendurl, 'mod_skilland');
        }
        // Start just below the new step so only it runs.
        $this->assertTrue(xmldb_skilland_upgrade(2026100202));
        $this->assertContains(2026100203, $GLOBALS['_test_savepoints']);
        $value = get_config('mod_skilland', 'frontend_url');
        return $value === null ? null : (string) $value;
    }

    public function test_clears_the_old_default(): void {
        $this->assertSame('', $this->upgrade('https://app.skilland.ai'));
    }

    public function test_clears_the_old_default_with_a_trailing_slash(): void {
        $this->assertSame('', $this->upgrade('https://app.skilland.ai/'));
    }

    public function test_keeps_a_custom_value(): void {
        $this->assertSame('https://custom.example.org', $this->upgrade('https://custom.example.org'));
    }

    public function test_keeps_an_empty_value(): void {
        $this->assertSame('', $this->upgrade(''));
    }

    public function test_settings_default_for_frontend_url_is_empty(): void {
        $source = file_get_contents(__DIR__ . '/../../src/settings.php');
        $this->assertMatchesRegularExpression(
            "/'mod_skilland\\/frontend_url',\\s*get_string\\('settings_frontend_url', 'mod_skilland'\\),\\s*" .
            "get_string\\('settings_frontend_url_desc', 'mod_skilland'\\),\\s*''\\s*\\)/",
            $source
        );
    }
}

}
