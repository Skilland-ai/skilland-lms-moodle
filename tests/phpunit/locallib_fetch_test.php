<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_fetch_test extends TestCase {

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
    // mod_skilland_fetch_topic_scorm()
    // ---------------------------------------------------------------

    public function test_fetch_topic_scorm_throws_without_topicid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_config_missing_topicid');

        mod_skilland_fetch_topic_scorm('');
    }

    public function test_fetch_topic_scorm_throws_when_not_available(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['topicScorm' => null]);

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_not_available', $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
        }
    }

    /**
     * Stub a GraphQL error response with the given extensions.code.
     *
     * @param string $code
     * @param string $message
     */
    private function stubGraphqlError(string $code, string $message = 'nope'): void {
        $GLOBALS['_test_curl_response'] = [
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
        $this->stubGraphqlError($code);

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
        }
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
        $this->stubGraphqlError('SKILLAND_INVALID_API_KEY');

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\mod_skilland\graphql_exception $e) {
            $this->assertSame('error_config_invalid_credentials', $e->errorcode);
            $this->assertSame('SKILLAND_INVALID_API_KEY', $e->graphqlcode);
        }
    }

    public function test_fetch_topic_scorm_rethrows_a_redirect_unchanged(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => '',
            'http_code' => 302,
            'errno' => 0,
            'error' => '',
        ];

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_http_redirect', $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
            $this->assertSame(302, $e->a);
        }
    }

    public function test_fetch_topic_scorm_unknown_code_fails_without_a(): void {
        $this->setValidConfig();
        $this->stubGraphqlError('SOMETHING_ELSE', 'secret detail');

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_fetch_failed', $e->errorcode);
            $this->assertNull($e->a);
        }
    }

    public function test_fetch_topic_scorm_malformed_errors_become_fetch_failed(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => '{"errors":[null]}',
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        try {
            mod_skilland_fetch_topic_scorm('t1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_fetch_failed', $e->errorcode);
        }
    }

    public function test_fetch_topic_scorm_returns_package_info(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topicScorm' => [
                'packageUrl' => 'https://cdn.example.com/package.zip',
                'packageSize' => 1024000,
                'packageHash' => 'sha256:abc123',
                'generatedAt' => '2024-01-01T00:00:00Z',
                'expiresAt' => '2024-01-02T00:00:00Z',
                'mappings' => [
                    ['lessonId' => 'l1', 'scoId' => 'sco1'],
                    ['lessonId' => 'l2', 'scoId' => 'sco2'],
                ],
            ],
        ]);

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertEquals('https://cdn.example.com/package.zip', $result['packageUrl']);
        $this->assertEquals(1024000, $result['packageSize']);
        $this->assertEquals('sha256:abc123', $result['packageHash']);
        $this->assertCount(2, $result['mappings']);
        $this->assertEquals('sco1', $result['mappings']['l1']);
        $this->assertEquals('sco2', $result['mappings']['l2']);
    }

    public function test_fetch_topic_scorm_handles_empty_mappings(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topicScorm' => [
                'packageUrl' => 'https://cdn.example.com/package.zip',
                'packageSize' => 512,
                'packageHash' => '',
                'generatedAt' => '',
                'expiresAt' => '',
                'mappings' => [],
            ],
        ]);

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertEmpty($result['mappings']);
        $this->assertEquals('', $result['packageHash']);
    }

    public function test_fetch_topic_scorm_skips_mappings_with_null_ids(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topicScorm' => [
                'packageUrl' => 'url',
                'mappings' => [
                    ['lessonId' => 'l1', 'scoId' => 'sco1'],
                    ['lessonId' => null, 'scoId' => 'sco2'],
                    ['lessonId' => 'l3', 'scoId' => null],
                ],
            ],
        ]);

        $result = mod_skilland_fetch_topic_scorm('t1');

        // Only l1->sco1 should be included (others have null ids).
        $this->assertCount(1, $result['mappings']);
        $this->assertEquals('sco1', $result['mappings']['l1']);
    }

    public function test_fetch_topic_scorm_skips_non_array_mappings(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topicScorm' => [
                'packageUrl' => 'url',
                'mappings' => [
                    ['lessonId' => 'l1', 'scoId' => 'sco1'],
                    'invalid-string',
                    42,
                ],
            ],
        ]);

        $result = mod_skilland_fetch_topic_scorm('t1');

        $this->assertCount(1, $result['mappings']);
    }

    public function test_fetch_topic_scorm_wraps_api_error(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => 'Server Error',
            'http_code' => 500,
            'errno' => 0,
            'error' => '',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_scorm_fetch_failed');

        mod_skilland_fetch_topic_scorm('t1');
    }

    // ---------------------------------------------------------------
    // mod_skilland_check_topic_snapshot()
    // ---------------------------------------------------------------

    public function test_check_topic_snapshot_returns_data_on_success(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse([
            'topicScormHash' => [
                'contentHash' => 'hash123',
                'packageHash' => 'phash456',
                'generatedAt' => '2024-06-01T00:00:00Z',
                'hasPackage' => true,
                'isStale' => false,
            ],
        ]);

        // Clear any test hook.
        unset($GLOBALS['_test_topic_snapshot']);

        $result = mod_skilland_check_topic_snapshot('t1');

        $this->assertIsArray($result);
        $this->assertEquals('hash123', $result['contentHash']);
        $this->assertTrue($result['hasPackage']);
    }

    public function test_check_topic_snapshot_returns_null_on_api_failure(): void {
        $this->setValidConfig();
        $GLOBALS['_test_curl_response'] = [
            'body' => 'Error',
            'http_code' => 500,
            'errno' => 0,
            'error' => '',
        ];

        unset($GLOBALS['_test_topic_snapshot']);

        $result = mod_skilland_check_topic_snapshot('t1');

        // Should catch exception and return null.
        $this->assertNull($result);
    }

    public function test_check_topic_snapshot_returns_null_when_hash_missing(): void {
        $this->setValidConfig();
        $this->stubGraphqlResponse(['topicScormHash' => null]);

        unset($GLOBALS['_test_topic_snapshot']);

        $result = mod_skilland_check_topic_snapshot('t1');

        $this->assertNull($result);
    }
}
