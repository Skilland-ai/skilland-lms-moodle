<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_fetch_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_requests'],
            $GLOBALS['_test_curl_last'], $GLOBALS['CFG']->mod_skilland_allow_http);
        \core\di::reset_container();
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_curl_responses'], $GLOBALS['_test_curl_requests'],
            $GLOBALS['_test_curl_last']);
        \core\di::reset_container();
        \mod_skilland\logger::reset_cache();
        parent::tearDown();
    }

    private function setValidConfig(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];
    }

    private function stubGraphqlResponse(array $data): void {
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => $data]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_courses()
    // ---------------------------------------------------------------

    public function test_fetch_courses_throws_without_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => ''];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_fetch_courses();
    }

    public function test_fetch_courses_returns_courses(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'organization' => [
                'id' => 'org1',
                'name' => 'Test Org',
                'courses' => [
                    ['id' => 'c1', 'name' => 'Course 1', 'code' => 'C1', 'status' => 'active'],
                    ['id' => 'c2', 'name' => 'Course 2', 'code' => 'C2', 'status' => 'draft'],
                ],
            ],
        ]);

        $courses = mod_skilland_fetch_courses();

        $this->assertCount(2, $courses);
        $this->assertEquals('Course 1', $courses[0]['name']);
        $this->assertEquals('draft', $courses[1]['status']);
    }

    public function test_fetch_courses_returns_empty_when_no_courses_key(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'organization' => ['id' => 'org1', 'name' => 'Test Org'],
        ]);

        $courses = mod_skilland_fetch_courses();

        $this->assertEmpty($courses);
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_user_courses()
    // ---------------------------------------------------------------

    public function test_fetch_user_courses_throws_without_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => ''];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_fetch_user_courses('user@test.com');
    }

    public function test_fetch_user_courses_returns_empty_for_empty_email(): void {
        $this->setValidConfig();

        $courses = mod_skilland_fetch_user_courses('');

        $this->assertEmpty($courses);
    }

    public function test_fetch_user_courses_returns_courses(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'moodleUserCourses' => [
                ['id' => 'c1', 'name' => 'My Course', 'code' => 'MC', 'status' => 'active'],
            ],
        ]);

        $courses = mod_skilland_fetch_user_courses('user@test.com');

        $this->assertCount(1, $courses);
        $this->assertEquals('My Course', $courses[0]['name']);
    }

    public function test_fetch_user_courses_returns_empty_on_api_error(): void {
        $this->setValidConfig();
        // Simulate HTTP error.
        $GLOBALS['_test_curl_response'] = [
            'body' => 'Internal Server Error',
            'http_code' => 500,
            'errno' => 0,
            'error' => '',
        ];

        // Should not throw — catches exception and returns empty.
        $courses = mod_skilland_fetch_user_courses('user@test.com');

        $this->assertEmpty($courses);
    }

    public function test_fetch_user_courses_returns_empty_when_no_key(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['somethingElse' => []]);

        $courses = mod_skilland_fetch_user_courses('user@test.com');

        $this->assertEmpty($courses);
    }

    // ---------------------------------------------------------------
    // mod_skilland_create_course()
    // ---------------------------------------------------------------

    public function test_create_course_throws_without_name(): void {
        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('', 'user@test.com');
    }

    public function test_create_course_throws_without_email(): void {
        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('Test Course', '');
    }

    public function test_create_course_returns_created_course(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'createSkillFromMoodle' => [
                'id' => 'new1',
                'name' => 'New Course',
                'status' => 'draft',
                'creationStep' => 'created',
            ],
        ]);

        $result = mod_skilland_create_course('New Course', 'user@test.com');

        $this->assertEquals('new1', $result['id']);
        $this->assertEquals('New Course', $result['name']);
    }

    public function test_create_course_throws_when_response_missing_data(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['other' => 'data']);

        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('Test', 'user@test.com');
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_topics()
    // ---------------------------------------------------------------

    public function test_fetch_topics_throws_without_courseid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_courseid');

        mod_skilland_fetch_topics('');
    }

    public function test_fetch_topics_returns_course_with_topics(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'course' => [
                'id' => 'c1',
                'name' => 'Test Course',
                'topics' => [
                    ['id' => 't1', 'name' => 'Topic 1', 'code' => 'T1', 'description' => 'Desc'],
                ],
            ],
        ]);

        $result = mod_skilland_fetch_topics('c1');

        $this->assertEquals('Test Course', $result['name']);
        $this->assertCount(1, $result['topics']);
    }

    public function test_fetch_topics_returns_empty_structure_when_no_course(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['course' => null]);

        $result = mod_skilland_fetch_topics('c1');

        $this->assertEquals('c1', $result['id']);
        $this->assertEmpty($result['topics']);
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_lessons()
    // ---------------------------------------------------------------

    public function test_fetch_lessons_throws_without_topicid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_topicid');

        mod_skilland_fetch_lessons('');
    }

    public function test_fetch_lessons_returns_lessons(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topic' => [
                'id' => 't1',
                'name' => 'Topic 1',
                'lessons' => [
                    ['id' => 'l1', 'name' => 'Lesson 1', 'updatedAt' => '2024-01-01'],
                    ['id' => 'l2', 'name' => 'Lesson 2', 'updatedAt' => '2024-01-02'],
                ],
            ],
        ]);

        $lessons = mod_skilland_fetch_lessons('t1');

        $this->assertCount(2, $lessons);
        $this->assertEquals('Lesson 1', $lessons[0]['name']);
    }

    public function test_fetch_lessons_returns_empty_when_no_lessons_key(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['topic' => ['id' => 't1', 'name' => 'Topic 1']]);

        $lessons = mod_skilland_fetch_lessons('t1');

        $this->assertEmpty($lessons);
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_topic_scorm() — REST route (SKL-791)
    // ---------------------------------------------------------------

    private const REST_SCORM_URL = 'https://localhost:8000/api/moodle/topics/t1/scorm';
    private const REST_HASH_URL = 'https://localhost:8000/api/moodle/topics/t1/scorm-hash';
    private const GRAPHQL_URL = 'https://localhost:8000/graphql';

    /** A REST answer with the given status and JSON body. */
    private function rest(int $code, $body = null): array {
        return ['body' => $body === null ? '' : json_encode($body), 'http_code' => $code, 'errno' => 0, 'error' => ''];
    }

    /** A GraphQL 200 answer carrying $data. */
    private function graphql(array $data): array {
        return ['body' => json_encode(['data' => $data]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    /** A connection refused: no HTTP response at all. */
    private function refused(): array {
        return ['body' => '', 'http_code' => 0, 'errno' => 7, 'error' => 'Connection refused'];
    }

    /** Queue one curl answer per request, in order. */
    private function queue(array ...$responses): void {
        $GLOBALS['_test_curl_responses'] = $responses;
    }

    /** Config with an API key and a frontend URL but no legacy GraphQL endpoint. */
    private function setRestOnlyConfig(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => '',
            'frontend_url' => 'https://app.skilland.test/',
        ];
    }

    private function scormBody(): array {
        return [
            'packageUrl' => 'https://bucket.s3.eu-west-1.amazonaws.com/topic.zip?X-Amz-Signature=abc',
            'packageSize' => 1024000,
            'packageHash' => 'sha256:abc123',
            'generatedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-01-02T00:00:00Z',
            'mappings' => [
                ['lessonId' => 'l1', 'scoId' => 'sco1'],
                ['lessonId' => 'l2', 'scoId' => 'sco2'],
            ],
        ];
    }

    private function expectCode(callable $fn, string $code): \moodle_exception {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
            return $e;
        }
        $this->fail('Expected moodle_exception ' . $code);
    }

    /** Every logged message, joined. */
    private function logText(): string {
        return implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
    }

    public function test_fetch_topic_scorm_throws_without_topicid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_topicid');

        mod_skilland_fetch_topic_scorm('');
    }

    public function test_fetch_topic_scorm_reads_the_rest_route_with_a_bearer_key(): void {
        $this->setValidConfig();
        $this->queue($this->rest(200, $this->scormBody()));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertSame([
            'packageUrl' => 'https://bucket.s3.eu-west-1.amazonaws.com/topic.zip?X-Amz-Signature=abc',
            'packageSize' => 1024000,
            'packageHash' => 'sha256:abc123',
            'generatedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-01-02T00:00:00Z',
            'mappings' => ['l1' => 'sco1', 'l2' => 'sco2'],
        ], $result);
        $this->assertSame([self::REST_SCORM_URL], $GLOBALS['_test_curl_requests']);
        $headers = $GLOBALS['_test_curl_last']['headers'];
        $this->assertContains('Authorization: Bearer key1', $headers);
        $this->assertContains('Accept: application/json', $headers);
        $this->assertFalse($GLOBALS['_test_curl_last']['options']['CURLOPT_FOLLOWLOCATION']);
    }

    public function test_fetch_topic_scorm_uses_the_frontend_url_and_encodes_the_topic_id(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(200, $this->scormBody()));

        mod_skilland_fetch_topic_scorm('a b/c');

        $this->assertSame(['https://app.skilland.test/api/moodle/topics/a%20b%2Fc/scorm'],
            $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_404_falls_back_to_graphql(): void {
        $this->setValidConfig();
        $this->queue($this->rest(404, ['error' => 'not found']), $this->graphql(['topicScorm' => $this->scormBody()]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertSame(['l1' => 'sco1', 'l2' => 'sco2'], $result['mappings']);
        $this->assertSame([self::REST_SCORM_URL, self::GRAPHQL_URL], $GLOBALS['_test_curl_requests']);
        $this->assertStringContainsString('falling back to the legacy GraphQL endpoint', $this->logText());
    }

    /**
     * @dataProvider fallback_statuses
     */
    public function test_fetch_topic_scorm_fallback_statuses(int $status): void {
        $this->setValidConfig();
        // A refused connection is retried before the fallback.
        $restfailures = $status === 0 ? [$this->refused(), $this->refused(), $this->refused()] : [$this->rest($status)];
        $this->queue(...array_merge($restfailures, [$this->graphql(['topicScorm' => $this->scormBody()])]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertSame('sha256:abc123', $result['packageHash']);
        $this->assertSame(self::GRAPHQL_URL, end($GLOBALS['_test_curl_requests']));
        $this->assertCount(count($restfailures) + 1, $GLOBALS['_test_curl_requests']);
    }

    public static function fallback_statuses(): array {
        return [
            '401' => [401],
            '403' => [403],
            'connection refused' => [0],
        ];
    }

    public function test_fetch_topic_scorm_rest_401_without_graphql_endpoint_fails_without_fallback(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(401, ['error' => 'Unauthorized']));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_invalid_credentials');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_404_without_graphql_endpoint_is_missing_topic(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(404));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_missing_topicid');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_409_is_not_available_and_never_falls_back(): void {
        $this->setValidConfig();
        $this->queue($this->rest(409, ['error' => 'Topic has no lessons']),
            $this->graphql(['topicScorm' => $this->scormBody()]));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_not_available');

        $this->assertSame([self::REST_SCORM_URL], $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_500_fails_without_fallback(): void {
        $this->setValidConfig();
        $this->queue($this->rest(500), $this->rest(500), $this->rest(500),
            $this->graphql(['topicScorm' => $this->scormBody()]));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_fetch_failed');

        $this->assertSame([self::REST_SCORM_URL, self::REST_SCORM_URL, self::REST_SCORM_URL],
            $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_invalid_json_fails_without_fallback(): void {
        $this->setValidConfig();
        $this->queue(['body' => '<html>', 'http_code' => 200, 'errno' => 0, 'error' => '']);

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_fetch_failed');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_empty_rest_body_is_not_available(): void {
        $this->setValidConfig();
        $this->queue(['body' => '{}', 'http_code' => 200, 'errno' => 0, 'error' => '']);

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_not_available');
    }

    public function test_fetch_topic_scorm_reports_the_rest_error_when_the_graphql_fallback_is_unreachable(): void {
        $this->setValidConfig();
        $this->queue($this->rest(404), $this->refused(), $this->refused(), $this->refused());

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_missing_topicid');

        $this->assertSame(self::GRAPHQL_URL, end($GLOBALS['_test_curl_requests']));
        $this->assertStringContainsString('reporting the REST failure', $this->logText());
    }

    public function test_fetch_topic_scorm_without_apikey_fails_before_any_request(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => '',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_missing_apikey');

        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_fetch_topic_scorm_rest_redirect_is_refused(): void {
        $this->setValidConfig();
        $this->queue($this->rest(302));

        $e = $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_http_redirect');

        $this->assertSame(302, $e->a);
        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_rest_never_logs_the_api_key(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'sk_live_supersecret',
            'graphql_endpoint' => '',
            'frontend_url' => 'https://app.skilland.test',
            'devmode' => 1,
        ];
        \mod_skilland\logger::reset_cache();
        $this->queue($this->rest(401, ['error' => 'Unauthorized']));

        try {
            mod_skilland_fetch_topic_scorm('t1');
        } catch (\moodle_exception $e) {
            // Expected.
        }
        $this->queue($this->rest(200, $this->scormBody()));
        mod_skilland_check_topic_snapshot('t1');

        $log = $this->logText();
        $this->assertStringContainsString('[REST]', $log);
        $this->assertStringNotContainsString('sk_live_supersecret', $log);
        $this->assertStringNotContainsString('X-Amz-Signature', $log);
    }

    public function test_rest_get_rejects_a_plain_http_frontend_url(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'apikey' => 'key1',
            'graphql_endpoint' => '',
            'frontend_url' => 'http://app.skilland.test',
        ];

        $this->expectCode(fn() => mod_skilland_rest_get('/api/moodle/topics/t1/scorm'), 'error_insecure_url');
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_rest_failure_carries_the_http_status(): void {
        $this->setValidConfig();
        $this->queue($this->rest(404));

        try {
            mod_skilland_rest_get('/api/moodle/topics/t1/scorm');
            $this->fail('Expected rest_exception');
        } catch (\mod_skilland\rest_exception $e) {
            $this->assertSame(404, $e->httpcode);
            $this->assertSame('error_graphql_http', $e->errorcode);
        }
    }

    public function test_rest_transport_failure_has_status_zero(): void {
        $this->setValidConfig();
        $this->queue($this->refused(), $this->refused(), $this->refused());

        try {
            mod_skilland_rest_get('/api/moodle/topics/t1/scorm');
            $this->fail('Expected rest_exception');
        } catch (\mod_skilland\rest_exception $e) {
            $this->assertSame(0, $e->httpcode);
        }
        $this->assertCount(3, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_skips_mappings_with_null_ids(): void {
        $this->setValidConfig();
        $this->queue($this->rest(200, [
            'packageUrl' => 'url',
            'mappings' => [
                ['lessonId' => 'l1', 'scoId' => 'sco1'],
                ['lessonId' => null, 'scoId' => 'sco2'],
                ['lessonId' => 'l3', 'scoId' => null],
            ],
        ]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        // Only l1->sco1 should be included (others have null ids).
        $this->assertSame(['l1' => 'sco1'], $result['mappings']);
    }

    public function test_fetch_topic_scorm_skips_non_array_mappings(): void {
        $this->setValidConfig();
        $this->queue($this->rest(200, [
            'packageUrl' => 'url',
            'mappings' => [
                ['lessonId' => 'l1', 'scoId' => 'sco1'],
                'invalid-string',
                42,
            ],
        ]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertCount(1, $result['mappings']);
    }

    public function test_fetch_topic_scorm_handles_empty_mappings(): void {
        $this->setValidConfig();
        $this->queue($this->rest(200, ['packageUrl' => 'https://cdn.example.com/package.zip', 'packageSize' => 512,
            'packageHash' => '', 'generatedAt' => '', 'expiresAt' => '', 'mappings' => []]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertEmpty($result['mappings']);
        $this->assertSame('', $result['packageHash']);
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_topic_scorm() — legacy GraphQL fallback (after a REST 404)
    // ---------------------------------------------------------------

    /** REST 404, then the given GraphQL answer. */
    private function stubGraphqlFallback(array $graphqlresponse): void {
        $this->queue($this->rest(404), $graphqlresponse);
    }

    public function test_fetch_topic_scorm_graphql_fallback_throws_when_not_available(): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback($this->graphql(['topicScorm' => null]));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_not_available');
    }

    /**
     * Stub a GraphQL error response with the given extensions.code.
     *
     * @param string $code
     * @param string $message
     */
    private function graphqlError(string $code, string $message = 'nope'): array {
        return [
            'body' => json_encode(['errors' => [['message' => $message, 'extensions' => ['code' => $code]]]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    /**
     * @dataProvider topic_scorm_error_codes
     */
    public function test_fetch_topic_scorm_maps_graphql_codes(string $code, string $errorcode): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback($this->graphqlError($code));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), $errorcode);
    }

    public static function topic_scorm_error_codes(): array {
        return [
            'scorm not available' => ['SCORM_NOT_AVAILABLE', 'error_scorm_not_available'],
            'topic not found' => ['TOPIC_NOT_FOUND', 'error_config_missing_topicid'],
            'invalid credentials' => ['SKILLAND_INVALID_API_KEY', 'error_config_invalid_credentials'],
            'missing org id' => ['SKILLAND_MISSING_ORG_ID', 'error_config_missing_orgid'],
            'unknown code' => ['SOMETHING_ELSE', 'error_scorm_fetch_failed'],
        ];
    }

    public function test_fetch_topic_scorm_rethrows_config_errors_unchanged(): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback($this->graphqlError('SKILLAND_INVALID_API_KEY'));

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_config_invalid_credentials', $e->errorcode);
            $this->assertSame('SKILLAND_INVALID_API_KEY', $e->graphqlcode);
        }
    }

    public function test_fetch_topic_scorm_unknown_code_fails_without_a(): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback($this->graphqlError('SOMETHING_ELSE', 'secret detail'));

        $e = $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_fetch_failed');

        $this->assertNull($e->a);
    }

    public function test_fetch_topic_scorm_malformed_errors_become_fetch_failed(): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback(['body' => '{"errors":[null]}', 'http_code' => 200, 'errno' => 0, 'error' => '']);

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_fetch_failed');
    }

    public function test_fetch_topic_scorm_graphql_fallback_returns_package_info(): void {
        $this->setValidConfig();
        $this->stubGraphqlFallback($this->graphql(['topicScorm' => [
            'packageUrl' => 'https://cdn.example.com/package.zip',
            'packageSize' => 1024000,
            'packageHash' => 'sha256:abc123',
            'generatedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-01-02T00:00:00Z',
            'mappings' => [
                ['lessonId' => 'l1', 'scoId' => 'sco1'],
                ['lessonId' => 'l2', 'scoId' => 'sco2'],
            ],
        ]]));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertEquals('https://cdn.example.com/package.zip', $result['packageUrl']);
        $this->assertEquals(1024000, $result['packageSize']);
        $this->assertEquals('sha256:abc123', $result['packageHash']);
        $this->assertSame(['l1' => 'sco1', 'l2' => 'sco2'], $result['mappings']);
    }

    // ---------------------------------------------------------------
    // mod_skilland_check_topic_snapshot()
    // ---------------------------------------------------------------

    private function hashBody(): array {
        return [
            'contentHash' => 'hash123',
            'packageHash' => 'phash456',
            'generatedAt' => '2024-06-01T00:00:00Z',
            'hasPackage' => true,
            'isStale' => false,
        ];
    }

    public function test_check_topic_snapshot_reads_the_rest_route(): void {
        $this->setValidConfig();
        $this->queue($this->rest(200, $this->hashBody()));

        // Use the real transport (the curl stub), not a fake api_client.
        \core\di::reset_container();

        $this->assertSame($this->hashBody(), mod_skilland_check_topic_snapshot('t1'));
        $this->assertSame([self::REST_HASH_URL], $GLOBALS['_test_curl_requests']);
        $this->assertContains('Authorization: Bearer key1', $GLOBALS['_test_curl_last']['headers']);
    }

    public function test_check_topic_snapshot_rest_404_falls_back_to_graphql(): void {
        $this->setValidConfig();
        $this->queue($this->rest(404), $this->graphql(['topicScormHash' => $this->hashBody()]));
        \core\di::reset_container();

        $this->assertSame($this->hashBody(), mod_skilland_check_topic_snapshot('t1'));
        $this->assertSame([self::REST_HASH_URL, self::GRAPHQL_URL], $GLOBALS['_test_curl_requests']);
        $this->assertStringContainsString('falling back to the legacy GraphQL endpoint', $this->logText());
    }

    public function test_check_topic_snapshot_rest_401_without_graphql_endpoint_returns_null(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(401), $this->graphql(['topicScormHash' => $this->hashBody()]));
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_check_topic_snapshot_rest_500_returns_null_without_fallback(): void {
        $this->setValidConfig();
        $this->queue($this->rest(500), $this->rest(500), $this->rest(500),
            $this->graphql(['topicScormHash' => $this->hashBody()]));
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
        $this->assertNotContains(self::GRAPHQL_URL, $GLOBALS['_test_curl_requests']);
    }

    public function test_check_topic_snapshot_returns_null_when_the_fallback_fails_too(): void {
        $this->setValidConfig();
        $this->queue($this->rest(403), ['body' => 'Error', 'http_code' => 400, 'errno' => 0, 'error' => '']);
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
    }

    public function test_check_topic_snapshot_returns_null_when_graphql_hash_missing(): void {
        $this->setValidConfig();
        $this->queue($this->rest(404), $this->graphql(['topicScormHash' => null]));
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
    }

    public function test_check_topic_snapshot_returns_null_for_an_empty_rest_body(): void {
        $this->setValidConfig();
        $this->queue(['body' => '{}', 'http_code' => 200, 'errno' => 0, 'error' => '']);
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
    }
}
