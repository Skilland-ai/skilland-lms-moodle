<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-670: web services return a generic or allowlisted message in `error`, never the raw
 * exception text (endpoint, curl error, SQL); the raw message only goes to the log.
 */
class client_errors_test extends TestCase {

    private const COURSE_ID = 10;
    private const CM_ID = 90;
    private const SKILLAND_ID = 7;

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

    private function set_devmode(bool $on): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->devmode = $on ? 1 : 0;
        \mod_skilland\logger::reset_cache();
    }

    // ---------------------------------------------------------------
    // mod_skilland_client_error_message()
    // ---------------------------------------------------------------

    public static function generic_exceptions(): array {
        return [
            'error_graphql_http' => [new \moodle_exception('error_graphql_http', 'mod_skilland', '',
                'HTTP error when calling Skilland API at http://localhost:8000/graphql')],
            'error_graphql' => [new \moodle_exception('error_graphql', 'mod_skilland', '', 'Organization not found')],
            'error_graphql_invalid_json' => [new \moodle_exception('error_graphql_invalid_json', 'mod_skilland')],
            'error_scorm_fetch_failed' => [new \moodle_exception('error_scorm_fetch_failed', 'mod_skilland')],
            'dml_exception' => [new \dml_exception('dmlreadexception', null, 'SELECT * FROM mdl_skilland')],
            'plain exception' => [new \Exception('curl: Connection refused http://localhost:8000')],
        ];
    }

    /**
     * @dataProvider generic_exceptions
     */
    public function test_generic_message_for_internal_errors(\Throwable $e): void {
        $this->assertSame('error_api_unavailable', mod_skilland_client_error_message($e));
    }

    public static function allowlisted_codes(): array {
        $codes = [
            'error_config_missing_orgid', 'error_config_missing_apikey', 'error_config_missing_endpoint',
            'error_config_invalid_credentials', 'error_config_missing_topicid', 'error_config_missing_courseid',
            'error_config_missing_lessonid', 'error_http_redirect', 'error_scorm_not_available',
            'error_plugin_disabled', 'error_course_not_mapped', 'error_course_not_mapped_to_skill',
            'error_lessons_not_in_topic', 'error_provision_in_progress',
        ];
        return array_combine($codes, array_map(fn($code) => [$code], $codes));
    }

    /**
     * @dataProvider allowlisted_codes
     */
    public function test_allowlisted_code_passes_its_lang_string(string $code): void {
        $e = new \moodle_exception($code, 'mod_skilland', '', 'Invalid API key at http://localhost:8000',
            'debug details');

        $message = mod_skilland_client_error_message($e);

        $this->assertSame($code, $message);
        $this->assertStringNotContainsString('localhost', $message);
        $this->assertStringNotContainsString('debug details', $message);
    }

    public function test_allowlisted_code_of_another_component_is_generic(): void {
        $e = new \moodle_exception('error_course_not_mapped', 'core');

        $this->assertSame('error_api_unavailable', mod_skilland_client_error_message($e));
    }

    public function test_devmode_appends_raw_message(): void {
        $this->set_devmode(true);
        $e = new \Exception('curl: Connection refused http://localhost:8000');

        $this->assertSame('error_api_unavailable (curl: Connection refused http://localhost:8000)',
            mod_skilland_client_error_message($e));
    }

    public function test_every_allowlisted_code_exists_in_both_languages(): void {
        $string = [];
        include(__DIR__ . '/../../src/lang/en/skilland.php');
        $en = $string;
        $string = [];
        include(__DIR__ . '/../../src/lang/es/skilland.php');
        $es = $string;

        foreach (array_merge(['error_api_unavailable'], MOD_SKILLAND_CLIENT_ERROR_CODES) as $code) {
            $this->assertArrayHasKey($code, $en, $code . ' missing in en');
            $this->assertArrayHasKey($code, $es, $code . ' missing in es');
        }
    }

    // ---------------------------------------------------------------
    // External functions on a connection failure
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
    public function test_connection_failure_returns_no_internal_details(string $class, array $args): void {
        $GLOBALS['_test_curl_response'] = [
            'body' => '',
            'http_code' => 0,
            'errno' => 7,
            'error' => 'Failed to connect to localhost port 8000: Connection refused',
        ];

        if ($class === 'provision_topic_scorm') {
            // Not provisioned yet, so the call reaches the API.
            $GLOBALS['DB']->update_record('skilland', (object) ['id' => self::SKILLAND_ID, 'scormcmid' => null,
                'scorm_provisioned' => null]);
        }

        try {
            $result = call_user_func_array(['\\mod_skilland\\external\\' . $class, 'execute'], $args);
        } catch (\moodle_exception $e) {
            // fetch_lessons checks the topic against the mapped course before its try block, so the
            // failure propagates; the exception still carries only the HTTP status.
            $this->assertSame('fetch_lessons', $class, $e->getMessage());
            $this->assertSame('error_graphql_http', $e->errorcode);
            $this->assertSame('HTTP 0', $e->a);
            return;
        }

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['error']);
        if ($class !== 'check_topic_snapshot') {
            // check_topic_snapshot keeps its own fixed message (SKL-671).
            $this->assertSame('error_api_unavailable', $result['error']);
        }
        foreach (['localhost', 'host.docker.internal', 'errno', 'http'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $result['error']);
        }
        $log = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('Connection refused', $log);
    }
}
