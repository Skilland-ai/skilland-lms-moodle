<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-659: an \Error (TypeError from a malformed API answer, a bug in a helper) inside a web
 * service's try block comes back as the declared payload with `error` set, never as a raw
 * PHP error. Access checks stay outside the try, so they still propagate.
 */
class external_throwable_test extends TestCase {

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
        \core\di::reset_container();
        $this->useDatabase(new \FakeDatabase());
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
        $cm = (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID,
            'section' => 12];
        $GLOBALS['_test_get_coursemodule_from_id'] = $cm;
        $GLOBALS['_test_get_coursemodule_from_instance'] = $cm;
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        \core\di::reset_container();
        parent::tearDown();
    }

    private function useDatabase(\FakeDatabase $db): void {
        $db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $db->seed('skilland', [
            (object) ['id' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'name' => 'Topic one',
                'skilland_topicid' => 'topic-a1', 'scormcmid' => 50, 'scorm_provisioned' => 1,
                'snapshotid' => 'old-hash'],
        ]);
        $db->seed('course_modules', [
            (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'section' => 12],
        ]);
        $db->seed('course_sections', [(object) ['id' => 12, 'section' => 2, 'course' => self::COURSE_ID]]);
        $GLOBALS['DB'] = $db;
    }

    /**
     * Queue $good valid GraphQL answers, then answers whose `data` is a string, so
     * mod_skilland_graphql() fails its array return type with a TypeError.
     */
    private function forceTypeErrorFromApi(int $good): void {
        $valid = ['body' => json_encode(['data' => ['course' => ['id' => 'skill-a', 'name' => 'Skill', 'topics' => [
            ['id' => 'topic-a1', 'name' => 'Topic', 'code' => '', 'description' => ''],
        ]]]]), 'http_code' => 200, 'errno' => 0, 'error' => ''];
        $broken = ['body' => '{"data":"not-an-object"}', 'http_code' => 200, 'errno' => 0, 'error' => ''];
        $GLOBALS['_test_curl_responses'] = array_merge(array_fill(0, $good, $valid), array_fill(0, 3, $broken));
    }

    /** Make reading the skilland record inside the try throw an \Error. */
    private function forceErrorFromDatabase(): void {
        $this->useDatabase(new class extends \FakeDatabase {
            public function get_record(string $table, array $conditions, string $fields = '*', int $strictness = 0) {
                if ($table === 'skilland') {
                    throw new \Error('forced error');
                }
                return parent::get_record($table, $conditions, $fields, $strictness);
            }
        });
    }

    /**
     * Arrange for an \Error inside the service's try block.
     *
     * @param string $force How to force it.
     */
    private function force(string $force): void {
        switch ($force) {
            case 'api':
                $this->forceTypeErrorFromApi(0);
                break;
            case 'api_after_topic_check':
                // fetch_lessons checks the topic belongs to the course (one API call) before its try.
                $this->forceTypeErrorFromApi(1);
                break;
            case 'rest':
                // The REST transport is type-safe, so the \Error comes from the bound api_client.
                \fake_api_client::install()->respond_rest('scorm-hash', new \Error('forced error'));
                break;
            case 'db':
                $this->forceErrorFromDatabase();
                break;
            case 'update_hook':
                \fake_topic_scorm_updater::install(function () {
                    throw new \Error('forced error');
                });
                break;
            default:
                $this->fail('Unknown force mode ' . $force);
        }
    }

    /**
     * class, capability, args, how to force an \Error inside the try.
     */
    public static function service_provider(): array {
        return [
            'fetch_courses' => ['fetch_courses', 'mod/skilland:accessstudio', [self::COURSE_ID], 'api'],
            'create_course' => ['create_course', 'mod/skilland:accessstudio', [self::COURSE_ID], 'api'],
            'fetch_topics' => ['fetch_topics', 'mod/skilland:accessstudio', ['skill-a', self::COURSE_ID], 'api'],
            'fetch_lessons' => ['fetch_lessons', 'mod/skilland:accessstudio', ['topic-a1', self::COURSE_ID],
                'api_after_topic_check'],
            'provision_topic_scorm' => ['provision_topic_scorm', 'mod/skilland:provision',
                [self::SKILLAND_ID, self::CM_ID], 'db'],
            'update_topic_scorm' => ['update_topic_scorm', 'mod/skilland:provision',
                [self::SKILLAND_ID, self::CM_ID], 'update_hook'],
            'check_topic_snapshot' => ['check_topic_snapshot', 'mod/skilland:provision', [self::SKILLAND_ID], 'rest'],
        ];
    }

    private function call(string $class, array $args) {
        return call_user_func_array(['\\mod_skilland\\external\\' . $class, 'execute'], $args);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_an_error_inside_the_try_returns_the_error_payload(string $class, string $capability,
            array $args, string $force): void {
        $this->force($force);

        $result = $this->call($class, $args);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('error_api_unavailable', $result['error']);
        $returns = call_user_func(['\\mod_skilland\\external\\' . $class, 'execute_returns']);
        $cleaned = \core_external\external_api::clean_returnvalue($returns, $result);
        $this->assertSame('error_api_unavailable', $cleaned['error']);
        $log = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertMatchesRegularExpression('/' . $class . ' error: (TypeError|Error): /', $log,
            'The \\Error must be caught by the service catch, not an inner one');
    }

    public function test_fetch_topics_error_payload_has_no_course_key(): void {
        $this->force('api');

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', self::COURSE_ID);

        $this->assertArrayNotHasKey('course', $result);
        $this->assertSame([], $result['topics']);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_require_login_exception_still_propagates(string $class, string $capability,
            array $args, string $force): void {
        $this->force($force);
        $GLOBALS['_test_login_denied_ids'] = [str_starts_with($capability, 'mod/skilland:provision') ?
            self::CM_ID : self::COURSE_ID];

        $this->expectException(\require_login_exception::class);

        $this->call($class, $args);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_required_capability_exception_still_propagates(string $class, string $capability,
            array $args, string $force): void {
        $this->force($force);
        $GLOBALS['_test_denied_capabilities'] = [$capability];

        $this->expectException(\required_capability_exception::class);

        $this->call($class, $args);
    }

    public function test_every_try_in_a_service_has_a_throwable_catch(): void {
        $files = glob(realpath(__DIR__ . '/../../src') . '/classes/external/*.php');
        $this->assertNotEmpty($files);
        $tries = 0;
        foreach ($files as $file) {
            $tokens = \PhpToken::tokenize(file_get_contents($file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!$tokens[$i]->is(T_TRY)) {
                    continue;
                }
                $tries++;
                // Walk to the matching close brace of the try block, then read its catch clauses.
                $j = $i + 1;
                while (!$tokens[$j]->is('{')) {
                    $j++;
                }
                $depth = 0;
                for (; $j < $count; $j++) {
                    if ($tokens[$j]->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                        $depth++;
                    } else if ($tokens[$j]->is('}')) {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $catches = '';
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($tokens[$k]->isIgnorable()) {
                        continue;
                    }
                    if (!$tokens[$k]->is(T_CATCH)) {
                        break;
                    }
                    // Collect the catch signature up to its opening brace, then skip its body.
                    while (!$tokens[$k]->is('{')) {
                        $catches .= $tokens[$k]->text;
                        $k++;
                    }
                    $depth = 0;
                    for (; $k < $count; $k++) {
                        if ($tokens[$k]->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                            $depth++;
                        } else if ($tokens[$k]->is('}')) {
                            $depth--;
                            if ($depth === 0) {
                                break;
                            }
                        }
                    }
                }
                $this->assertStringContainsString('catch (\\Throwable', $catches,
                    basename($file) . ' has a try without a catch (\\Throwable ...)');
            }
        }
        $this->assertGreaterThanOrEqual(8, $tries);
    }
}
