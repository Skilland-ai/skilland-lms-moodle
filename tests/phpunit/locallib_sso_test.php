<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_sso_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        \mod_skilland\logger::reset_cache();
    }

    // ---------------------------------------------------------------
    // skilland_get_sso_url() — frontend_url config
    // ---------------------------------------------------------------

    public function test_uses_frontend_url_when_set(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com',
        ];

        $url = skilland_get_sso_url('my-token');

        $this->assertStringStartsWith('https://app.skilland.com/sso-login?', $url);
    }

    public function test_falls_back_to_graphql_endpoint_minus_graphql(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => '',
            'graphql_endpoint' => 'https://api.example.com/graphql',
        ];

        $url = skilland_get_sso_url('tok');

        $this->assertStringStartsWith('https://api.example.com/sso-login?', $url);
    }

    public function test_falls_back_to_hardcoded_default(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => '',
            'graphql_endpoint' => '',
        ];

        $url = skilland_get_sso_url('tok');

        $this->assertStringStartsWith('https://api.skilland.com/sso-login?', $url);
    }

    public function test_removes_trailing_slash(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com/',
        ];

        $url = skilland_get_sso_url('tok');

        $this->assertStringStartsWith('https://app.skilland.com/sso-login?', $url);
        $this->assertStringNotContainsString('//sso-login', $url);
    }

    // ---------------------------------------------------------------
    // skilland_get_sso_url() — query string
    // ---------------------------------------------------------------

    public function test_constructs_sso_login_query_string(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com',
        ];

        $url = skilland_get_sso_url('abc123', '/dashboard');

        $parsed = parse_url($url);
        parse_str($parsed['query'], $query);
        $this->assertEquals('abc123', $query['token']);
        $this->assertEquals('/dashboard', $query['redirect']);
    }

    public function test_default_redirect_is_dashboard(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com',
        ];

        $url = skilland_get_sso_url('tok');

        $parsed = parse_url($url);
        parse_str($parsed['query'], $query);
        $this->assertEquals('/dashboard', $query['redirect']);
    }

    public function test_custom_redirect_path_preserved(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com',
        ];

        $url = skilland_get_sso_url('tok', '/courses/123/edit');

        $parsed = parse_url($url);
        parse_str($parsed['query'], $query);
        $this->assertEquals('/courses/123/edit', $query['redirect']);
    }

    public function test_token_with_special_chars_is_encoded(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => 'https://app.skilland.com',
        ];

        $url = skilland_get_sso_url('tok&en=val', '/dashboard');

        // The & in the token should be URL-encoded.
        $this->assertStringContainsString('tok%26en%3Dval', $url);
    }

    public function test_graphql_endpoint_suffix_stripped_correctly(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'frontend_url' => '',
            'graphql_endpoint' => 'https://api.example.com:8000/graphql',
        ];

        $url = skilland_get_sso_url('tok');

        $this->assertStringStartsWith('https://api.example.com:8000/sso-login?', $url);
    }
}
