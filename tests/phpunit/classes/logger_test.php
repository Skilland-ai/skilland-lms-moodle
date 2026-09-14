<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\logger;

/**
 * Unit tests for the mod_skilland\logger class.
 */
class logger_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        logger::reset_cache();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
    }

    // ---------------------------------------------------------------
    // Method existence — catches missing methods before runtime.
    // ---------------------------------------------------------------

    public function test_debug_method_exists(): void {
        $this->assertTrue(
            method_exists(logger::class, 'debug'),
            'logger::debug() must exist'
        );
    }

    public function test_warn_method_exists(): void {
        $this->assertTrue(
            method_exists(logger::class, 'warn'),
            'logger::warn() must exist'
        );
    }

    public function test_error_method_exists(): void {
        $this->assertTrue(
            method_exists(logger::class, 'error'),
            'logger::error() must exist'
        );
    }

    public function test_info_method_exists(): void {
        // Bug #20: sync_content calls logger::info() but the method doesn't exist.
        $this->assertTrue(
            method_exists(logger::class, 'info'),
            'logger::info() must exist — called by sync_content task (Issue #20)'
        );
    }

    // ---------------------------------------------------------------
    // info() behaviour — always logs at DEBUG_NORMAL, no prefix.
    // ---------------------------------------------------------------

    public function test_info_always_logs(): void {
        logger::info('SyncContent', 'Starting sync');

        $this->assertCount(1, $GLOBALS['_test_debug_messages']);
        $this->assertStringContainsString('[Skilland] [SyncContent] Starting sync', $GLOBALS['_test_debug_messages'][0]['message']);
        $this->assertEquals(DEBUG_NORMAL, $GLOBALS['_test_debug_messages'][0]['level']);
    }

    public function test_info_does_not_prefix_warning_or_error(): void {
        logger::info('Test', 'Operational message');

        $msg = $GLOBALS['_test_debug_messages'][0]['message'];
        $this->assertStringNotContainsString('WARNING:', $msg);
        $this->assertStringNotContainsString('ERROR:', $msg);
    }

    // ---------------------------------------------------------------
    // debug() behaviour — only logs when devmode is enabled.
    // ---------------------------------------------------------------

    public function test_debug_logs_when_devmode_enabled(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) ['devmode' => true];

        logger::debug('Test', 'Debug message');

        $this->assertCount(1, $GLOBALS['_test_debug_messages']);
        $this->assertStringContainsString('[Skilland] [Test] Debug message', $GLOBALS['_test_debug_messages'][0]['message']);
        $this->assertEquals(DEBUG_DEVELOPER, $GLOBALS['_test_debug_messages'][0]['level']);
    }

    public function test_debug_silent_when_devmode_disabled(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) ['devmode' => false];

        logger::debug('Test', 'Should not appear');

        $this->assertEmpty($GLOBALS['_test_debug_messages']);
    }

    // ---------------------------------------------------------------
    // warn() behaviour — always logs at DEBUG_NORMAL.
    // ---------------------------------------------------------------

    public function test_warn_always_logs(): void {
        logger::warn('Test', 'Warning message');

        $this->assertCount(1, $GLOBALS['_test_debug_messages']);
        $this->assertStringContainsString('WARNING:', $GLOBALS['_test_debug_messages'][0]['message']);
        $this->assertEquals(DEBUG_NORMAL, $GLOBALS['_test_debug_messages'][0]['level']);
    }

    public function test_warn_includes_component_prefix(): void {
        logger::warn('SCORM', 'Missing package');

        $this->assertStringContainsString('[Skilland] [SCORM]', $GLOBALS['_test_debug_messages'][0]['message']);
    }

    // ---------------------------------------------------------------
    // error() behaviour — always logs at DEBUG_MINIMAL.
    // ---------------------------------------------------------------

    public function test_error_always_logs(): void {
        logger::error('Test', 'Error message');

        $this->assertCount(1, $GLOBALS['_test_debug_messages']);
        $this->assertStringContainsString('ERROR:', $GLOBALS['_test_debug_messages'][0]['message']);
        $this->assertEquals(DEBUG_MINIMAL, $GLOBALS['_test_debug_messages'][0]['level']);
    }

    public function test_error_includes_component_prefix(): void {
        logger::error('GraphQL', 'HTTP 500');

        $this->assertStringContainsString('[Skilland] [GraphQL]', $GLOBALS['_test_debug_messages'][0]['message']);
    }

    // ---------------------------------------------------------------
    // reset_cache() — devmode changes take effect after reset.
    // ---------------------------------------------------------------

    public function test_reset_cache_clears_devmode(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) ['devmode' => true];
        logger::debug('Test', 'Should log');
        $this->assertCount(1, $GLOBALS['_test_debug_messages']);

        // Change config and reset cache.
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) ['devmode' => false];
        $GLOBALS['_test_debug_messages'] = [];
        logger::reset_cache();

        logger::debug('Test', 'Should not log');
        $this->assertEmpty($GLOBALS['_test_debug_messages']);
    }
}
