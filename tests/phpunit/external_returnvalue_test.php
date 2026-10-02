<?php

namespace mod_skilland\tests;

use core_external\external_api;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use PHPUnit\Framework\TestCase;

/**
 * SKL-665: every web service result must pass core's external_api::clean_returnvalue() against its
 * execute_returns(), including the error payloads. A null for a single structure is rejected by core
 * ("Invalid response value detected"), which hid the Skilland error from the teacher.
 */
class external_returnvalue_test extends TestCase {

    private const COURSE_ID = 10;
    private const CM_ID = 90;
    private const SKILLAND_ID = 7;

    private const CLASSES = [
        'fetch_courses', 'create_course', 'fetch_topics', 'fetch_lessons', 'provision_topic_scorm',
        'update_topic_scorm', 'check_topic_snapshot',
    ];

    private const GLOBALS_TO_RESET = [
        '_test_validated_contexts', '_test_call_order', '_test_login_denied_ids', '_test_denied_capabilities',
        '_test_capability_course_ids', '_test_curl_response', '_test_curl_responses', '_test_curl_requests',
        '_test_curl_last', '_test_customfield_value', '_test_get_coursemodule_from_id',
        '_test_get_coursemodule_from_instance', '_test_deleted_cmids', '_test_create_module_calls',
        '_test_lock_calls', '_test_events', '_test_cm_from_db',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $db = new \FakeDatabase();
        $db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $db->seed('skilland', [
            (object) ['id' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'name' => 'Topic one',
                'skilland_topicid' => 'topic-a1', 'scormcmid' => 50, 'scorm_provisioned' => 1,
                'snapshotid' => 'old-hash'],
        ]);
        $db->seed('course_modules', [
            (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'section' => 12],
            (object) ['id' => 50, 'instance' => 60, 'course' => self::COURSE_ID, 'section' => 12, 'modname' => 'scorm'],
        ]);
        $db->seed('course_sections', [(object) ['id' => 12, 'section' => 2, 'course' => self::COURSE_ID]]);
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object) ['id' => 2, 'email' => 'teacher@example.com', 'firstname' => 'T', 'lastname' => 'Eacher'];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ]];
        $GLOBALS['_test_customfield_value'] = [self::COURSE_ID => 'skill-a'];
        $GLOBALS['_test_denied_capabilities'] = [];
        $GLOBALS['_test_validated_contexts'] = [];
        $GLOBALS['_test_call_order'] = [];
        $cm = (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'section' => 12];
        $GLOBALS['_test_get_coursemodule_from_id'] = $cm;
        $GLOBALS['_test_get_coursemodule_from_instance'] = $cm;
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['_test_plugin_config'] = [];
        \mod_skilland\logger::reset_cache();
        parent::tearDown();
    }

    private static function connection_failure(): array {
        return ['body' => '', 'http_code' => 0, 'errno' => 7,
            'error' => 'Failed to connect to localhost port 8000: Connection refused'];
    }

    private static function server_error(): array {
        return ['body' => json_encode(['error' => 'upstream detail at http://internal:8000']), 'http_code' => 400,
            'errno' => 0, 'error' => ''];
    }

    private static function course_payload(): array {
        return ['body' => json_encode([
            'topics' => [['id' => 'topic-a1', 'name' => 'Topic', 'description' => '<p>Intro</p>', 'sortOrder' => 0]],
        ]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
    }

    private static function returns(string $class): external_description {
        return call_user_func(['\\mod_skilland\\external\\' . $class, 'execute_returns']);
    }

    // ---------------------------------------------------------------
    // Stub fidelity: clean_returnvalue() rejects what core rejects
    // ---------------------------------------------------------------

    public function test_null_single_structure_is_rejected(): void {
        $this->expectException(\invalid_response_exception::class);

        external_api::clean_returnvalue(self::returns('fetch_topics'), ['course' => null, 'topics' => [], 'error' => 'x']);
    }

    public function test_absent_optional_single_structure_passes(): void {
        $clean = external_api::clean_returnvalue(self::returns('fetch_topics'), ['topics' => [], 'error' => 'x']);

        $this->assertSame(['topics' => [], 'error' => 'x'], $clean);
    }

    public function test_missing_required_key_is_rejected(): void {
        $this->expectException(\invalid_response_exception::class);

        external_api::clean_returnvalue(self::returns('fetch_topics'), ['error' => 'x']);
    }

    public function test_non_array_multiple_structure_is_rejected(): void {
        $this->expectException(\invalid_response_exception::class);

        external_api::clean_returnvalue(self::returns('fetch_topics'), ['topics' => null]);
    }

    public function test_null_value_is_rejected_when_not_allowed(): void {
        $this->expectException(\invalid_response_exception::class);

        external_api::clean_returnvalue(new external_value(PARAM_TEXT, 'x', VALUE_REQUIRED, null, false), null);
    }

    public function test_unknown_keys_are_dropped(): void {
        $clean = external_api::clean_returnvalue(self::returns('fetch_topics'), ['topics' => [], 'extra' => 1]);

        $this->assertSame(['topics' => []], $clean);
    }

    // ---------------------------------------------------------------
    // fetch_topics
    // ---------------------------------------------------------------

    public static function fetch_topics_failures(): array {
        return [
            'connection failure' => [self::connection_failure()],
            'server error' => [self::server_error()],
        ];
    }

    /**
     * @dataProvider fetch_topics_failures
     */
    public function test_fetch_topics_error_payload_passes_response_validation(array $response): void {
        $GLOBALS['_test_curl_response'] = $response;

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', self::COURSE_ID);

        $this->assertArrayNotHasKey('course', $result);
        $clean = external_api::clean_returnvalue(self::returns('fetch_topics'), $result);
        $this->assertSame([], $clean['topics']);
        $this->assertSame('error_api_unavailable', $clean['error']);
    }

    public function test_fetch_topics_success_payload_passes_response_validation(): void {
        $GLOBALS['_test_curl_response'] = self::course_payload();

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', self::COURSE_ID);
        $clean = external_api::clean_returnvalue(self::returns('fetch_topics'), $result);

        $this->assertSame('skill-a', $clean['course']['id']);
        $this->assertSame('skill-a', $clean['course']['code'], 'A skill has no code: the id stands in');
        $this->assertCount(1, $clean['topics']);
        $this->assertSame('topic-a1', $clean['topics'][0]['id']);
        $this->assertNull($clean['error']);
    }

    public function test_fetch_lessons_success_payload_passes_response_validation_with_integer_positions(): void {
        $contents = ['body' => json_encode(['contents' => [
            ['id' => 'l1', 'name' => 'One', 'type' => 'lesson', 'content' => '<p>1</p>', 'updatedAt' => '2026-01-01T00:00:00Z'],
            ['id' => 'q1', 'name' => 'Quiz', 'type' => 'assessment', 'content' => '<p>q</p>'],
            ['id' => 'l2', 'name' => 'Two', 'type' => 'lesson', 'content' => '<p>2</p>', 'updatedAt' => '2026-01-02T00:00:00Z'],
        ]]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
        $GLOBALS['_test_curl_responses'] = [self::course_payload(), $contents];

        $result = \mod_skilland\external\fetch_lessons::execute('topic-a1', self::COURSE_ID);
        $clean = external_api::clean_returnvalue(self::returns('fetch_lessons'), $result);

        $this->assertSame(PARAM_INT, self::returns('fetch_lessons')->keys['lessons']->content->keys['position']->type);
        $this->assertNull($clean['error']);
        $this->assertSame(['l1', 'l2'], array_column($clean['lessons'], 'id'));
        $this->assertSame([1, 2], array_column($clean['lessons'], 'position'));
    }

    // ---------------------------------------------------------------
    // Every web service's error payload
    // ---------------------------------------------------------------

    public static function service_provider(): array {
        return [
            'fetch_courses' => ['fetch_courses', [self::COURSE_ID]],
            'create_course' => ['create_course', [self::COURSE_ID]],
            'fetch_topics' => ['fetch_topics', ['skill-a', self::COURSE_ID]],
            'fetch_lessons' => ['fetch_lessons', ['topic-a1', self::COURSE_ID]],
            'provision_topic_scorm' => ['provision_topic_scorm', [self::SKILLAND_ID, self::CM_ID]],
            'update_topic_scorm' => ['update_topic_scorm', [self::SKILLAND_ID, self::CM_ID]],
            'check_topic_snapshot' => ['check_topic_snapshot', [self::SKILLAND_ID]],
        ];
    }

    /**
     * @dataProvider service_provider
     */
    public function test_api_failure_payload_passes_response_validation(string $class, array $args): void {
        if ($class === 'fetch_lessons') {
            // The topic check runs before the try block and throws on an API failure, so let it pass
            // and fail the lessons request, which is handled inside the try.
            $GLOBALS['_test_curl_responses'] = [self::course_payload(), self::connection_failure()];
        } else {
            $GLOBALS['_test_curl_response'] = self::connection_failure();
        }
        if ($class === 'provision_topic_scorm') {
            // Not provisioned yet, so the call reaches the API.
            $GLOBALS['DB']->update_record('skilland', (object) ['id' => self::SKILLAND_ID, 'scormcmid' => null,
                'scorm_provisioned' => null]);
        }

        $result = call_user_func_array(['\\mod_skilland\\external\\' . $class, 'execute'], $args);
        $clean = external_api::clean_returnvalue(self::returns($class), $result);

        $this->assertIsString($clean['error']);
        $this->assertNotEmpty($clean['error']);
    }

    // ---------------------------------------------------------------
    // Structural scan: no null for a key declared as a single structure
    // ---------------------------------------------------------------

    /**
     * Names of every key declared as an external_single_structure anywhere in the returns tree.
     */
    private static function single_structure_keys(external_description $description): array {
        $keys = [];
        if ($description instanceof external_single_structure) {
            foreach ($description->keys as $key => $sub) {
                if ($sub instanceof external_single_structure) {
                    $keys[] = $key;
                }
                $keys = array_merge($keys, self::single_structure_keys($sub));
            }
        } else if ($description instanceof external_multiple_structure) {
            $keys = self::single_structure_keys($description->content);
        }
        return $keys;
    }

    public function test_no_null_single_structure_in_any_web_service(): void {
        $this->assertContains('course', self::single_structure_keys(self::returns('fetch_topics')));

        $files = glob(__DIR__ . '/../../src/classes/external/*.php');
        $classes = array_values(array_diff(array_map(fn($f) => basename($f, '.php'), $files), ['base']));
        $this->assertEqualsCanonicalizing(self::CLASSES, $classes, 'New web service: add it to CLASSES and the providers');

        $offenders = [];
        foreach ($classes as $class) {
            $source = file_get_contents(__DIR__ . '/../../src/classes/external/' . $class . '.php');
            foreach (array_unique(self::single_structure_keys(self::returns($class))) as $key) {
                if (preg_match('/[\'"]' . preg_quote($key, '/') . '[\'"]\s*=>\s*null\b/i', $source)) {
                    $offenders[] = $class . ': ' . $key;
                }
            }
        }

        $this->assertSame([], $offenders);
    }
}
