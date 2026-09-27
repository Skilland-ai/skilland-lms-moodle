<?php

namespace mod_skilland\tests;

use core_external\external_api;
use PHPUnit\Framework\TestCase;

/**
 * SKL-697: check_topic_snapshot's studentattemptcount field sizes the destructive-confirmation
 * copy shown before an update or a topic change would delete student progress. It is computed by
 * skilland_count_topic_student_attempts() (locallib.php), independently of the remote Skilland
 * API call the rest of the web service makes.
 */
class check_topic_snapshot_test extends TestCase {

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

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $this->db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $this->db->seed('skilland', [
            (object) ['id' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'name' => 'Topic one',
                'skilland_topicid' => 'topic-a1', 'scormcmid' => 50, 'scorm_provisioned' => 1,
                'snapshotid' => 'old-hash'],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'section' => 12],
            (object) ['id' => 50, 'instance' => 60, 'course' => self::COURSE_ID, 'section' => 12, 'modname' => 'scorm'],
        ]);
        $this->db->seed('course_sections', [(object) ['id' => 12, 'section' => 2, 'course' => self::COURSE_ID]]);
        $GLOBALS['DB'] = $this->db;
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
        $GLOBALS['_test_curl_response'] = self::connection_failure();
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

    private function execute_clean(): array {
        $result = \mod_skilland\external\check_topic_snapshot::execute(self::SKILLAND_ID);
        return external_api::clean_returnvalue(\mod_skilland\external\check_topic_snapshot::execute_returns(), $result);
    }

    public function test_zero_when_scorm_attempt_table_is_unavailable(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);

        $this->assertSame(0, $this->execute_clean()['studentattemptcount']);
    }

    public function test_zero_when_topic_has_no_student_attempts(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->set_records_sql_handler(fn() => []);

        $this->assertSame(0, $this->execute_clean()['studentattemptcount']);
    }

    public function test_counts_distinct_students_with_attempts(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        // A real "SELECT DISTINCT userid ..." already dedupes repeat attempts by the same
        // student; the fake DB does not parse SQL, so the handler returns the already-distinct
        // rows a real query would produce.
        $this->db->set_records_sql_handler(fn() => [
            (object) ['userid' => 50],
            (object) ['userid' => 51],
        ]);

        $this->assertSame(2, $this->execute_clean()['studentattemptcount']);
    }

    public function test_zero_when_activity_has_no_linked_scorm(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->update_record('skilland', (object) ['id' => self::SKILLAND_ID, 'scormcmid' => null]);
        $GLOBALS['_test_get_coursemodule_from_id'] = false;

        $this->assertSame(0, $this->execute_clean()['studentattemptcount']);
    }

    // ---------------------------------------------------------------
    // The remote hash comes from the REST route (SKL-791)
    // ---------------------------------------------------------------

    private static function rest(int $code, ?array $body = null): array {
        return ['body' => $body === null ? '' : json_encode($body), 'http_code' => $code, 'errno' => 0, 'error' => ''];
    }

    private static function hash_body(string $contenthash): array {
        return ['contentHash' => $contenthash, 'packageHash' => 'p', 'generatedAt' => '2026-01-01T00:00:00Z',
            'hasPackage' => true, 'isStale' => false];
    }

    public function test_reports_the_rest_content_hash_with_a_bearer_key(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $GLOBALS['_test_curl_responses'] = [self::rest(200, self::hash_body('new-hash'))];

        $result = $this->execute_clean();

        $this->assertTrue($result['isstale']);
        $this->assertSame('new-hash', $result['contenthash']);
        $this->assertNull($result['error'] ?? null);
        $this->assertSame(['https://localhost:8000/api/moodle/topics/topic-a1/scorm-hash'],
            $GLOBALS['_test_curl_requests']);
        $this->assertContains('Authorization: Bearer key1', $GLOBALS['_test_curl_last']['headers']);
    }

    public function test_same_rest_hash_is_not_stale(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $GLOBALS['_test_curl_responses'] = [self::rest(200, self::hash_body('old-hash'))];

        $result = $this->execute_clean();

        $this->assertFalse($result['isstale']);
        $this->assertSame('old-hash', $result['contenthash']);
    }

    public function test_rest_404_falls_back_to_the_graphql_endpoint(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $GLOBALS['_test_curl_responses'] = [
            self::rest(404),
            ['body' => json_encode(['data' => ['topicScormHash' => self::hash_body('gql-hash')]]), 'http_code' => 200,
                'errno' => 0, 'error' => ''],
        ];

        $result = $this->execute_clean();

        $this->assertSame('gql-hash', $result['contenthash']);
        $this->assertSame([
            'https://localhost:8000/api/moodle/topics/topic-a1/scorm-hash',
            'https://localhost:8000/graphql',
        ], $GLOBALS['_test_curl_requests']);
    }

    public function test_rest_401_without_graphql_endpoint_reports_unreachable(): void {
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);
        $GLOBALS['_test_plugin_config']['mod_skilland']->graphql_endpoint = '';
        $GLOBALS['_test_plugin_config']['mod_skilland']->frontend_url = 'https://app.skilland.test';
        $GLOBALS['_test_curl_responses'] = [self::rest(401)];

        $result = $this->execute_clean();

        $this->assertFalse($result['isstale']);
        $this->assertSame('', $result['contenthash']);
        $this->assertSame('Could not reach Skilland API', $result['error']);
        $this->assertSame(['https://app.skilland.test/api/moodle/topics/topic-a1/scorm-hash'],
            $GLOBALS['_test_curl_requests']);
    }
}
