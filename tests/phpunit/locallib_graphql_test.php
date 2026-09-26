<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_graphql_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_curl_response']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response']);
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
}
