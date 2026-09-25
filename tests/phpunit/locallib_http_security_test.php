<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Outbound HTTP hardening (SKL-656): curl security, redirects, https and SCORM package checks.
 */
class locallib_http_security_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_last'], $GLOBALS['_test_curl_requests']);
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_last'], $GLOBALS['_test_curl_requests']);
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        parent::tearDown();
    }

    private function config(string $endpoint, array $extra = []): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) array_merge([
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => $endpoint,
        ], $extra);
    }

    private function respond(int $code, string $body = ''): void {
        $GLOBALS['_test_curl_response'] = ['body' => $body, 'http_code' => $code, 'errno' => 0, 'error' => ''];
    }

    private function tempfiles(): array {
        return glob(make_temp_directory('skilland_scorm') . '/skl*') ?: [];
    }

    /** A tiny valid zip holding imsmanifest.xml (php:8.2-cli ships without ext-zip). */
    private function zipbytes(): string {
        return base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
    }

    private function expect_code(callable $fn, string $code): \moodle_exception {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            return $e;
        }
        $this->fail('Expected moodle_exception ' . $code);
    }

    // ---------------------------------------------------------------
    // mod_skilland_graphql()
    // ---------------------------------------------------------------

    public function test_graphql_keeps_curl_security_and_does_not_follow_redirects(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, json_encode(['data' => ['ok' => true]]));

        mod_skilland_graphql('{ ok }');

        $last = $GLOBALS['_test_curl_last'];
        $this->assertArrayNotHasKey('ignoresecurity', $last['settings']);
        $this->assertFalse($last['options']['CURLOPT_FOLLOWLOCATION']);
        $this->assertSame(0, $last['options']['CURLOPT_MAXREDIRS']);
        $this->assertArrayNotHasKey('CURLOPT_PROXY', $last['options']);
        $this->assertArrayNotHasKey('CURLOPT_NOPROXY', $last['options']);
    }

    public function test_graphql_http_endpoint_throws_before_any_request(): void {
        $this->config('http://api.skilland.ai/graphql');
        $this->respond(200, json_encode(['data' => []]));

        $this->expect_code(fn() => mod_skilland_graphql('{ ok }'), 'error_insecure_url');
        $this->assertArrayNotHasKey('_test_curl_last', $GLOBALS);
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_graphql_redirect_is_rejected_and_not_followed(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(302);

        $this->expect_code(fn() => mod_skilland_graphql('{ ok }'), 'error_http_redirect');
        $this->assertSame(['https://api.skilland.ai/graphql'], $GLOBALS['_test_curl_requests']);
    }

    public function test_graphql_dev_local_endpoint_bypasses_security_and_proxy(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->config('http://host.docker.internal:8000/graphql');
        $this->respond(200, json_encode(['data' => []]));

        mod_skilland_graphql('{ ok }');

        $last = $GLOBALS['_test_curl_last'];
        $this->assertTrue($last['settings']['ignoresecurity']);
        $this->assertSame('', $last['options']['CURLOPT_PROXY']);
        $this->assertSame('*', $last['options']['CURLOPT_NOPROXY']);
        $this->assertFalse($last['options']['CURLOPT_FOLLOWLOCATION']);
    }

    public function test_graphql_dev_public_endpoint_keeps_security(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, json_encode(['data' => []]));

        mod_skilland_graphql('{ ok }');

        $last = $GLOBALS['_test_curl_last'];
        $this->assertArrayNotHasKey('ignoresecurity', $last['settings']);
        $this->assertArrayNotHasKey('CURLOPT_PROXY', $last['options']);
    }

    // ---------------------------------------------------------------
    // mod_skilland_package_host_allowed()
    // ---------------------------------------------------------------

    public function test_package_from_endpoint_host_is_allowed(): void {
        $this->assertTrue(mod_skilland_package_host_allowed(
            'https://api.example.org/scorm/1.zip', 'https://api.example.org/graphql'));
    }

    public function test_package_from_default_s3_host_is_allowed(): void {
        $this->assertTrue(mod_skilland_package_host_allowed(
            'https://bucket.s3.eu-west-1.amazonaws.com/p.zip?X-Amz-Signature=abc', 'https://api.skilland.ai/graphql'));
    }

    /**
     * @dataProvider rejected_package_urls
     */
    public function test_package_host_rejected(string $url): void {
        $this->assertFalse(mod_skilland_package_host_allowed($url, 'https://api.skilland.ai/graphql'));
    }

    public static function rejected_package_urls(): array {
        return [
            'metadata ip' => ['https://169.254.169.254/latest/meta-data'],
            'localhost' => ['https://localhost/p.zip'],
            'other domain' => ['https://evil.com/p.zip'],
            'suffix look-alike' => ['https://skilland.ai.evil.com/p.zip'],
            'wildcard base itself' => ['https://skilland.ai/p.zip'],
        ];
    }

    public function test_package_hosts_setting_replaces_defaults(): void {
        set_config('package_hosts', 'CDN.Example.net, *.files.example.org', 'mod_skilland');
        $endpoint = 'https://api.skilland.ai/graphql';
        $this->assertTrue(mod_skilland_package_host_allowed('https://cdn.example.net/p.zip', $endpoint));
        $this->assertTrue(mod_skilland_package_host_allowed('https://a.files.example.org/p.zip', $endpoint));
        $this->assertFalse(mod_skilland_package_host_allowed('https://files.example.org/p.zip', $endpoint));
        $this->assertFalse(mod_skilland_package_host_allowed('https://x.s3.amazonaws.com/p.zip', $endpoint));
    }

    // ---------------------------------------------------------------
    // mod_skilland_download_package()
    // ---------------------------------------------------------------

    public function test_download_rejects_http(): void {
        $this->config('https://api.skilland.ai/graphql');
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('http://cdn.skilland.ai/p.zip'), 'error_insecure_url');
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_rejects_unlisted_host_without_leaking_query(): void {
        $this->config('https://api.skilland.ai/graphql');
        $e = $this->expect_code(
            fn() => mod_skilland_download_package('https://evil.com/p.zip?token=secret'), 'error_package_host_not_allowed');
        $this->assertSame('evil.com', $e->a);
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_download_rejects_html_body(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, '<html>login</html>');
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_package_not_zip');
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_rejects_truncated_zip(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, "PK\x03\x04truncated");
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_package_not_zip');
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_rejects_package_over_cap(): void {
        $this->config('https://api.skilland.ai/graphql', ['package_max_mb' => 1]);
        $this->respond(200, "PK\x03\x04" . str_repeat('x', 1024 * 1024 + 1));
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_package_too_large');
        $this->assertSame(1024 * 1024, $GLOBALS['_test_curl_last']['options']['CURLOPT_MAXFILESIZE']);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_rejects_redirect(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(302);
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_http_redirect');
        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_rejects_http_error_and_empty_file(): void {
        $this->config('https://api.skilland.ai/graphql');
        $before = $this->tempfiles();
        $this->respond(404, 'nope');
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_scorm_download_failed');
        $this->respond(200, '');
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_scorm_download_failed');
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_valid_zip_returns_temp_path(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, $this->zipbytes());

        $path = mod_skilland_download_package('https://bucket.s3.amazonaws.com/topic-abc.zip?sig=1');

        try {
            $this->assertFileExists($path);
            $this->assertStringStartsWith(realpath(make_temp_directory('skilland_scorm')), realpath($path));
            $this->assertStringNotContainsString('topic', basename($path));
            $last = $GLOBALS['_test_curl_last'];
            $this->assertArrayNotHasKey('ignoresecurity', $last['settings']);
            $this->assertFalse($last['options']['CURLOPT_FOLLOWLOCATION']);
            $this->assertSame(200 * 1024 * 1024, $last['options']['CURLOPT_MAXFILESIZE']);
        } finally {
            @unlink($path);
        }
    }
}
