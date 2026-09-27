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

    public function test_download_accepts_the_frontend_host_even_off_the_package_hosts_list(): void {
        $this->config('', ['frontend_url' => 'https://learn.customer.example', 'package_hosts' => '']);
        $this->respond(200, $this->zipbytes());

        $path = mod_skilland_download_package('https://learn.customer.example/api/moodle/packages/p.zip?sig=1');

        @unlink($path);
        $this->assertSame(['https://learn.customer.example/api/moodle/packages/p.zip?sig=1'],
            $GLOBALS['_test_curl_requests']);
    }

    public function test_download_accepts_presigned_s3_urls_by_default(): void {
        $this->config('', ['frontend_url' => 'https://app.skilland.ai']);
        foreach (['https://skilland-scorm.s3.eu-west-1.amazonaws.com/t/p.zip?X-Amz-Signature=a',
                'https://s3.eu-west-1.amazonaws.com/skilland-scorm/t/p.zip?X-Amz-Signature=a'] as $url) {
            $this->respond(200, $this->zipbytes());
            @unlink(mod_skilland_download_package($url));
        }
        $this->assertCount(2, $GLOBALS['_test_curl_requests']);
    }

    public function test_download_frontend_host_does_not_admit_other_hosts(): void {
        $this->config('', ['frontend_url' => 'https://learn.customer.example', 'package_hosts' => '']);
        $this->expect_code(fn() => mod_skilland_download_package('https://evil.example/p.zip'),
            'error_package_host_not_allowed');
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_download_local_frontend_host_needs_the_dev_flag(): void {
        $this->config('', ['frontend_url' => 'https://localhost:3000', 'package_hosts' => '']);
        $this->expect_code(fn() => mod_skilland_download_package('https://localhost:3000/p.zip'),
            'error_package_host_not_allowed');
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
    // ---------------------------------------------------------------
    // Adversarial cases (SKL-656 review)
    // ---------------------------------------------------------------

    /** A stored (uncompressed) zip holding one entry, built byte by byte. */
    private function stored_zip(string $name, string $content): string {
        $crc = crc32($content);
        $len = strlen($content);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $len, $len, strlen($name), 0) . $name;
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $len, $len,
            strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        $offset = strlen($local) + $len;
        $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), $offset, 0);
        return $local . $content . $central . $eocd;
    }

    /** A valid stored zip of exactly $bytes bytes. */
    private function zip_of_size(int $bytes): string {
        $name = 'imsmanifest.xml';
        $overhead = strlen($this->stored_zip($name, ''));
        $zip = $this->stored_zip($name, str_repeat('x', $bytes - $overhead));
        $this->assertSame($bytes, strlen($zip));
        return $zip;
    }

    /**
     * @dataProvider default_pattern_cases
     */
    public function test_default_amazonaws_wildcard(string $url, bool $allowed): void {
        $this->assertSame($allowed, mod_skilland_package_host_allowed($url, 'https://api.skilland.ai/graphql'));
    }

    public static function default_pattern_cases(): array {
        return [
            'wildcard base itself' => ['https://amazonaws.com/p.zip', false],
            'suffix look-alike' => ['https://amazonaws.com.evil.com/p.zip', false],
            'no dot boundary' => ['https://xamazonaws.com/p.zip', false],
            'uppercase host' => ['https://BUCKET.S3.AMAZONAWS.COM/p.zip', true],
            'userinfo trick resolves to evil.com' => ['https://bucket.amazonaws.com@evil.com/p.zip', false],
            'trailing dot on unlisted host' => ['https://evil.com./p.zip', false],
            'ipv6 loopback literal' => ['https://[::1]/p.zip', false],
            'public ip literal' => ['https://52.95.110.1/p.zip', false],
            'no host' => ['/p.zip', false],
            'empty' => ['', false],
        ];
    }

    public function test_userinfo_url_reports_real_host(): void {
        $this->config('https://api.skilland.ai/graphql');
        $e = $this->expect_code(
            fn() => mod_skilland_download_package('https://bucket.amazonaws.com@evil.com/p.zip'), 'error_package_host_not_allowed');
        $this->assertSame('evil.com', $e->a);
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    /**
     * @dataProvider permissive_settings
     */
    public function test_permissive_package_hosts_never_admit_local_targets_outside_dev(string $setting): void {
        set_config('package_hosts', $setting, 'mod_skilland');
        $endpoint = 'https://api.skilland.ai/graphql';
        foreach (['https://localhost/p.zip', 'https://169.254.169.254/latest', 'https://10.0.0.5/p.zip',
                'https://8.8.8.8/p.zip', 'https://[::1]/p.zip', 'https://host.docker.internal/p.zip'] as $url) {
            $this->assertFalse(mod_skilland_package_host_allowed($url, $endpoint), "$setting admitted $url");
        }
    }

    public static function permissive_settings(): array {
        return [
            'star' => ['*'],
            'localhost' => ['localhost'],
            'localhost and metadata' => ['localhost, 169.254.169.254, [::1], host.docker.internal, 10.0.0.5, 8.8.8.8'],
        ];
    }

    public function test_star_setting_is_not_a_wildcard(): void {
        set_config('package_hosts', '*', 'mod_skilland');
        $this->assertFalse(mod_skilland_package_host_allowed('https://evil.com/p.zip', 'https://api.skilland.ai/graphql'));
    }

    public function test_local_endpoint_host_does_not_admit_packages_outside_dev(): void {
        $this->assertFalse(mod_skilland_package_host_allowed('https://localhost/p.zip', 'https://localhost/graphql'));
    }

    public function test_dev_flag_admits_local_package_hosts(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->assertTrue(mod_skilland_package_host_allowed('http://localhost:8000/p.zip', 'http://localhost:8000/graphql'));
        $this->assertTrue(mod_skilland_package_host_allowed('http://skilland-back/p.zip', 'https://api.skilland.ai/graphql'));
        $this->assertFalse(mod_skilland_package_host_allowed('http://8.8.8.8/p.zip', 'https://api.skilland.ai/graphql'));
    }

    public function test_endpoint_host_match_ignores_port(): void {
        // Host allowlisting is by host name; Moodle's blocked-ports list still applies to the request.
        $this->assertTrue(mod_skilland_package_host_allowed(
            'https://api.example.org:8443/p.zip', 'https://api.example.org/graphql'));
        $this->assertTrue(mod_skilland_package_host_allowed(
            'https://API.example.org./p.zip', 'https://api.example.org/graphql'));
    }

    /**
     * @dataProvider https_cases
     */
    public function test_require_https(string $url, bool $ok): void {
        if ($ok) {
            mod_skilland_require_https($url, 'endpoint');
            $this->addToAssertionCount(1);
        } else {
            $e = $this->expect_code(fn() => mod_skilland_require_https($url, 'endpoint'), 'error_insecure_url');
            $this->assertSame('endpoint', $e->a);
        }
    }

    public static function https_cases(): array {
        return [
            'lowercase https' => ['https://api.skilland.ai/graphql', true],
            'uppercase HTTPS' => ['HTTPS://api.skilland.ai/graphql', true],
            'http' => ['http://api.skilland.ai/graphql', false],
            'ftp' => ['ftp://api.skilland.ai/p.zip', false],
            'no scheme' => ['api.skilland.ai/graphql', false],
            'protocol-relative' => ['//api.skilland.ai/graphql', false],
            'empty' => ['', false],
        ];
    }

    public function test_require_https_relaxed_by_dev_flag(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        mod_skilland_require_https('http://localhost:8000/graphql', 'endpoint');
        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider redirect_codes
     */
    public function test_graphql_redirects_make_exactly_one_request(int $code): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond($code);

        $e = $this->expect_code(fn() => mod_skilland_graphql('{ ok }'), 'error_http_redirect');
        $this->assertSame($code, $e->a);
        $this->assertSame(['https://api.skilland.ai/graphql'], $GLOBALS['_test_curl_requests']);
    }

    public static function redirect_codes(): array {
        return ['301' => [301], '307' => [307], '308' => [308]];
    }

    public function test_graphql_curl_error_surfaces_as_exception(): void {
        $this->config('https://api.skilland.ai/graphql');
        $GLOBALS['_test_curl_response'] = ['body' => '', 'http_code' => 0, 'errno' => 60, 'error' => 'SSL certificate problem'];
        $GLOBALS['_test_debug_messages'] = [];

        $e = $this->expect_code(fn() => mod_skilland_graphql('{ ok }'), 'error_graphql_http');
        // SKL-670: the exception carries only the status; the curl details go to the log.
        $this->assertSame('HTTP 0', $e->a);
        $log = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('SSL certificate problem', $log);
        $this->assertStringContainsString('errno: 60', $log);
    }

    public function test_download_404_throws_and_removes_temp_file(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(404, 'not found');
        $before = $this->tempfiles();
        $e = $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_scorm_download_failed');
        $this->assertSame('HTTP 404', $e->a);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_empty_body_throws_and_removes_temp_file(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, '');
        $before = $this->tempfiles();
        $e = $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_scorm_download_failed');
        $this->assertSame('Empty file', $e->a);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_curl_error_with_200_throws_and_removes_temp_file(): void {
        $this->config('https://api.skilland.ai/graphql');
        // CURLE_FILESIZE_EXCEEDED (63) after a partial body.
        $GLOBALS['_test_curl_response'] = ['body' => $this->zipbytes(), 'http_code' => 200, 'errno' => 63, 'error' => 'Maximum file size exceeded'];
        $before = $this->tempfiles();
        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_scorm_download_failed');
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_with_matching_size_passes(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, $this->zipbytes());

        $path = mod_skilland_download_package('https://cdn.skilland.ai/p.zip', strlen($this->zipbytes()));
        try {
            $this->assertSame(strlen($this->zipbytes()), filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_download_one_byte_short_of_expected_size_fails_and_removes_temp_file(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, $this->zipbytes());
        $before = $this->tempfiles();

        $e = $this->expect_code(
            fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip', strlen($this->zipbytes()) + 1),
            'error_scorm_download_failed');

        $this->assertSame('Size mismatch', $e->a);
        $this->assertSame($before, $this->tempfiles());
    }

    public function test_download_expected_size_zero_skips_the_check(): void {
        $this->config('https://api.skilland.ai/graphql');
        $this->respond(200, $this->zipbytes());

        $path = mod_skilland_download_package('https://cdn.skilland.ai/p.zip', 0);
        $this->assertFileExists($path);
        @unlink($path);
    }

    public function test_download_is_not_retried(): void {
        $this->config('https://api.skilland.ai/graphql');
        $GLOBALS['_test_curl_requests'] = [];
        $this->respond(503, 'busy');

        $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'),
            'error_scorm_download_failed');
        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_download_exactly_at_cap_is_accepted(): void {
        $this->config('https://api.skilland.ai/graphql', ['package_max_mb' => 1]);
        $this->respond(200, $this->zip_of_size(1024 * 1024));

        $path = mod_skilland_download_package('https://cdn.skilland.ai/p.zip');
        try {
            $this->assertSame(1024 * 1024, filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_download_one_byte_over_cap_is_rejected(): void {
        $this->config('https://api.skilland.ai/graphql', ['package_max_mb' => 1]);
        $this->respond(200, $this->zip_of_size(1024 * 1024 + 1));
        $before = $this->tempfiles();
        $e = $this->expect_code(fn() => mod_skilland_download_package('https://cdn.skilland.ai/p.zip'), 'error_package_too_large');
        $this->assertSame(1, $e->a);
        $this->assertSame($before, $this->tempfiles());
    }

    /**
     * @dataProvider unusable_max_mb
     */
    public function test_download_defaults_to_200_mb_cap($value): void {
        $this->config('https://api.skilland.ai/graphql', $value === 'unset' ? [] : ['package_max_mb' => $value]);
        $this->respond(200, $this->zipbytes());

        $path = mod_skilland_download_package('https://cdn.skilland.ai/p.zip');
        @unlink($path);
        $this->assertSame(200 * 1024 * 1024, $GLOBALS['_test_curl_last']['options']['CURLOPT_MAXFILESIZE']);
    }

    public static function unusable_max_mb(): array {
        return ['unset' => ['unset'], 'empty' => [''], 'zero' => ['0'], 'negative' => ['-5'], 'text' => ['abc']];
    }

    public function test_download_uses_skilland_scorm_temp_directory(): void {
        $source = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertMatchesRegularExpression(
            "/function mod_skilland_download_package\(.*?tempnam\(make_temp_directory\('skilland_scorm'\)/s", $source);
    }

    public function test_provision_topic_scorm_still_verifies_hash_after_download(): void {
        // SKL-663 moved the download and hash check into skilland_download_topic_scorm_package().
        $source = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertSame(1, preg_match('/function skilland_download_topic_scorm_package\(.*?\n}\n/s', $source, $m));
        $body = $m[0];
        $download = strpos($body, 'mod_skilland_download_package($packageurl, (int) ($scorminfo[\'packageSize\'] ?? 0))');
        $hash = strpos($body, 'hash_file($algorithm, $tempfile)');
        $this->assertNotFalse($download);
        $this->assertNotFalse($hash);
        $this->assertGreaterThan($download, $hash);
        $this->assertStringContainsString("'sha256'", $body);
        $this->assertStringContainsString('error_scorm_hash_mismatch', $body);
        $this->assertStringNotContainsString('new \curl(', $body);
    }

    public function test_no_outbound_curl_ignores_security_unconditionally(): void {
        $source = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertSame(1, substr_count($source, "'ignoresecurity' => true"));
        $this->assertStringNotContainsString("'CURLOPT_FOLLOWLOCATION' => true", $source);
    }

    /**
     * @dataProvider boundary_hosts
     */
    public function test_is_local_host_boundaries(string $host, bool $local): void {
        $this->assertSame($local, mod_skilland_is_local_host($host));
    }

    public static function boundary_hosts(): array {
        return [
            '172.15.255.255 public' => ['172.15.255.255', false],
            '172.31.255.255 private' => ['172.31.255.255', true],
            '172.32.0.1 public' => ['172.32.0.1', false],
            '169.254.1.1 link-local' => ['169.254.1.1', true],
            'fc00::1 unique local' => ['fc00::1', true],
            // PHP 8.2's FILTER_FLAG_NO_RES_RANGE covers the 2001:db8::/32 documentation range (RFC 6890).
            '2001:db8::1 documentation range' => ['2001:db8::1', true],
            '2606:4700::1111 public ipv6' => ['2606:4700::1111', false],
            '0.0.0.0' => ['0.0.0.0', true],
            'localhost with trailing space' => [' localhost ', true],
            'localhost.evil.com' => ['localhost.evil.com', false],
        ];
    }

    public function test_new_lang_keys_exist_in_en_and_es_with_matching_placeholders(): void {
        $string = [];
        require __DIR__ . '/../../src/lang/en/skilland.php';
        $en = $string;
        $string = [];
        require __DIR__ . '/../../src/lang/es/skilland.php';
        $es = $string;

        $keys = ['settings_package_hosts', 'settings_package_hosts_desc', 'settings_package_max_mb',
            'settings_package_max_mb_desc', 'error_insecure_url', 'error_http_redirect',
            'error_package_host_not_allowed', 'error_package_too_large', 'error_package_not_zip'];
        foreach ($keys as $key) {
            $this->assertNotEmpty($en[$key] ?? '', "EN missing $key");
            $this->assertNotEmpty($es[$key] ?? '', "ES missing $key");
            $this->assertNotSame($en[$key], $es[$key], "ES $key is untranslated");
            $this->assertSame(substr_count($en[$key], '{$a}'), substr_count($es[$key], '{$a}'), "Placeholder mismatch in $key");
        }
    }

    public function test_docs_mention_package_hosts(): void {
        $root = __DIR__ . '/../..';
        $this->assertStringContainsString('SCORM package hosts', file_get_contents($root . '/README.md'));
        $this->assertStringContainsString('mod_skilland/package_hosts', file_get_contents($root . '/DEVELOPMENT.md'));
        $this->assertStringContainsString('package_max_mb', file_get_contents($root . '/DEVELOPMENT.md'));
    }
}
