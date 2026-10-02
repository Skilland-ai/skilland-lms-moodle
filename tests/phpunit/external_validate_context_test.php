<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-666: every web service is a core_external class that calls validate_context() on its
 * course or module context before require_capability(), so a user who cannot enter the context
 * (suspended enrolment, hidden course) is refused even when a role still grants the capability.
 */
class external_validate_context_test extends TestCase {

    private const COURSE_ID = 10;
    private const CM_ID = 90;
    private const SKILLAND_ID = 7;

    private const SERVICES = [
        'mod_skilland_fetch_courses_ajax' => 'fetch_courses',
        'mod_skilland_create_course_ajax' => 'create_course',
        'mod_skilland_fetch_topics_ajax' => 'fetch_topics',
        'mod_skilland_fetch_lessons_ajax' => 'fetch_lessons',
        'mod_skilland_provision_topic_scorm_ajax' => 'provision_topic_scorm',
        'mod_skilland_update_topic_scorm_ajax' => 'update_topic_scorm',
        'mod_skilland_check_topic_snapshot' => 'check_topic_snapshot',
    ];

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
        $GLOBALS['_test_get_coursemodule_from_id'] = $this->cm();
        $GLOBALS['_test_get_coursemodule_from_instance'] = $this->cm();
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function cm(): \stdClass {
        return (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID,
            'section' => 12];
    }

    /** A REST answer that serves as the skills/topics payload for every query. */
    private function queueResponses(int $count = 3): void {
        $body = json_encode([
            'skills' => [['id' => 'skill-a', 'name' => 'Skill', 'status' => 'Published']],
            'topics' => [['id' => 'topic-a1', 'name' => 'Topic', 'description' => '']],
        ]);
        $GLOBALS['_test_curl_responses'] = array_fill(0, $count,
            ['body' => $body, 'http_code' => 200, 'errno' => 0, 'error' => '']);
    }

    /**
     * class, capability, expected context class, expected instance id, args.
     */
    public static function service_provider(): array {
        return [
            'fetch_courses' => ['fetch_courses', 'mod/skilland:accessstudio', \context_course::class,
                self::COURSE_ID, [self::COURSE_ID]],
            'create_course' => ['create_course', 'mod/skilland:accessstudio', \context_course::class,
                self::COURSE_ID, [self::COURSE_ID]],
            'fetch_topics' => ['fetch_topics', 'mod/skilland:accessstudio', \context_course::class,
                self::COURSE_ID, ['skill-a', self::COURSE_ID]],
            'fetch_lessons' => ['fetch_lessons', 'mod/skilland:accessstudio', \context_course::class,
                self::COURSE_ID, ['topic-a1', self::COURSE_ID]],
            'provision_topic_scorm' => ['provision_topic_scorm', 'mod/skilland:provision', \context_module::class,
                self::CM_ID, [self::SKILLAND_ID, self::CM_ID]],
            'update_topic_scorm' => ['update_topic_scorm', 'mod/skilland:provision', \context_module::class,
                self::CM_ID, [self::SKILLAND_ID, self::CM_ID]],
            'check_topic_snapshot' => ['check_topic_snapshot', 'mod/skilland:provision', \context_module::class,
                self::CM_ID, [self::SKILLAND_ID]],
        ];
    }

    private function call(string $class, array $args) {
        return call_user_func_array(['\\mod_skilland\\external\\' . $class, 'execute'], $args);
    }

    // ---------------------------------------------------------------
    // Behaviour
    // ---------------------------------------------------------------

    /**
     * @dataProvider service_provider
     */
    public function test_validates_expected_context_before_capability(string $class, string $capability,
            string $contextclass, int $instanceid, array $args): void {
        $this->queueResponses();

        $this->call($class, $args);

        $this->assertCount(1, $GLOBALS['_test_validated_contexts']);
        $context = $GLOBALS['_test_validated_contexts'][0];
        $this->assertInstanceOf($contextclass, $context);
        $this->assertSame($instanceid, $context->instanceid);

        $order = $GLOBALS['_test_call_order'];
        $this->assertNotEmpty($order);
        $this->assertSame('validate_context', $order[0][0], 'validate_context() must run before require_capability()');
        $this->assertSame(['require_capability', $capability], $order[1]);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_suspended_user_is_refused_even_with_the_capability(string $class, string $capability,
            string $contextclass, int $instanceid, array $args): void {
        // The role still grants every capability, but the user cannot enter the context.
        $GLOBALS['_test_login_denied_ids'] = [$instanceid];
        $this->queueResponses();
        $queued = count($GLOBALS['_test_curl_responses']);

        try {
            $this->call($class, $args);
            $this->fail('require_login_exception expected');
        } catch (\require_login_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }

        $this->assertCount($queued, $GLOBALS['_test_curl_responses'], 'No Skilland API call may be made');
        $this->assertEmpty($GLOBALS['_test_curl_requests'] ?? []);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? [], 'No module may be deleted');
        $this->assertEmpty($GLOBALS['_test_create_module_calls'] ?? [], 'No module may be created');
        $this->assertNotContains(['require_capability', $capability], $GLOBALS['_test_call_order']);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_missing_capability_is_refused(string $class, string $capability,
            string $contextclass, int $instanceid, array $args): void {
        $GLOBALS['_test_denied_capabilities'] = [$capability];
        $this->queueResponses();
        $queued = count($GLOBALS['_test_curl_responses']);

        try {
            $this->call($class, $args);
            $this->fail('required_capability_exception expected');
        } catch (\required_capability_exception $e) {
            $this->assertInstanceOf(\required_capability_exception::class, $e);
        }

        $this->assertCount(1, $GLOBALS['_test_validated_contexts']);
        $this->assertCount($queued, $GLOBALS['_test_curl_responses']);
        $this->assertEmpty($GLOBALS['_test_deleted_cmids'] ?? []);
    }

    /**
     * @dataProvider service_provider
     */
    public function test_allowed_call_returns_the_declared_structure(string $class, string $capability,
            string $contextclass, int $instanceid, array $args): void {
        $this->queueResponses();

        $result = $this->call($class, $args);

        $returns = call_user_func(['\\mod_skilland\\external\\' . $class, 'execute_returns']);
        $this->assertInstanceOf(\core_external\external_single_structure::class, $returns);
        $this->assertIsArray($result);
        $this->assertSame(array_keys($returns->keys), array_keys($result));
    }

    public function test_happy_paths_return_data(): void {
        $this->queueResponses();
        $topics = \mod_skilland\external\fetch_topics::execute('skill-a', self::COURSE_ID);
        $this->assertNull($topics['error']);
        $this->assertSame(['topic-a1'], array_column($topics['topics'], 'id'));

        $this->queueResponses();
        $lessons = \mod_skilland\external\fetch_lessons::execute('topic-a1', self::COURSE_ID);
        $this->assertNull($lessons['error']);
    }

    // ---------------------------------------------------------------
    // Structure
    // ---------------------------------------------------------------

    private static function srcDir(): string {
        return realpath(__DIR__ . '/../../src');
    }

    private static function serviceFiles(): array {
        $files = glob(self::srcDir() . '/classes/external/*.php');
        return array_values(array_filter($files, fn($f) => basename($f) !== 'base.php'));
    }

    public function test_every_service_class_extends_base_and_defines_the_three_methods(): void {
        $files = self::serviceFiles();
        $this->assertCount(7, $files);
        foreach ($files as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            $this->assertStringContainsString("namespace mod_skilland\\external;", $source, $name);
            $this->assertMatchesRegularExpression('/\bclass ' . $name . ' extends base \{/', $source, $name);
            foreach (['execute_parameters', 'execute', 'execute_returns'] as $method) {
                $this->assertMatchesRegularExpression('/public static function ' . $method . '\(/', $source,
                    "$name::$method()");
            }
            $this->assertTrue(is_subclass_of('\\mod_skilland\\external\\' . $name, \mod_skilland\external\base::class));
        }
    }

    public function test_base_extends_core_external_api(): void {
        $source = file_get_contents(self::srcDir() . '/classes/external/base.php');
        $this->assertStringContainsString('namespace mod_skilland\\external;', $source);
        $this->assertStringContainsString('use core_external\\external_api;', $source);
        $this->assertMatchesRegularExpression('/abstract class base extends external_api \{/', $source);
        $this->assertTrue(is_subclass_of(\mod_skilland\external\base::class, \core_external\external_api::class));
    }

    public function test_every_execute_validates_context_before_first_capability_check(): void {
        foreach (self::serviceFiles() as $file) {
            $name = basename($file, '.php');
            $source = file_get_contents($file);
            $pattern = '/public static function execute\(.*?(?=\n    (?:public|private|protected) static function |\z)/s';
            $this->assertSame(1, preg_match($pattern, $source, $m), $name);
            $body = $m[0];
            $validate = strpos($body, 'self::validate_context(');
            $capability = strpos($body, 'require_capability(');
            $this->assertNotFalse($validate, "$name does not call self::validate_context()");
            $this->assertNotFalse($capability, "$name does not call require_capability()");
            $this->assertLessThan($capability, $validate, "$name checks a capability before validate_context()");
            $try = strpos($body, 'try {');
            if ($try !== false) {
                $this->assertLessThan($try, $capability, "$name checks the capability inside the try");
            }
        }
    }

    public function test_no_legacy_external_api_left_in_src(): void {
        $this->assertFileDoesNotExist(self::srcDir() . '/classes/external.php');
        $srcdir = self::srcDir();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcdir, \FilesystemIterator::SKIP_DOTS));
        $hits = [];
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (strpos($content, 'externallib.php') !== false || strpos($content, 'mod_skilland_external') !== false) {
                $hits[] = substr($file->getPathname(), strlen($srcdir) + 1);
            }
        }
        $this->assertSame([], $hits);
    }

    public function test_services_point_at_the_external_classes(): void {
        $functions = [];
        require self::srcDir() . '/db/services.php';

        $this->assertSame(array_keys(self::SERVICES), array_keys($functions));
        foreach ($functions as $name => $function) {
            $this->assertArrayNotHasKey('classpath', $function, $name);
            $this->assertSame('mod_skilland\\external\\' . self::SERVICES[$name], $function['classname'], $name);
            $this->assertSame('execute', $function['methodname'], $name);
            $this->assertTrue(class_exists($function['classname']), "$name classname does not resolve");
            $this->assertTrue(method_exists($function['classname'], 'execute'), $name);
            $this->assertTrue($function['ajax'], $name);
            $this->assertTrue($function['loginrequired'], $name);
        }
    }
}
