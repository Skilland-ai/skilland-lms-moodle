<?php

namespace mod_skilland\tests;

use mod_skilland\local\skilland_url;
use mod_skilland\rest_exception;
use PHPUnit\Framework\TestCase;

/**
 * The REST transport (SKL-963): retries, POST semantics, error answers, logging and the
 * Skilland URL normaliser.
 */
class locallib_rest_test extends TestCase {

    /** @var \recording_retry_sleeper Records the retry delays instead of sleeping. */
    private $sleeper;

    protected function setUp(): void {
        parent::setUp();
        \core\di::reset_container();
        $this->sleeper = new \recording_retry_sleeper();
        \core\di::set(\mod_skilland\local\retry_sleeper::class, $this->sleeper);
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_events'] = [];
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://app.skilland.test',
        ];
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_requests'],
            $GLOBALS['_test_curl_last'], $GLOBALS['_test_curl_posts'], $GLOBALS['_test_event_trigger_throw'],
            $GLOBALS['CFG']->mod_skilland_allow_http);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_requests'],
            $GLOBALS['_test_curl_last'], $GLOBALS['_test_curl_posts'], $GLOBALS['_test_events'],
            $GLOBALS['_test_event_trigger_throw']);
        \core\di::reset_container();
        $GLOBALS['_test_plugin_config'] = [];
        parent::tearDown();
    }

    private function devmode(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->devmode = 1;
        \mod_skilland\logger::reset_cache();
    }

    private function queue(array ...$responses): void {
        $GLOBALS['_test_curl_responses'] = $responses;
        $GLOBALS['_test_curl_requests'] = [];
    }

    private static function resp(int $code, string $body = '', int $errno = 0, array $headers = [],
            string $error = ''): array {
        return ['body' => $body, 'http_code' => $code, 'errno' => $errno, 'error' => $error, 'headers' => $headers];
    }

    private static function ok(): array {
        return self::resp(200, json_encode(['skills' => [['id' => '1']]]));
    }

    private function requests(): int {
        return count($GLOBALS['_test_curl_requests'] ?? []);
    }

    private function log(): string {
        return implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
    }

    private function failure_event(): \mod_skilland\event\api_request_failed {
        $this->assertCount(1, $GLOBALS['_test_events']);
        $event = $GLOBALS['_test_events'][0];
        $this->assertInstanceOf(\mod_skilland\event\api_request_failed::class, $event);
        $this->assertSame(['path', 'httpcode', 'errorclass'], array_keys($event->other));
        return $event;
    }

    public function test_get_failure_emits_one_safe_event_after_retry_exhaustion(): void {
        $this->queue(self::resp(503, 'secret response'), self::resp(503), self::resp(504));

        $this->get_failure();

        $this->assertSame(3, $this->requests());
        $event = $this->failure_event();
        $this->assertSame(['path' => '/api/moodle/skills', 'httpcode' => 504,
            'errorclass' => 'http'], $event->other);
        $this->assertSame('r', $event->crud);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
        $this->assertSame('A Skilland API request to /api/moodle/skills failed (HTTP 504, http).',
            $event->get_description());
    }

    public function test_post_http_error_event_excludes_body_and_credentials(): void {
        $this->queue(self::resp(400, '{"error":"secret key1 teacher@example.com"}'));

        $this->post_failure();

        $event = $this->failure_event();
        $this->assertSame(['path' => '/api/moodle/skills', 'httpcode' => 400,
            'errorclass' => 'http'], $event->other);
        $this->assertStringNotContainsString('secret', json_encode($event->get_data()));
        $this->assertStringNotContainsString('teacher@example.com', json_encode($event->get_data()));
        $this->assertStringNotContainsString('key1', json_encode($event->get_data()));
    }

    public function test_transport_redirect_decode_and_config_failures_are_classified(): void {
        $cases = [
            [self::resp(0, '', 60), 0, 'transport'],
            [self::resp(302), 302, 'redirect'],
            [self::resp(200, '<html>'), 200, 'decode'],
        ];
        foreach ($cases as [$response, $status, $category]) {
            $GLOBALS['_test_events'] = [];
            $this->queue($response);
            try {
                mod_skilland_rest_get('/api/moodle/skills');
                $this->fail('Expected request failure');
            } catch (\moodle_exception $e) {
                // The event must carry only the fixed category and numeric status.
            }
            $this->assertSame($status, $this->failure_event()->other['httpcode']);
            $this->assertSame($category, $this->failure_event()->other['errorclass']);
        }

        $GLOBALS['_test_events'] = [];
        $GLOBALS['_test_plugin_config']['mod_skilland']->apikey = '';
        try {
            mod_skilland_rest_post('/api/moodle/skills', []);
            $this->fail('Expected configuration failure');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_config_missing_apikey', $e->errorcode);
        }
        $this->assertSame('config', $this->failure_event()->other['errorclass']);
    }

    public function test_variable_path_segments_and_query_are_redacted(): void {
        $this->queue(self::resp(400));
        try {
            mod_skilland_rest_get('/api/moodle/topics/teacher%40example.com/scorm-hash?token=secret');
            $this->fail('Expected HTTP failure');
        } catch (rest_exception $e) {
            $this->assertSame(400, $e->httpcode);
        }

        $this->assertSame('/api/moodle/topics/[redacted]/scorm-hash', $this->failure_event()->other['path']);
        $this->assertSame('A Skilland API request to /api/moodle/topics/[redacted]/scorm-hash failed ' .
            '(HTTP 400, http).', $this->failure_event()->get_description());
        $this->assertStringNotContainsString('teacher', $this->failure_event()->get_description());
        $this->assertStringNotContainsString('secret', $this->failure_event()->get_description());
    }

    public function test_event_storage_failure_preserves_original_request_failure(): void {
        $this->queue(self::resp(400));
        $GLOBALS['_test_event_trigger_throw'] = true;

        $error = $this->get_failure();

        $this->assertSame(400, $error->httpcode);
        $this->assertSame([], $GLOBALS['_test_events']);
    }

    private function get_failure(): rest_exception {
        try {
            mod_skilland_rest_get('/api/moodle/skills?status=all');
        } catch (rest_exception $e) {
            return $e;
        }
        $this->fail('Expected rest_exception');
    }

    private function post_failure(): rest_exception {
        try {
            mod_skilland_rest_post('/api/moodle/skills', ['name' => 'N']);
        } catch (rest_exception $e) {
            return $e;
        }
        $this->fail('Expected rest_exception');
    }

    // ---------------------------------------------------------------
    // skilland_url::normalise()
    // ---------------------------------------------------------------

    /**
     * @dataProvider stored_urls
     */
    public function test_normalise_turns_a_stored_value_into_the_base_url(string $stored, string $expected): void {
        $this->assertSame($expected, skilland_url::normalise($stored));
    }

    public static function stored_urls(): array {
        return [
            'base url' => ['https://app.skilland.ai', 'https://app.skilland.ai'],
            'trailing slash' => ['https://app.skilland.ai/', 'https://app.skilland.ai'],
            'surrounding whitespace' => ["  https://app.skilland.ai/ \n", 'https://app.skilland.ai'],
            'legacy graphql endpoint' => ['https://api.skilland.ai/graphql', 'https://api.skilland.ai'],
            'legacy graphql with slash' => ['https://api.skilland.ai/graphql/', 'https://api.skilland.ai'],
            'graphql any case' => ['https://api.skilland.ai/GraphQL', 'https://api.skilland.ai'],
            'api prefix' => ['https://app.skilland.ai/api/moodle', 'https://app.skilland.ai'],
            'api prefix with slash' => ['https://app.skilland.ai/api/moodle/', 'https://app.skilland.ai'],
            'port kept' => ['http://host.docker.internal:3100/graphql', 'http://host.docker.internal:3100'],
            'sub path kept' => ['https://example.com/skilland/', 'https://example.com/skilland'],
            'only a trailing suffix is dropped' => ['https://example.com/graphql/app', 'https://example.com/graphql/app'],
            'look-alike suffix kept' => ['https://example.com/mygraphql', 'https://example.com/mygraphql'],
            'empty' => ['', ''],
            'only spaces' => ['   ', ''],
        ];
    }

    public function test_legacy_skilland_url_reaches_the_rest_api_on_its_host(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->graphql_endpoint = ' https://api.skilland.test/graphql/ ';
        $this->queue(self::ok());

        mod_skilland_rest_get('/api/moodle/skills');

        $this->assertSame(['https://api.skilland.test/api/moodle/skills'], $GLOBALS['_test_curl_requests']);
    }

    public function test_frontend_url_overrides_the_skilland_url(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->frontend_url = 'https://learn.example/';
        $this->queue(self::ok());

        mod_skilland_rest_get('/api/moodle/skills');

        $this->assertSame(['https://learn.example/api/moodle/skills'], $GLOBALS['_test_curl_requests']);
    }

    // ---------------------------------------------------------------
    // mod_skilland_rest_get_http() — retries
    // ---------------------------------------------------------------

    public function test_get_503_then_200_retries_once(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        $data = mod_skilland_rest_get('/api/moodle/skills');

        $this->assertSame('1', $data['skills'][0]['id']);
        $this->assertSame(2, $this->requests());
        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(250, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual(500, $this->sleeper->sleeps[0]);
        $this->assertSame([], $GLOBALS['_test_events']);
    }

    public function test_get_retry_is_logged_without_the_query_string(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        mod_skilland_rest_get('/api/moodle/users/courses?email=teacher%40example.com');

        $warnings = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => strpos($m['message'], 'Retrying') !== false));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('after HTTP 503 (attempt 2/3)', $warnings[0]['message']);
        $this->assertStringNotContainsString('teacher', $this->log());
    }

    public function test_get_gateway_errors_give_up_after_three_attempts(): void {
        $this->queue(self::resp(502), self::resp(504), self::resp(504));

        $e = $this->get_failure();

        $this->assertSame('error_graphql_http', $e->errorcode);
        $this->assertSame(504, $e->httpcode);
        $this->assertSame('HTTP 504', $e->a);
        $this->assertSame(3, $this->requests());
        $this->assertCount(2, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(500, $this->sleeper->sleeps[1]);
        $this->assertLessThanOrEqual(750, $this->sleeper->sleeps[1]);
    }

    public function test_get_connection_refused_then_200_succeeds(): void {
        $this->queue(self::resp(0, '', 7), self::ok());

        mod_skilland_rest_get('/api/moodle/skills');

        $this->assertSame(2, $this->requests());
    }

    public function test_get_tls_certificate_error_is_not_retried(): void {
        $this->queue(self::resp(0, '', 60), self::ok());

        $this->assertSame(0, $this->get_failure()->httpcode);
        $this->assertSame(1, $this->requests());
    }

    /**
     * @dataProvider retry_after_cases
     */
    public function test_get_retry_after_on_429_and_503(int $code, array $headers, int $min, int $max): void {
        $this->queue(self::resp($code, '', 0, $headers), self::ok());

        mod_skilland_rest_get('/api/moodle/skills');

        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual($min, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual($max, $this->sleeper->sleeps[0]);
    }

    public static function retry_after_cases(): array {
        return [
            '429 two seconds' => [429, ['Retry-After' => '2'], 2000, 2000],
            '429 lower-case header' => [429, ['retry-after' => '2'], 2000, 2000],
            '503 capped at five seconds' => [503, ['Retry-After' => '60'], 5000, 5000],
            '429 http-date is ignored' => [429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 250, 500],
            '429 garbage is ignored' => [429, ['Retry-After' => '1.5s'], 250, 500],
            '502 ignores retry-after' => [502, ['Retry-After' => '2'], 250, 500],
        ];
    }

    public function test_get_500_is_retried(): void {
        $this->queue(self::resp(500, 'Internal Server Error'), self::ok());

        mod_skilland_rest_get('/api/moodle/skills');

        $this->assertSame(2, $this->requests());
    }

    /**
     * @dataProvider non_retried_client_errors
     */
    public function test_get_client_errors_are_not_retried(int $code): void {
        $this->queue(self::resp($code, 'nope'), self::ok());

        $this->assertSame($code, $this->get_failure()->httpcode);
        $this->assertSame(1, $this->requests());
    }

    public static function non_retried_client_errors(): array {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '409' => [409]];
    }

    // ---------------------------------------------------------------
    // mod_skilland_rest_post_http()
    // ---------------------------------------------------------------

    public function test_post_sends_json_with_the_bearer_key(): void {
        $this->queue(self::resp(201, json_encode(['id' => 's1', 'path' => '/skills/new?draft=s1', 'name' => 'N'])));

        $answer = mod_skilland_rest_post('/api/moodle/skills', ['name' => 'N', 'userEmail' => 'u@example.com']);

        $this->assertSame(['id' => 's1', 'path' => '/skills/new?draft=s1', 'name' => 'N'], $answer);
        $this->assertSame([['url' => 'https://app.skilland.test/api/moodle/skills',
            'body' => '{"name":"N","userEmail":"u@example.com"}']], $GLOBALS['_test_curl_posts']);
        $headers = $GLOBALS['_test_curl_last']['headers'];
        $this->assertContains('Authorization: Bearer key1', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('Accept: application/json', $headers);
    }

    public function test_post_empty_body_is_an_empty_object(): void {
        $this->queue(self::resp(200, '{}'));

        mod_skilland_rest_post('/api/moodle/skills', []);

        $this->assertSame('{}', $GLOBALS['_test_curl_last']['body']);
    }

    /**
     * @dataProvider transient_failures
     */
    public function test_post_is_never_retried(array $failure): void {
        $this->queue($failure, self::resp(201, json_encode(['id' => 's1'])));

        $this->post_failure();

        $this->assertSame(1, $this->requests());
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public static function transient_failures(): array {
        return [
            '503' => [self::resp(503, 'busy', 0, ['Retry-After' => '1'])],
            '502' => [self::resp(502)],
            '500' => [self::resp(500)],
            '429' => [self::resp(429)],
            'connection refused' => [self::resp(0, '', 7)],
            'timeout' => [self::resp(0, '', 28)],
        ];
    }

    public function test_post_error_answer_carries_its_error_code(): void {
        $this->queue(self::resp(409, json_encode(['error' => 'name_taken'])));

        $e = $this->post_failure();

        $this->assertSame(409, $e->httpcode);
        $this->assertSame('name_taken', $e->apierror);
        $this->assertSame('HTTP 409', $e->a);
    }

    /**
     * @dataProvider unusable_error_bodies
     */
    public function test_post_error_without_a_usable_error_code(string $body): void {
        $this->queue(self::resp(400, $body));

        $this->assertSame('', $this->post_failure()->apierror);
    }

    public static function unusable_error_bodies(): array {
        return [
            'empty' => [''],
            'html' => ['<html>Bad Request</html>'],
            'non-string error' => [json_encode(['error' => ['code' => 'x']])],
            'no error member' => [json_encode(['message' => 'x'])],
        ];
    }

    public function test_post_invalid_json_answer_throws(): void {
        $this->queue(self::resp(201, '<html>'));

        $e = $this->post_failure();

        $this->assertSame('error_graphql_invalid_json', $e->errorcode);
        $this->assertSame(201, $e->httpcode);
    }

    public function test_post_without_apikey_fails_before_any_request(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->apikey = '';

        try {
            mod_skilland_rest_post('/api/moodle/skills', ['name' => 'N']);
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_config_missing_apikey', $e->errorcode);
        }
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_post_logs_field_names_but_never_values_or_the_key(): void {
        $this->devmode();
        $this->queue(self::resp(201, json_encode(['id' => 's1'])));

        mod_skilland_rest_post('/api/moodle/skills', ['name' => 'Secret course', 'userEmail' => 'teacher@example.com']);

        $log = $this->log();
        $this->assertStringContainsString('name, userEmail', $log);
        $this->assertStringNotContainsString('teacher@example.com', $log);
        $this->assertStringNotContainsString('Secret course', $log);
        $this->assertStringNotContainsString('key1', $log);
    }

    // ---------------------------------------------------------------
    // Failure logging (both methods)
    // ---------------------------------------------------------------

    public function test_body_snippet_goes_to_the_debug_log_only(): void {
        $this->devmode();
        $this->queue(self::resp(401, '<html>BODYSECRET ' . str_repeat('x', 500) . '</html>'));

        $e = $this->get_failure();

        foreach ([(string) $e->a, $e->getMessage(), (string) $e->debuginfo] as $text) {
            $this->assertStringNotContainsString('BODYSECRET', $text);
            $this->assertStringNotContainsString('skilland.test', $text);
        }
        $this->assertStringContainsString('BODYSECRET', $this->log());
        $this->assertStringNotContainsString(str_repeat('x', 200), $this->log());
    }

    public function test_body_snippet_is_not_logged_without_devmode(): void {
        $this->queue(self::resp(401, 'BODYSECRET'));

        $this->post_failure();

        $this->assertStringNotContainsString('BODYSECRET', $this->log());
    }

    public function test_connection_refused_keeps_details_out_of_the_exception(): void {
        $this->queue(self::resp(0, '', 7, [], 'Connection refused'));

        $e = $this->post_failure();

        $this->assertSame('HTTP 0', $e->a);
        $this->assertSame(0, $e->httpcode);
        $this->assertStringNotContainsString('Connection refused', $e->getMessage());
        $this->assertStringContainsString('Connection refused', $this->log());
        $this->assertStringNotContainsString('host.docker.internal', $this->log());
    }

    public function test_connection_refused_on_localhost_logs_the_docker_hint_in_devmode(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $GLOBALS['_test_plugin_config']['mod_skilland']->graphql_endpoint = 'http://localhost:3100';
        $this->devmode();
        $this->queue(self::resp(0, '', 7, [], 'Connection refused'));

        $this->post_failure();

        $this->assertStringContainsString('host.docker.internal', $this->log());
        $this->assertStringContainsString('Skilland URL setting', $this->log());
        $this->assertStringNotContainsString('Array (', $this->log());
    }

    // ---------------------------------------------------------------
    // Pure helpers
    // ---------------------------------------------------------------

    public function test_retry_delay_ms_bounds(): void {
        for ($i = 0; $i < 50; $i++) {
            $first = mod_skilland_retry_delay_ms(1, null);
            $second = mod_skilland_retry_delay_ms(2, null);
            $this->assertGreaterThanOrEqual(250, $first);
            $this->assertLessThanOrEqual(500, $first);
            $this->assertGreaterThanOrEqual(500, $second);
            $this->assertLessThanOrEqual(750, $second);
        }
        $this->assertSame(0, mod_skilland_retry_delay_ms(1, '0'));
        $this->assertSame(3000, mod_skilland_retry_delay_ms(2, ' 3 '));
        $this->assertSame(5000, mod_skilland_retry_delay_ms(1, '3600'));
    }

    /**
     * @dataProvider transient_cases
     */
    public function test_is_transient(int $code, int $errno, bool $expected): void {
        $this->assertSame($expected, mod_skilland_is_transient($code, $errno));
    }

    public static function transient_cases(): array {
        return [
            'dns' => [0, 6, true],
            'refused' => [0, 7, true],
            'timeout' => [0, 28, true],
            'tls handshake' => [0, 35, true],
            'empty reply' => [0, 52, true],
            'receive error' => [0, 56, true],
            'certificate' => [0, 60, false],
            'no errno' => [0, 0, false],
            '429' => [429, 0, true],
            '500' => [500, 0, true],
            '502' => [502, 0, true],
            '503' => [503, 0, true],
            '504' => [504, 0, true],
            '400' => [400, 0, false],
            '401' => [401, 0, false],
            '404' => [404, 0, false],
            '409' => [409, 0, false],
        ];
    }

    /**
     * @dataProvider local_hosts
     */
    public function test_local_hosts_are_local(string $host): void {
        $this->assertTrue(mod_skilland_is_local_host($host));
    }

    public static function local_hosts(): array {
        return [
            'localhost' => ['localhost'],
            'localhost uppercase' => ['LOCALHOST'],
            'host.docker.internal' => ['host.docker.internal'],
            'docker service' => ['skilland-back'],
            'loopback' => ['127.0.0.1'],
            'ipv6 loopback' => ['::1'],
            'ipv6 loopback bracketed' => ['[::1]'],
            '172.16/12 start' => ['172.16.0.1'],
            '172.17 docker bridge' => ['172.17.0.2'],
            '10/8' => ['10.0.0.5'],
            '192.168/16' => ['192.168.1.100'],
            'link-local metadata' => ['169.254.169.254'],
        ];
    }

    /**
     * @dataProvider public_hosts
     */
    public function test_public_hosts_are_not_local(string $host): void {
        $this->assertFalse(mod_skilland_is_local_host($host));
    }

    public static function public_hosts(): array {
        return [
            'skilland production' => ['app.skilland.ai'],
            'public hostname containing skilland' => ['evil-skilland.com'],
            'public 172 outside 172.16/12' => ['172.217.0.1'],
            'public dns' => ['8.8.8.8'],
            'public hostname' => ['api.example.com'],
            'prefix look-alike' => ['10.evil.com'],
            'empty' => [''],
        ];
    }
}
