<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\admin_setting_https_url;

/**
 * Unit tests for mod_skilland\admin_setting_https_url.
 */
class admin_setting_https_url_test extends TestCase {

    private function setting(): admin_setting_https_url {
        return new admin_setting_https_url('mod_skilland/frontend_url', 'URL', 'desc', 'https://app.skilland.ai');
    }

    protected function setUp(): void {
        parent::setUp();
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
    }

    protected function tearDown(): void {
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        parent::tearDown();
    }

    public function test_uses_param_url(): void {
        $this->assertSame(PARAM_URL, $this->setting()->paramtype);
    }

    public function test_rejects_http_by_default(): void {
        $this->assertSame('error_url_https_required', $this->setting()->validate('http://example.com/graphql'));
    }

    public function test_accepts_http_when_allowed_in_config(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->assertTrue($this->setting()->validate('http://host.docker.internal:8000/graphql'));
    }

    public function test_accepts_https(): void {
        $this->assertTrue($this->setting()->validate('https://api.skilland.ai/graphql'));
    }

    public function test_accepts_uppercase_https_scheme(): void {
        $this->assertTrue($this->setting()->validate('HTTPS://api.skilland.ai/graphql'));
    }

    public function test_empty_value_defers_to_parent(): void {
        $this->assertTrue($this->setting()->validate(''));
    }

    public function test_rejects_protocol_relative_url(): void {
        $this->assertSame('error_url_https_required', $this->setting()->validate('//api.skilland.ai/graphql'));
    }

    public function test_rejects_javascript_url(): void {
        $this->assertSame('error_url_https_required', $this->setting()->validate('javascript:alert(1)'));
    }

    public function test_rejects_other_schemes(): void {
        $this->assertSame('error_url_https_required', $this->setting()->validate('ftp://api.skilland.ai/graphql'));
    }

    public function test_accepts_https_with_surrounding_whitespace(): void {
        $this->assertTrue($this->setting()->validate(' https://api.skilland.ai/graphql '));
    }

    public function test_accepts_https_when_http_allowed_in_config(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->assertTrue($this->setting()->validate('https://api.skilland.ai/graphql'));
    }

    public function test_empty_allow_http_flag_keeps_https_required(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = false;
        $this->assertSame('error_url_https_required', $this->setting()->validate('http://example.com'));
    }

    public function test_error_string_is_defined_in_every_language(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include(__DIR__ . '/../../src/lang/' . $lang . '/skilland.php');
            $this->assertArrayHasKey('error_url_https_required', $string, "$lang is missing error_url_https_required");
            $this->assertStringContainsString('https://', $string['error_url_https_required']);
        }
    }
}
