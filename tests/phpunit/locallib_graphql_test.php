<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_graphql_test extends TestCase {

    /** @var \recording_retry_sleeper Records the GraphQL retry delays instead of sleeping. */
    private $sleeper;

    protected function setUp(): void {
        parent::setUp();
        \core\di::reset_container();
        $this->sleeper = new \recording_retry_sleeper();
        \core\di::set(\mod_skilland\local\retry_sleeper::class, $this->sleeper);
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_curl_response']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_requests']);
        \core\di::reset_container();
        parent::tearDown();
    }

    private function setValidConfig(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];
    }

    // ---------------------------------------------------------------
    // mod_skilland_graphql() — config validation
    // ---------------------------------------------------------------

    public function test_missing_orgid_throws(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => '',
            'apikey' => 'key',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_graphql('{ test }');
    }

    public function test_missing_apikey_throws(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => '',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_apikey');

        mod_skilland_graphql('{ test }');
    }

    public function test_missing_endpoint_throws(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => 'key',
            'graphql_endpoint' => '',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_endpoint');

        mod_skilland_graphql('{ test }');
    }

    // ---------------------------------------------------------------
    // mod_skilland_is_local_host() — extracted pure function
    // ---------------------------------------------------------------

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
            'skilland production' => ['api.skilland.ai'],
            'public hostname containing skilland' => ['evil-skilland.com'],
            'public 172 outside 172.16/12' => ['172.217.0.1'],
            'public dns' => ['8.8.8.8'],
            'public hostname' => ['api.example.com'],
            'prefix look-alike' => ['10.evil.com'],
            'empty' => [''],
        ];
    }

    // ---------------------------------------------------------------
    // mod_skilland_map_graphql_error() — error code mapping
    // ---------------------------------------------------------------

    public function test_map_error_missing_org_id(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_map_graphql_error([
            'message' => 'Org ID required',
            'extensions' => ['code' => 'SKILLAND_MISSING_ORG_ID'],
        ]);
    }

    public function test_map_error_missing_api_key(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_apikey');

        mod_skilland_map_graphql_error([
            'message' => 'API key required',
            'extensions' => ['code' => 'SKILLAND_MISSING_API_KEY'],
        ]);
    }

    public function test_map_error_invalid_org_id_format(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Bad format',
            'extensions' => ['code' => 'SKILLAND_INVALID_ORG_ID_FORMAT', 'details' => 'Custom detail'],
        ]);
    }

    public function test_map_error_org_not_found(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Not found',
            'extensions' => ['code' => 'SKILLAND_ORG_NOT_FOUND'],
        ]);
    }

    public function test_map_error_invalid_api_key(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_invalid_credentials');

        mod_skilland_map_graphql_error([
            'message' => 'Invalid key',
            'extensions' => ['code' => 'SKILLAND_INVALID_API_KEY'],
        ]);
    }

    public function test_map_error_org_mismatch(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_invalid_credentials');

        mod_skilland_map_graphql_error([
            'message' => 'Mismatch',
            'extensions' => ['code' => 'SKILLAND_ORG_MISMATCH'],
        ]);
    }

    public function test_map_error_api_key_not_found(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Key not found',
            'extensions' => ['code' => 'SKILLAND_API_KEY_NOT_FOUND'],
        ]);
    }

    public function test_map_error_api_key_inactive(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Key inactive',
                'extensions' => ['code' => 'SKILLAND_API_KEY_INACTIVE'],
            ]);
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_graphql', $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
            $this->assertSame('SKILLAND_API_KEY_INACTIVE', $e->graphqlcode);
        }
    }

    public function test_map_error_unknown_code_uses_message(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Something went wrong',
            'extensions' => ['code' => 'SOME_UNKNOWN_CODE'],
        ]);
    }

    public function test_map_error_uses_details_over_message(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Generic message',
                'extensions' => ['code' => 'SKILLAND_ORG_NOT_FOUND', 'details' => 'Detailed info here'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('Detailed info here', $e->a);
        }
    }

    // ---------------------------------------------------------------
    // mod_skilland_map_graphql_error() — fallback messages (SKL-671)
    //
    // When the API sends no extensions.details, each of these codes falls back to a
    // get_string() lookup (previously a hardcoded English string). The bootstrap stub for
    // get_string() just echoes the identifier back, so asserting $e->a equals that
    // identifier confirms the code path calls get_string() with the right key rather than
    // silently keeping (or reintroducing) a hardcoded string.
    // ---------------------------------------------------------------

    public function test_map_error_invalid_org_id_format_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Bad format',
                'extensions' => ['code' => 'SKILLAND_INVALID_ORG_ID_FORMAT'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_invalid_orgid_format', $e->a);
        }
    }

    public function test_map_error_org_not_found_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Not found',
                'extensions' => ['code' => 'SKILLAND_ORG_NOT_FOUND'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_org_not_found', $e->a);
        }
    }

    public function test_map_error_api_key_not_found_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Key not found',
                'extensions' => ['code' => 'SKILLAND_API_KEY_NOT_FOUND'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_apikey_not_found', $e->a);
        }
    }

    public function test_map_error_api_key_inactive_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Key inactive',
                'extensions' => ['code' => 'SKILLAND_API_KEY_INACTIVE'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_apikey_inactive', $e->a);
        }
    }

    public function test_map_error_invalid_api_key_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Invalid key',
                'extensions' => ['code' => 'SKILLAND_INVALID_API_KEY'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_invalid_apikey', $e->a);
        }
    }

    public function test_map_error_org_mismatch_falls_back_to_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Mismatch',
                'extensions' => ['code' => 'SKILLAND_ORG_MISMATCH'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_invalid_apikey', $e->a);
        }
    }

    public function test_map_error_details_still_take_priority_over_lang_string(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => 'Bad format',
                'extensions' => ['code' => 'SKILLAND_INVALID_ORG_ID_FORMAT', 'details' => 'Custom detail'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('Custom detail', $e->a);
        }
    }

    // ---------------------------------------------------------------
    // mod_skilland_map_graphql_error() — missing keys
    // ---------------------------------------------------------------

    public function test_map_error_no_extensions_key(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Something failed',
        ]);
    }

    public function test_map_error_no_code_in_extensions(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql');

        mod_skilland_map_graphql_error([
            'message' => 'Something failed',
            'extensions' => [],
        ]);
    }

    public function test_map_error_no_message_uses_fallback(): void {
        try {
            mod_skilland_map_graphql_error([
                'extensions' => ['code' => 'SOME_CODE'],
            ]);
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            // The fallback message from get_string is the identifier itself.
            $this->assertEquals('error_graphql', $e->errorcode);
        }
    }

    // ---------------------------------------------------------------
    // mod_skilland_graphql() — HTTP error paths
    // ---------------------------------------------------------------

    public function test_graphql_http_500_throws_error(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => 'Internal Server Error',
            'http_code' => 500,
            'errno' => 0,
            'error' => '',
        ];

        try {
            mod_skilland_graphql('{ test }');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_graphql_http', $e->errorcode);
            $this->assertStringContainsString('HTTP 500', $e->a);
        }
    }

    /**
     * Run a request that fails with curl's connection refused against the localhost endpoint.
     *
     * @param bool $devmode Whether devmode is on.
     * @return \moodle_exception
     */
    private function connection_refused_exception(bool $devmode = false): \moodle_exception {
        $this->setValidConfig();
        $GLOBALS['_test_plugin_config']['mod_skilland']->devmode = $devmode ? 1 : 0;
        \mod_skilland\logger::reset_cache();
        $GLOBALS['_test_curl_response'] = [
            'body' => '',
            'http_code' => 0,
            'errno' => 7,
            'error' => 'Connection refused',
        ];

        try {
            mod_skilland_graphql('{ test }');
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('Expected exception');
    }

    /**
     * All logged debugging() messages as one string.
     *
     * @return string
     */
    private function debug_log(): string {
        return implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
    }

    public function test_graphql_http_0_with_errno7_keeps_details_out_of_exception(): void {
        $e = $this->connection_refused_exception();

        $this->assertEquals('error_graphql_http', $e->errorcode);
        $this->assertSame('HTTP 0', $e->a);
        $this->assertStringNotContainsString('host.docker.internal', $e->getMessage());
        $this->assertStringNotContainsString('localhost', $e->getMessage());
        $this->assertStringNotContainsString('Connection refused', $e->getMessage());
        $this->assertStringNotContainsString('errno', $e->getMessage());
        // The detailed failure is logged, the Docker hint is not (devmode off).
        $this->assertStringContainsString('Connection refused', $this->debug_log());
        $this->assertStringNotContainsString('host.docker.internal', $this->debug_log());
    }

    public function test_graphql_http_0_with_errno7_logs_docker_hint_in_devmode(): void {
        $e = $this->connection_refused_exception(true);

        $this->assertSame('HTTP 0', $e->a);
        $this->assertStringContainsString('host.docker.internal', $this->debug_log());
    }

    public function test_graphql_debug_messages_never_dump_arrays(): void {
        $this->connection_refused_exception(true);

        $this->assertNotEmpty($GLOBALS['_test_debug_messages']);
        $this->assertStringNotContainsString('Array (', $this->debug_log());
        $this->assertStringNotContainsString("Array\n(", $this->debug_log());
    }

    public function test_graphql_http_0_no_errno_shows_connection_failed(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => '',
            'http_code' => 0,
            'errno' => 0,
            'error' => '',
        ];

        try {
            mod_skilland_graphql('{ test }');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertEquals('error_graphql_http', $e->errorcode);
            $this->assertSame('HTTP 0', $e->a);
            $this->assertStringContainsString('failed', $this->debug_log());
        }
    }

    public function test_graphql_invalid_json_response_throws(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => 'not valid json {{{',
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_graphql_invalid_json');

        mod_skilland_graphql('{ test }');
    }

    public function test_graphql_valid_response_returns_data(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => ['courses' => [['id' => '1', 'name' => 'Test']]]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        $result = mod_skilland_graphql('{ courses { id name } }');

        $this->assertIsArray($result);
        $this->assertCount(1, $result['courses']);
        $this->assertEquals('Test', $result['courses'][0]['name']);
    }

    public function test_graphql_response_with_graphql_errors_throws(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode([
                'errors' => [['message' => 'Auth failed', 'extensions' => ['code' => 'SKILLAND_INVALID_API_KEY']]],
            ]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_invalid_credentials');

        mod_skilland_graphql('{ test }');
    }

    public function test_graphql_empty_data_returns_empty_array(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => null]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        $result = mod_skilland_graphql('{ test }');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // Malformed errors members (SKL-659)
    // ---------------------------------------------------------------

    /**
     * @dataProvider graphql_code_cases
     */
    public function test_map_error_carries_the_graphql_code(string $code, string $errorcode): void {
        try {
            mod_skilland_map_graphql_error(['message' => 'x', 'extensions' => ['code' => $code]]);
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame($code, $e->graphqlcode);
        }
    }

    public static function graphql_code_cases(): array {
        return [
            'missing org id' => ['SKILLAND_MISSING_ORG_ID', 'error_config_missing_orgid'],
            'missing api key' => ['SKILLAND_MISSING_API_KEY', 'error_config_missing_apikey'],
            'invalid api key' => ['SKILLAND_INVALID_API_KEY', 'error_config_invalid_credentials'],
            'org mismatch' => ['SKILLAND_ORG_MISMATCH', 'error_config_invalid_credentials'],
            'org not found' => ['SKILLAND_ORG_NOT_FOUND', 'error_graphql'],
            'unknown' => ['SCORM_NOT_AVAILABLE', 'error_graphql'],
            'no code' => ['', 'error_graphql'],
        ];
    }

    public function test_map_error_tolerates_non_array_extensions(): void {
        try {
            mod_skilland_map_graphql_error(['message' => 'Broken', 'extensions' => 'not-an-object']);
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_graphql', $e->errorcode);
            $this->assertSame('', $e->graphqlcode);
            $this->assertSame('Broken', $e->a);
        }
    }

    public function test_map_error_tolerates_non_string_fields(): void {
        try {
            mod_skilland_map_graphql_error([
                'message' => ['nested'],
                'extensions' => ['code' => ['x'], 'details' => 42],
            ]);
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_graphql', $e->errorcode);
            $this->assertSame('', $e->graphqlcode);
            $this->assertSame('error_graphql_unknown', $e->a);
        }
    }

    private function stubErrorsBody(string $body): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => $body,
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    /**
     * @dataProvider malformed_errors
     */
    public function test_malformed_errors_throw_graphql_exception(string $body, string $errorcode): void {
        $this->stubErrorsBody($body);

        try {
            mod_skilland_graphql('{ test }');
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
        }
    }

    public static function malformed_errors(): array {
        return [
            'empty list' => ['{"errors":[]}', 'error_graphql_unknown'],
            'string entry' => ['{"errors":["Internal error"]}', 'error_graphql'],
            'null entry' => ['{"errors":[null]}', 'error_graphql_unknown'],
            'string errors' => ['{"errors":"boom"}', 'error_graphql_unknown'],
            'object errors' => ['{"errors":{}}', 'error_graphql_unknown'],
            'keyed object errors' => ['{"errors":{"a":{"message":"x"}}}', 'error_graphql_unknown'],
        ];
    }

    public function test_string_error_entry_is_logged(): void {
        $this->stubErrorsBody('{"errors":["Internal error"]}');

        try {
            mod_skilland_graphql('{ test }');
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('Internal error', $e->a);
            $this->assertStringContainsString('Internal error', $this->debug_log());
        }
    }

    public function test_null_errors_with_data_returns_data(): void {
        $this->stubErrorsBody('{"errors":null,"data":{"ok":true}}');

        $this->assertSame(['ok' => true], mod_skilland_graphql('{ test }'));
    }

    public function test_graphql_error_with_string_extensions_does_not_type_error(): void {
        $this->stubErrorsBody('{"errors":[{"message":"Nope","extensions":"x"}]}');

        try {
            mod_skilland_graphql('{ test }');
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_graphql', $e->errorcode);
            $this->assertSame('Nope', $e->a);
        }
    }

    /**
     * @dataProvider first_error_cases
     */
    public function test_first_graphql_error_normalises(mixed $errors, ?array $expected): void {
        $this->assertSame($expected, mod_skilland_first_graphql_error($errors));
    }

    public static function first_error_cases(): array {
        return [
            'absent' => [null, null],
            'array entry' => [[['message' => 'm']], ['message' => 'm']],
            'string entry' => [['boom'], ['message' => 'boom']],
            'int entry' => [[42], ['message' => '42']],
        ];
    }

    // ---------------------------------------------------------------
    // Non-2xx bodies and retries (SKL-672)
    // ---------------------------------------------------------------

    /**
     * Queue one curl response per request and start recording requests and sleeps.
     *
     * @param array ...$responses
     */
    private function queue(array ...$responses): void {
        $this->setValidConfig();
        unset($GLOBALS['_test_curl_response']);
        $GLOBALS['_test_curl_responses'] = $responses;
        $GLOBALS['_test_curl_requests'] = [];
        $this->sleeper->sleeps = [];
    }

    private static function resp(int $code, string $body = '', int $errno = 0, array $headers = []): array {
        return ['body' => $body, 'http_code' => $code, 'errno' => $errno, 'error' => '', 'headers' => $headers];
    }

    private static function ok(): array {
        return self::resp(200, json_encode(['data' => ['courses' => [['id' => '1']]]]));
    }

    private static function errors_body(string $code): string {
        return json_encode(['errors' => [['message' => 'Denied', 'extensions' => ['code' => $code]]]]);
    }

    private function graphql_failure(string $query = 'query MoodleListCourses { courses { id } }'): \moodle_exception {
        try {
            mod_skilland_graphql($query);
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('Expected moodle_exception');
    }

    private function requests(): int {
        return count($GLOBALS['_test_curl_requests'] ?? []);
    }

    public function test_401_with_graphql_errors_carries_the_graphql_code(): void {
        $this->queue(self::resp(401, self::errors_body('SKILLAND_API_KEY_INACTIVE')));

        $e = $this->graphql_failure();

        $this->assertInstanceOf(\mod_skilland\graphql_exception::class, $e);
        $this->assertSame('SKILLAND_API_KEY_INACTIVE', $e->graphqlcode);
        $this->assertSame('error_graphql', $e->errorcode);
        $this->assertSame(1, $this->requests());
    }

    public function test_403_invalid_api_key_maps_to_invalid_credentials(): void {
        $this->queue(self::resp(403, self::errors_body('SKILLAND_INVALID_API_KEY')));

        $e = $this->graphql_failure();

        $this->assertInstanceOf(\mod_skilland\graphql_exception::class, $e);
        $this->assertSame('error_config_invalid_credentials', $e->errorcode);
        $this->assertSame('SKILLAND_INVALID_API_KEY', $e->graphqlcode);
    }

    /**
     * @dataProvider plain_http_failures
     */
    public function test_non_2xx_without_usable_errors_is_error_graphql_http(int $code, string $body): void {
        $this->queue(self::resp($code, $body));

        $e = $this->graphql_failure();

        $this->assertSame('error_graphql_http', $e->errorcode);
        $this->assertSame('HTTP ' . $code, $e->a);
        $this->assertSame(1, $this->requests());
    }

    public static function plain_http_failures(): array {
        return [
            '400 with empty errors' => [400, json_encode(['errors' => []])],
            '400 with malformed errors' => [400, json_encode(['errors' => 'nope'])],
            '401 html' => [401, '<html><body>Unauthorized</body></html>'],
            '404 empty' => [404, ''],
        ];
    }

    public function test_body_snippet_goes_to_the_debug_log_only(): void {
        $this->queue(self::resp(401, '<html>BODYSECRET ' . str_repeat('x', 500) . '</html>'));
        $GLOBALS['_test_plugin_config']['mod_skilland']->devmode = 1;
        \mod_skilland\logger::reset_cache();

        $e = $this->graphql_failure();

        $this->assertSame('HTTP 401', $e->a);
        foreach ([(string) $e->a, $e->getMessage(), (string) $e->debuginfo] as $text) {
            $this->assertStringNotContainsString('BODYSECRET', $text);
            $this->assertStringNotContainsString('localhost', $text);
        }
        $log = $this->debug_log();
        $this->assertStringContainsString('BODYSECRET', $log);
        $this->assertStringNotContainsString(str_repeat('x', 200), $log);
    }

    public function test_body_snippet_is_not_logged_without_devmode(): void {
        $this->queue(self::resp(401, 'BODYSECRET'));

        $this->graphql_failure();

        $this->assertStringNotContainsString('BODYSECRET', $this->debug_log());
    }

    public function test_503_then_200_retries_once(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        $data = mod_skilland_graphql('query MoodleListCourses { courses { id } }');

        $this->assertSame('1', $data['courses'][0]['id']);
        $this->assertSame(2, $this->requests());
        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(250, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual(500, $this->sleeper->sleeps[0]);
    }

    public function test_retry_is_logged_as_a_warning_without_the_endpoint(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        mod_skilland_graphql('query MoodleListCourses { courses { id } }');

        $warnings = array_values(array_filter($GLOBALS['_test_debug_messages'],
            fn($m) => strpos($m['message'], 'Retrying') !== false));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('WARNING: Retrying MoodleListCourses after HTTP 503 (attempt 2/3)',
            $warnings[0]['message']);
        $this->assertStringNotContainsString('localhost', $warnings[0]['message']);
    }

    public function test_gateway_errors_give_up_after_three_attempts(): void {
        $this->queue(self::resp(502), self::resp(504), self::resp(504));

        $e = $this->graphql_failure();

        $this->assertSame('error_graphql_http', $e->errorcode);
        $this->assertSame('HTTP 504', $e->a);
        $this->assertSame(3, $this->requests());
        $this->assertCount(2, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(500, $this->sleeper->sleeps[1]);
        $this->assertLessThanOrEqual(750, $this->sleeper->sleeps[1]);
    }

    public function test_connection_refused_then_200_succeeds(): void {
        $this->queue(self::resp(0, '', 7), self::ok());

        $data = mod_skilland_graphql('query MoodleListCourses { courses { id } }');

        $this->assertSame('1', $data['courses'][0]['id']);
        $this->assertSame(2, $this->requests());
    }

    public function test_tls_certificate_error_is_not_retried(): void {
        $this->queue(self::resp(0, '', 60), self::ok());

        $this->assertSame('HTTP 0', $this->graphql_failure()->a);
        $this->assertSame(1, $this->requests());
    }

    /**
     * @dataProvider retry_after_cases
     */
    public function test_retry_after_on_429_and_503(int $code, array $headers, int $min, int $max): void {
        $this->queue(self::resp($code, '', 0, $headers), self::ok());

        mod_skilland_graphql('query MoodleListCourses { courses { id } }');

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

    public function test_500_with_graphql_errors_is_mapped_without_retry(): void {
        $this->queue(self::resp(500, self::errors_body('SKILLAND_ORG_NOT_FOUND')), self::ok());

        $e = $this->graphql_failure();

        $this->assertInstanceOf(\mod_skilland\graphql_exception::class, $e);
        $this->assertSame('SKILLAND_ORG_NOT_FOUND', $e->graphqlcode);
        $this->assertSame(1, $this->requests());
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_500_without_graphql_errors_is_retried(): void {
        $this->queue(self::resp(500, 'Internal Server Error'), self::ok());

        mod_skilland_graphql('query MoodleListCourses { courses { id } }');

        $this->assertSame(2, $this->requests());
    }

    /**
     * @dataProvider non_retried_client_errors
     */
    public function test_client_errors_are_not_retried(int $code): void {
        $this->queue(self::resp($code, 'nope'), self::ok());

        $this->assertSame('HTTP ' . $code, $this->graphql_failure()->a);
        $this->assertSame(1, $this->requests());
    }

    public static function non_retried_client_errors(): array {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404]];
    }

    public function test_2xx_with_graphql_errors_is_not_retried(): void {
        $this->queue(self::resp(200, self::errors_body('SKILLAND_ORG_NOT_FOUND')), self::ok());

        $this->graphql_failure();

        $this->assertSame(1, $this->requests());
    }

    public function test_create_course_mutation_is_not_retried(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        try {
            mod_skilland_create_course('Course', 'teacher@example.com');
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_graphql_http', $e->errorcode);
        }
        $this->assertSame(1, $this->requests());
        $this->assertSame([], $this->sleeper->sleeps);
    }

    public function test_commented_mutation_is_not_retried(): void {
        $this->queue(self::resp(503, 'busy'), self::ok());

        $e = $this->graphql_failure("  \n  # creates a course\nmutation MoodleCreateCourse { createSkillFromMoodle { id } }");

        $this->assertSame('HTTP 503', $e->a);
        $this->assertSame(1, $this->requests());
    }

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
     * @dataProvider mutation_cases
     */
    public function test_graphql_is_mutation(string $query, bool $expected): void {
        $this->assertSame($expected, mod_skilland_graphql_is_mutation($query));
    }

    public static function mutation_cases(): array {
        return [
            'named mutation' => ['mutation Foo { a }', true],
            'leading spaces, no name' => ['  mutation{ a }', true],
            'comment line first' => ["# c\nmutation X { a }", true],
            'named query' => ['query X { a }', false],
            'shorthand query' => ['{ a }', false],
            'query named like a mutation' => ['query mutationLike { a }', false],
            'mutation prefix of a longer word' => ['mutations { a }', false],
            'only a comment' => ['# mutation', false],
        ];
    }

    /**
     * @dataProvider transient_cases
     */
    public function test_is_transient(int $code, int $errno, ?array $body, bool $expected): void {
        $this->assertSame($expected, mod_skilland_is_transient($code, $errno, $body));
    }

    public static function transient_cases(): array {
        return [
            'dns' => [0, 6, null, true],
            'refused' => [0, 7, null, true],
            'timeout' => [0, 28, null, true],
            'tls handshake' => [0, 35, null, true],
            'empty reply' => [0, 52, null, true],
            'recv error' => [0, 56, null, true],
            'certificate' => [0, 60, null, false],
            'no errno' => [0, 0, null, false],
            '429' => [429, 0, null, true],
            '502' => [502, 0, null, true],
            '503' => [503, 0, null, true],
            '504' => [504, 0, null, true],
            '500 plain' => [500, 0, null, true],
            '500 empty errors' => [500, 0, ['errors' => []], true],
            '500 graphql errors' => [500, 0, ['errors' => [['message' => 'x']]], false],
            '501' => [501, 0, null, false],
            '400' => [400, 0, null, false],
            '401' => [401, 0, null, false],
        ];
    }
}
