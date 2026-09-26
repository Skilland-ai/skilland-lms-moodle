<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Static checks that src/settings.php stays declarative (it runs on every admin page load)
 * and that the course custom field is recreated by an upgrade step instead.
 */
class settings_declarative_test extends TestCase {

    private static function source(string $relative): string {
        return file_get_contents(__DIR__ . '/../../' . $relative);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbidden_provider(): array {
        return [
            'optional_param' => ['optional_param'],
            'required_param' => ['required_param'],
            'redirect' => ['redirect('],
            'set_config' => ['set_config'],
            'DB access' => ['$DB->'],
            'custom field creation' => ['skilland_ensure_course_customfield'],
            'sesskey link' => ['sesskey'],
            'createcustomfield' => ['createcustomfield'],
        ];
    }

    /**
     * @dataProvider forbidden_provider
     */
    public function test_settings_has_no_side_effects(string $needle): void {
        $this->assertStringNotContainsString($needle, self::source('src/settings.php'));
    }

    public function test_orgid_uses_param_alphanumext(): void {
        $this->assertMatchesRegularExpression(
            "/'mod_skilland\\/orgid'.*?PARAM_ALPHANUMEXT\\)\\);/s",
            self::source('src/settings.php')
        );
    }

    public function test_settings_has_gpl_header_and_package(): void {
        $source = self::source('src/settings.php');
        $this->assertStringContainsString('GNU General Public License', $source);
        $this->assertStringContainsString('@package    mod_skilland', $source);
    }

    public function test_removed_lang_strings_are_gone(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            require __DIR__ . '/../../src/lang/' . $lang . '/skilland.php';
            foreach (['create_customfield_button', 'customfield_created', 'customfield_create_failed'] as $key) {
                $this->assertArrayNotHasKey($key, $string, "$lang still defines $key");
            }
        }
    }

    public function test_last_upgrade_savepoint_is_new_and_within_version(): void {
        $source = self::source('src/db/upgrade.php');
        preg_match_all('/upgrade_mod_savepoint\(true,\s*(\d+),/', $source, $m);
        $savepoints = array_map('intval', $m[1]);
        $this->assertNotEmpty($savepoints);

        $plugin = new \stdClass();
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        if (!defined('ANY_VERSION')) {
            define('ANY_VERSION', 'any');
        }
        require __DIR__ . '/../../src/version.php';

        $last = end($savepoints);
        $this->assertGreaterThanOrEqual(2026092613, $last);
        $this->assertLessThanOrEqual($plugin->version, $last);
    }

    public function test_upgrade_recreates_the_custom_field(): void {
        $source = self::source('src/db/upgrade.php');
        $this->assertMatchesRegularExpression(
            '/if \(\$oldversion < 2026092613\) \{.*?skilland_ensure_course_customfield\(\);.*?' .
            'upgrade_mod_savepoint\(true, 2026092613, \'skilland\'\);/s',
            $source
        );
    }
}
