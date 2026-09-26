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

    private function config(array $values): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) $values;
    }

    // ---------------------------------------------------------------
    // skilland_get_frontend_url()
    // ---------------------------------------------------------------

    public function test_frontend_url_uses_setting(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $this->assertSame('https://app.skilland.com', skilland_get_frontend_url());
    }

    public function test_frontend_url_strips_trailing_slash(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com/']);

        $this->assertSame('https://app.skilland.com', skilland_get_frontend_url());
    }

    public function test_frontend_url_falls_back_to_graphql_endpoint_minus_graphql(): void {
        $this->config(['frontend_url' => '', 'graphql_endpoint' => 'https://api.example.com:8000/graphql']);

        $this->assertSame('https://api.example.com:8000', skilland_get_frontend_url());
    }

    public function test_frontend_url_falls_back_to_hardcoded_default(): void {
        $this->config(['frontend_url' => '', 'graphql_endpoint' => '']);

        $this->assertSame('https://api.skilland.com', skilland_get_frontend_url());
    }

    // ---------------------------------------------------------------
    // skilland_get_sso_endpoint()
    // ---------------------------------------------------------------

    public function test_sso_endpoint_has_no_query(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com/']);

        $endpoint = skilland_get_sso_endpoint();

        $this->assertSame('https://app.skilland.com/sso-login', $endpoint);
        $this->assertStringNotContainsString('?', $endpoint);
    }

    public function test_sso_endpoint_from_graphql_fallback(): void {
        $this->config(['frontend_url' => '', 'graphql_endpoint' => 'https://api.example.com/graphql']);

        $this->assertSame('https://api.example.com/sso-login', skilland_get_sso_endpoint());
    }

    // ---------------------------------------------------------------
    // skilland_get_sso_audience()
    // ---------------------------------------------------------------

    public function test_audience_is_origin_of_frontend_url(): void {
        $this->config(['frontend_url' => 'https://app.example.com/']);

        $this->assertSame('https://app.example.com', skilland_get_sso_audience());
    }

    public function test_audience_keeps_port(): void {
        $this->config(['frontend_url' => 'http://localhost:3100']);

        $this->assertSame('http://localhost:3100', skilland_get_sso_audience());
    }

    public function test_audience_strips_path(): void {
        $this->config(['frontend_url' => 'https://app.example.com:8443/skilland/app/']);

        $this->assertSame('https://app.example.com:8443', skilland_get_sso_audience());
    }

    public function test_audience_from_graphql_fallback(): void {
        $this->config(['frontend_url' => '', 'graphql_endpoint' => 'https://api.example.com/graphql']);

        $this->assertSame('https://api.example.com', skilland_get_sso_audience());
    }

    // ---------------------------------------------------------------
    // skilland_render_sso_post_form()
    // ---------------------------------------------------------------

    public function test_form_posts_to_sso_endpoint(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $html = skilland_render_sso_post_form('abc123', '/dashboard');

        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*method="post"/', $html);
        $this->assertMatchesRegularExpression('#<form[^>]*action="https://app\.skilland\.com/sso-login"#', $html);
    }

    public function test_form_carries_token_and_redirect_as_hidden_fields(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $html = skilland_render_sso_post_form('abc123', '/skills-studio/s1/topics/t1');

        $this->assertStringContainsString('<input type="hidden" name="token" value="abc123">', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="redirect" value="/skills-studio/s1/topics/t1">', $html);
    }

    public function test_form_escapes_token_and_redirect(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $html = skilland_render_sso_post_form('a"b<c>d&e', '/x"><script>alert(1)</script>');

        $this->assertStringContainsString('name="token" value="a&quot;b&lt;c&gt;d&amp;e"', $html);
        $this->assertStringContainsString(
            'name="redirect" value="/x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('a"b<c>', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_form_escapes_endpoint(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com/"><x']);

        $html = skilland_render_sso_post_form('tok', '/dashboard');

        $this->assertStringNotContainsString('"><x', $html);
    }

    public function test_form_has_message_autosubmit_and_noscript_fallback(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $html = skilland_render_sso_post_form('tok', '/dashboard');

        // The test get_string() stub returns the identifier.
        $this->assertStringContainsString('<p>sso_redirecting</p>', $html);
        $this->assertMatchesRegularExpression('#<noscript>\s*<button type="submit">sso_continue</button>\s*</noscript>#', $html);
        $this->assertMatchesRegularExpression('#<script>[^<]*\.submit\(\);?[^<]*</script>#', $html);
    }

    public function test_form_never_puts_token_in_a_url(): void {
        $this->config(['frontend_url' => 'https://app.skilland.com']);

        $html = skilland_render_sso_post_form('secret-token', '/dashboard');

        preg_match_all('/\b(?:href|action|src)="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            $this->assertStringNotContainsString('token=', $url);
            $this->assertStringNotContainsString('secret-token', $url);
        }
        $this->assertStringNotContainsString('token=', $html);
    }

    public function test_old_get_url_builder_is_gone(): void {
        $this->assertFalse(function_exists('skilland_get_sso_url'));
    }
}
