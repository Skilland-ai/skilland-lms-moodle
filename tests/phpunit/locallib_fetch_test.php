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

    /** Answer the next request(s) with $body as a 2xx REST answer. */
    private function stubRest(array $body, int $code = 200): void {
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode($body),
            'http_code' => $code,
            'errno' => 0,
            'error' => '',
        ];
    }

    /** Answer the next request with an error status and an optional JSON {error} body. */
    private function stubRestError(int $code, ?string $error = null): void {
        $GLOBALS['_test_curl_response'] = [
            'body' => $error === null ? '' : json_encode(['error' => $error]),
            'http_code' => $code,
            'errno' => 0,
            'error' => '',
        ];
    }

    private const SKILL_ROW = ['id' => 'c1', 'name' => 'Course 1', 'description' => 'D', 'status' => 'Published',
        'language' => 'es', 'category' => 'Sales'];

    // ---------------------------------------------------------------
    // mod_skilland_fetch_courses() — GET skills?status=all
    // ---------------------------------------------------------------

    public function test_fetch_courses_throws_without_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => ''];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_fetch_courses();
    }

    public function test_fetch_courses_reads_every_status_and_uses_the_id_as_code(): void {
        $this->setValidConfig();
        $this->stubRest(['skills' => [
            self::SKILL_ROW,
            ['id' => 'c2', 'name' => 'Course 2', 'description' => null, 'status' => 'CREATING', 'language' => 'en',
                'category' => null],
        ]]);

        $courses = mod_skilland_fetch_courses();

        $this->assertSame(['https://localhost:8000/api/moodle/skills?status=all'], $GLOBALS['_test_curl_requests']);
        $this->assertContains('Authorization: Bearer key1', $GLOBALS['_test_curl_last']['headers']);
        $this->assertSame([
            'id' => 'c1', 'name' => 'Course 1', 'code' => 'c1', 'status' => 'Published', 'description' => 'D',
            'language' => 'es', 'category' => 'Sales',
        ], $courses[0]);
        $this->assertSame('c2', $courses[1]['code']);
        $this->assertSame('CREATING', $courses[1]['status']);
        $this->assertSame('', $courses[1]['description']);
        $this->assertSame('', $courses[1]['category']);
    }

    public function test_fetch_courses_skips_rows_without_an_id(): void {
        $this->setValidConfig();
        $this->stubRest(['skills' => [['name' => 'No id'], 'not-a-row', ['id' => '', 'name' => 'Empty'], self::SKILL_ROW]]);

        $courses = mod_skilland_fetch_courses();

        $this->assertSame(['c1'], array_column($courses, 'id'));
    }

    public function test_fetch_courses_returns_empty_when_no_skills_key(): void {
        $this->setValidConfig();
        $this->stubRest(['somethingElse' => []]);

        $this->assertSame([], mod_skilland_fetch_courses());
    }

    public function test_fetch_courses_propagates_an_api_failure(): void {
        $this->setValidConfig();
        $this->stubRestError(401, 'Unauthorized');

        $this->expectException(\mod_skilland\rest_exception::class);

        mod_skilland_fetch_courses();
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_user_courses() — GET users/courses?email=
    // ---------------------------------------------------------------

    public function test_fetch_user_courses_throws_without_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['orgid' => ''];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_orgid');

        mod_skilland_fetch_user_courses('user@test.com');
    }

    public function test_fetch_user_courses_returns_empty_for_empty_email(): void {
        $this->setValidConfig();

        $courses = mod_skilland_fetch_user_courses('  ');

        $this->assertEmpty($courses);
        $this->assertArrayNotHasKey('_test_curl_requests', $GLOBALS);
    }

    public function test_fetch_user_courses_sends_the_lower_cased_email(): void {
        $this->setValidConfig();
        $this->stubRest(['skills' => [self::SKILL_ROW]]);

        $courses = mod_skilland_fetch_user_courses(' Teacher+One@Test.COM ');

        $this->assertSame(['https://localhost:8000/api/moodle/users/courses?email=teacher%2Bone%40test.com'],
            $GLOBALS['_test_curl_requests']);
        $this->assertSame(['c1'], array_column($courses, 'id'));
        $this->assertSame('c1', $courses[0]['code']);
    }

    public function test_fetch_user_courses_never_logs_the_email(): void {
        $this->setValidConfig();
        $GLOBALS['_test_plugin_config']['mod_skilland']->devmode = 1;
        \mod_skilland\logger::reset_cache();
        $this->stubRestError(404);

        mod_skilland_fetch_user_courses('teacher@test.com');

        $log = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('users/courses?[redacted]', $log);
        $this->assertStringNotContainsString('teacher', $log);
    }

    public function test_fetch_user_courses_returns_empty_on_api_error(): void {
        $this->setValidConfig();
        $this->stubRestError(400, 'invalid_email');

        $this->assertEmpty(mod_skilland_fetch_user_courses('user@test.com'));
    }

    public function test_fetch_user_courses_returns_empty_when_no_key(): void {
        $this->setValidConfig();
        $this->stubRest(['somethingElse' => []]);

        $this->assertEmpty(mod_skilland_fetch_user_courses('user@test.com'));
    }

    // ---------------------------------------------------------------
    // mod_skilland_create_course() — POST skills
    // ---------------------------------------------------------------

    public function test_create_course_throws_without_name(): void {
        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('', 'user@test.com');
    }

    public function test_create_course_throws_without_email(): void {
        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('Test Course', '');
    }

    public function test_create_course_posts_the_course_and_returns_its_studio_path(): void {
        $this->setValidConfig();
        $this->stubRest(['id' => 'new1', 'path' => '/skills/new?draft=new1', 'name' => 'New Course'], 201);

        $result = mod_skilland_create_course('New Course', 'User@Test.com', '42');

        $this->assertSame(['id' => 'new1', 'name' => 'New Course', 'path' => '/skills/new?draft=new1'], $result);
        $this->assertSame(['https://localhost:8000/api/moodle/skills'], $GLOBALS['_test_curl_requests']);
        $this->assertSame([
            'name' => 'New Course',
            'userEmail' => 'user@test.com',
            'moodleUserId' => '42',
            'issuer' => $GLOBALS['CFG']->wwwroot,
        ], json_decode($GLOBALS['_test_curl_last']['body'], true));
        $headers = $GLOBALS['_test_curl_last']['headers'];
        $this->assertContains('Authorization: Bearer key1', $headers);
        $this->assertContains('Content-Type: application/json', $headers);
    }

    public function test_create_course_without_a_moodle_user_sends_no_identity_link(): void {
        $this->setValidConfig();
        $this->stubRest(['id' => 'new1', 'path' => '/skills/new?draft=new1', 'name' => 'N'], 201);

        mod_skilland_create_course('N', 'user@test.com');

        $sent = json_decode($GLOBALS['_test_curl_last']['body'], true);
        $this->assertArrayNotHasKey('moodleUserId', $sent);
        $this->assertArrayNotHasKey('issuer', $sent);
    }

    /**
     * @dataProvider untrusted_paths
     */
    public function test_create_course_rebuilds_a_path_outside_the_allow_list(mixed $path): void {
        $this->setValidConfig();
        $this->stubRest(['id' => 'new 1', 'path' => $path, 'name' => 'N'], 201);

        $this->assertSame('/skills/new?draft=new%201', mod_skilland_create_course('N', 'user@test.com')['path']);
    }

    public static function untrusted_paths(): array {
        return [
            'absolute url' => ['https://evil.example/skills/new?draft=x'],
            'protocol-relative' => ['//evil.example/skills/x'],
            'old studio path' => ['/skills-studio/create/x/new1'],
            'missing' => [null],
            'not a string' => [42],
        ];
    }

    public function test_create_course_name_falls_back_to_the_requested_name(): void {
        $this->setValidConfig();
        $this->stubRest(['id' => 'new1', 'path' => '/skills/new?draft=new1'], 201);

        $this->assertSame('Requested', mod_skilland_create_course('Requested', 'user@test.com')['name']);
    }

    public function test_create_course_throws_when_the_answer_has_no_id(): void {
        $this->setValidConfig();
        $this->stubRest(['other' => 'data'], 201);

        $this->expectException(\moodle_exception::class);

        mod_skilland_create_course('Test', 'user@test.com');
    }

    /**
     * @dataProvider create_failures
     */
    public function test_create_course_maps_api_errors(int $code, ?string $error, string $errorcode): void {
        $this->setValidConfig();
        $this->stubRestError($code, $error);

        try {
            mod_skilland_create_course('Test', 'user@test.com', '42');
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertCount(1, $GLOBALS['_test_curl_requests'], 'A POST is never retried');
    }

    public static function create_failures(): array {
        return [
            'insufficient role' => [403, 'insufficient_role', 'error_create_insufficient_role'],
            'inactive member' => [403, 'inactive_member', 'error_create_inactive_member'],
            'name taken' => [409, 'name_taken', 'error_create_name_taken'],
            'not a member' => [409, 'not_a_member', 'error_create_not_a_member'],
            'rate limited' => [429, null, 'error_create_rate_limited'],
            'unknown 403' => [403, 'something_else', 'error_config_invalid_credentials'],
            'bad key' => [401, 'Unauthorized', 'error_config_invalid_credentials'],
            'invalid body' => [400, 'invalid_body', 'error_graphql_http'],
            'server error' => [500, null, 'error_graphql_http'],
            'error code on the wrong status' => [400, 'name_taken', 'error_graphql_http'],
        ];
    }

    public function test_create_course_errors_reach_the_client_as_actionable_messages(): void {
        $this->setValidConfig();
        $this->stubRestError(409, 'name_taken');

        try {
            mod_skilland_create_course('Test', 'user@test.com');
            $this->fail('Expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_create_name_taken', mod_skilland_client_error_message($e));
        }
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_topics() — GET skills/{id}/topics
    // ---------------------------------------------------------------

    public function test_fetch_topics_throws_without_courseid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_courseid');

        mod_skilland_fetch_topics('');
    }

    public function test_fetch_topics_adapts_the_rest_topics(): void {
        $this->setValidConfig();
        $this->stubRest(['topics' => [
            ['id' => 't1', 'name' => 'Topic 1', 'description' => 'Desc', 'sortOrder' => 0,
                'updatedAt' => '2026-01-01T00:00:00Z'],
            ['id' => 't2', 'name' => 'Topic 2', 'description' => null, 'sortOrder' => 1,
                'updatedAt' => '2026-01-01T00:00:00Z'],
            ['name' => 'no id'],
        ]]);

        $result = mod_skilland_fetch_topics('c 1');

        $this->assertSame(['https://localhost:8000/api/moodle/skills/c%201/topics'], $GLOBALS['_test_curl_requests']);
        $this->assertSame([
            'id' => 'c 1',
            'name' => '',
            'code' => 'c 1',
            'topics' => [
                ['id' => 't1', 'name' => 'Topic 1', 'code' => 't1', 'description' => 'Desc'],
                ['id' => 't2', 'name' => 'Topic 2', 'code' => 't2', 'description' => ''],
            ],
        ], $result);
    }

    public function test_fetch_topics_of_a_skill_outside_the_organization_is_empty(): void {
        $this->setValidConfig();
        $this->stubRestError(404, 'Skill not found');

        $result = mod_skilland_fetch_topics('c1');

        $this->assertSame('c1', $result['id']);
        $this->assertSame([], $result['topics']);
    }

    public function test_fetch_topics_propagates_other_failures(): void {
        $this->setValidConfig();
        $this->stubRestError(401);

        $this->expectException(\mod_skilland\rest_exception::class);

        mod_skilland_fetch_topics('c1');
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_lessons() — GET topics/{id}/contents
    // ---------------------------------------------------------------

    public function test_fetch_lessons_throws_without_topicid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_topicid');

        mod_skilland_fetch_lessons('');
    }

    public function test_fetch_lessons_keeps_the_lessons_with_a_body_in_order(): void {
        $this->setValidConfig();
        $this->stubRest(['contents' => [
            ['id' => 'l1', 'name' => 'Lesson 1', 'type' => 'lesson', 'sortOrder' => 0, 'content' => '<p>a</p>',
                'updatedAt' => '2024-01-01T00:00:00.000Z'],
            ['id' => 'a1', 'name' => 'Quiz', 'type' => 'assessment', 'sortOrder' => 1, 'content' => '<p>q</p>',
                'updatedAt' => '2024-01-01T00:00:00.000Z'],
            ['id' => 'l2', 'name' => 'Empty lesson', 'type' => 'lesson', 'sortOrder' => 2, 'content' => null,
                'updatedAt' => '2024-01-01T00:00:00.000Z'],
            ['id' => 'e1', 'name' => 'Exam', 'type' => 'exam', 'sortOrder' => 3, 'content' => null],
            ['id' => 'l3', 'name' => 'Lesson 3', 'type' => 'lesson', 'sortOrder' => 4, 'content' => '<p>c</p>',
                'updatedAt' => '2024-01-02T00:00:00.000Z'],
        ]]);

        $lessons = mod_skilland_fetch_lessons('t 1');

        $this->assertSame(['https://localhost:8000/api/moodle/topics/t%201/contents'], $GLOBALS['_test_curl_requests']);
        $this->assertSame([
            ['id' => 'l1', 'name' => 'Lesson 1', 'updatedAt' => '2024-01-01T00:00:00.000Z'],
            ['id' => 'l3', 'name' => 'Lesson 3', 'updatedAt' => '2024-01-02T00:00:00.000Z'],
        ], $lessons);
        $this->assertSame(strtotime('2024-01-02T00:00:00Z'), strtotime($lessons[1]['updatedAt']));
    }

    public function test_fetch_lessons_returns_empty_when_no_contents_key(): void {
        $this->setValidConfig();
        $this->stubRest(['topic' => ['id' => 't1']]);

        $this->assertEmpty(mod_skilland_fetch_lessons('t1'));
    }

    public function test_fetch_lessons_of_a_topic_outside_the_organization_is_empty(): void {
        $this->setValidConfig();
        $this->stubRestError(404, 'Topic not found');

        $this->assertSame([], mod_skilland_fetch_lessons('t1'));
    }

    public function test_fetch_lessons_propagates_other_failures(): void {
        $this->setValidConfig();
        $this->stubRestError(403);

        $this->expectException(\mod_skilland\rest_exception::class);

        mod_skilland_fetch_lessons('t1');
    }

    // ---------------------------------------------------------------
    // mod_skilland_fetch_topic_scorm() — REST route (SKL-791)
    // ---------------------------------------------------------------

    private const REST_SCORM_URL = 'https://localhost:8000/api/moodle/topics/t1/scorm';
    private const REST_HASH_URL = 'https://localhost:8000/api/moodle/topics/t1/scorm-hash';

    /** A REST answer with the given status and JSON body. */
    private function rest(int $code, $body = null): array {
        return ['body' => $body === null ? '' : json_encode($body), 'http_code' => $code, 'errno' => 0, 'error' => ''];
    }

    /** A connection refused: no HTTP response at all. */
    private function refused(): array {
        return ['body' => '', 'http_code' => 0, 'errno' => 7, 'error' => 'Connection refused'];
    }

    /** Queue one curl answer per request, in order. */
    private function queue(array ...$responses): void {
        $GLOBALS['_test_curl_responses'] = $responses;
    }

    /** Config with an API key and a frontend URL but no Skilland URL. */
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
        $this->queue($this->rest(200, $this->scormBody() + ['contentHash' => 'c1', 'signature' => 'c2ln',
            'keyId' => 'k1']));

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertSame([
            'packageUrl' => 'https://bucket.s3.eu-west-1.amazonaws.com/topic.zip?X-Amz-Signature=abc',
            'packageSize' => 1024000,
            'packageHash' => 'sha256:abc123',
            'generatedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-01-02T00:00:00Z',
            'mappings' => ['l1' => 'sco1', 'l2' => 'sco2'],
            'contentHash' => 'c1',
            'signature' => 'c2ln',
            'keyId' => 'k1',
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

    /**
     * @dataProvider former_fallback_statuses
     */
    public function test_fetch_topic_scorm_never_falls_back_to_the_legacy_endpoint(int $status, string $errorcode): void {
        $this->setValidConfig();
        // A refused connection is retried, then reported; nothing else is ever asked.
        $failures = $status === 0 ? [$this->refused(), $this->refused(), $this->refused()] : [$this->rest($status)];
        $this->queue(...array_merge($failures, [$this->rest(200, $this->scormBody())]));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), $errorcode);

        $this->assertSame(array_fill(0, count($failures), self::REST_SCORM_URL), $GLOBALS['_test_curl_requests']);
    }

    public static function former_fallback_statuses(): array {
        return [
            '401' => [401, 'error_config_invalid_credentials'],
            '403' => [403, 'error_config_invalid_credentials'],
            '404' => [404, 'error_config_missing_topicid'],
            'connection refused' => [0, 'error_scorm_fetch_failed'],
        ];
    }

    public function test_fetch_topic_scorm_rest_401_with_only_a_frontend_url_is_invalid_credentials(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(401, ['error' => 'Unauthorized']));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_invalid_credentials');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_404_with_only_a_frontend_url_is_missing_topic(): void {
        $this->setRestOnlyConfig();
        $this->queue($this->rest(404));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_config_missing_topicid');

        $this->assertCount(1, $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_409_is_not_available(): void {
        $this->setValidConfig();
        $this->queue($this->rest(409, ['error' => 'Topic has no lessons']));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_not_available');

        $this->assertSame([self::REST_SCORM_URL], $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_rest_500_fails_after_three_attempts(): void {
        $this->setValidConfig();
        $this->queue($this->rest(500), $this->rest(500), $this->rest(500), $this->rest(200, $this->scormBody()));

        $this->expectCode(fn() => mod_skilland_fetch_topic_scorm('t1'), 'error_scorm_fetch_failed');

        $this->assertSame([self::REST_SCORM_URL, self::REST_SCORM_URL, self::REST_SCORM_URL],
            $GLOBALS['_test_curl_requests']);
    }

    public function test_fetch_topic_scorm_invalid_json_fails(): void {
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
            'apikey' => 'unit-test-api-key-0123456789',
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
        $this->assertStringNotContainsString('unit-test-api-key-0123456789', $log);
        // The key was really sent, so its absence from the log is not an accident.
        $this->assertContains('Authorization: Bearer unit-test-api-key-0123456789', $GLOBALS['_test_curl_last']['headers']);
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

    /**
     * @dataProvider snapshot_failures
     */
    public function test_check_topic_snapshot_failure_returns_null_without_a_second_route(int $status, int $requests): void {
        $this->setValidConfig();
        $failures = array_fill(0, $requests, $status === 0 ? $this->refused() : $this->rest($status));
        $this->queue(...array_merge($failures, [$this->rest(200, $this->hashBody())]));
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
        $this->assertSame(array_fill(0, $requests, self::REST_HASH_URL), $GLOBALS['_test_curl_requests']);
    }

    public static function snapshot_failures(): array {
        return [
            '401' => [401, 1],
            '403' => [403, 1],
            '404' => [404, 1],
            '500 after three attempts' => [500, 3],
            'refused after three attempts' => [0, 3],
        ];
    }

    public function test_check_topic_snapshot_returns_null_for_an_empty_rest_body(): void {
        $this->setValidConfig();
        $this->queue(['body' => '{}', 'http_code' => 200, 'errno' => 0, 'error' => '']);
        \core\di::reset_container();

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
    }
}
