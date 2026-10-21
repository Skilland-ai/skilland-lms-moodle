<?php

namespace mod_skilland\tests;

use core_external\external_api;
use PHPUnit\Framework\TestCase;

/**
 * SKL-699: mod_skilland_view_skilland, the student-facing view function of the Moodle App, does
 * what view.php does on load (viewed event, view completion) after the module context and
 * mod/skilland:view are checked, and is registered in the official mobile service.
 */
class external_view_skilland_test extends TestCase {

    private const COURSE_ID = 10;
    private const CM_ID = 90;
    private const SKILLAND_ID = 7;

    private const GLOBALS_TO_RESET = [
        '_test_validated_contexts', '_test_call_order', '_test_login_denied_ids', '_test_denied_capabilities',
        '_test_get_coursemodule_from_id', '_test_get_coursemodule_from_instance', '_test_events',
        '_test_completion_viewed', '_test_completion_courses', '_test_cm_from_db',
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
            (object) ['id' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'name' => 'Topic one', 'scormcmid' => 50],
        ]);
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['USER'] = (object) ['id' => 5];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_denied_capabilities'] = [];
        $GLOBALS['_test_validated_contexts'] = [];
        $GLOBALS['_test_call_order'] = [];
        $GLOBALS['_test_events'] = [];
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID,
            'course' => self::COURSE_ID, 'section' => 12];
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    private function viewed_events(): array {
        return array_values(array_filter($GLOBALS['_test_events'] ?? [],
            fn($event) => $event instanceof \mod_skilland\event\course_module_viewed));
    }

    // ---------------------------------------------------------------
    // Behaviour
    // ---------------------------------------------------------------

    public function test_a_viewer_triggers_one_viewed_event_and_the_view_completion(): void {
        $result = \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);

        $this->assertSame(['status' => true, 'warnings' => []], $result);
        $events = $this->viewed_events();
        $this->assertCount(1, $events);
        $this->assertSame(self::SKILLAND_ID, $events[0]->objectid);
        $this->assertSame(self::CM_ID, $events[0]->context->instanceid);
        $this->assertSame('Topic one', $events[0]->get_record_snapshot('skilland', self::SKILLAND_ID)->name);
        $this->assertCount(1, $GLOBALS['_test_completion_viewed']);
        $this->assertSame(self::CM_ID, $GLOBALS['_test_completion_viewed'][0]->id);
        $this->assertSame(self::COURSE_ID, $GLOBALS['_test_completion_courses'][0]->id);
    }

    public function test_validates_the_module_context_before_the_view_capability(): void {
        \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);

        $this->assertCount(1, $GLOBALS['_test_validated_contexts']);
        $this->assertInstanceOf(\context_module::class, $GLOBALS['_test_validated_contexts'][0]);
        $this->assertSame(self::CM_ID, $GLOBALS['_test_validated_contexts'][0]->instanceid);
        $order = $GLOBALS['_test_call_order'];
        $this->assertSame('validate_context', $order[0][0]);
        $this->assertSame(['require_capability', 'mod/skilland:view'], $order[1]);
    }

    public function test_a_user_without_the_view_capability_is_refused_and_nothing_is_logged(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:view'];

        try {
            \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);
            $this->fail('required_capability_exception expected');
        } catch (\required_capability_exception $e) {
            $this->assertSame('mod/skilland:view', $e->a);
        }

        $this->assertSame([], $this->viewed_events());
        $this->assertEmpty($GLOBALS['_test_completion_viewed'] ?? []);
    }

    public function test_a_user_outside_the_course_is_refused_before_the_capability(): void {
        $GLOBALS['_test_login_denied_ids'] = [self::CM_ID];

        try {
            \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);
            $this->fail('require_login_exception expected');
        } catch (\require_login_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }

        $this->assertNotContains(['require_capability', 'mod/skilland:view'], $GLOBALS['_test_call_order']);
        $this->assertSame([], $this->viewed_events());
        $this->assertEmpty($GLOBALS['_test_completion_viewed'] ?? []);
    }

    public function test_a_disabled_plugin_refuses_the_call(): void {
        $this->db->set_field('modules', 'visible', 0, ['name' => 'skilland']);

        $this->expectExceptionObject(new \moodle_exception('error_plugin_disabled', 'mod_skilland'));

        \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);
    }

    public function test_an_unknown_activity_is_refused(): void {
        $this->db->delete_records('skilland', ['id' => self::SKILLAND_ID]);

        $this->expectException(\dml_missing_record_exception::class);

        \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);
    }

    public function test_the_result_passes_response_validation(): void {
        $result = \mod_skilland\external\view_skilland::execute(self::SKILLAND_ID);

        $clean = external_api::clean_returnvalue(\mod_skilland\external\view_skilland::execute_returns(), $result);

        $this->assertSame(['status' => true, 'warnings' => []], $clean);
        $this->assertSame(PARAM_INT, \mod_skilland\external\view_skilland::execute_parameters()->keys['skillandid']->type);
        $this->assertSame(VALUE_REQUIRED, \mod_skilland\external\view_skilland::execute_parameters()->keys['skillandid']->required);
    }

    // ---------------------------------------------------------------
    // Registration: db/services.php and db/mobile.php
    // ---------------------------------------------------------------

    public function test_the_service_is_in_the_official_mobile_service_with_the_view_capability(): void {
        $functions = [];
        require realpath(__DIR__ . '/../../src') . '/db/services.php';

        $function = $functions['mod_skilland_view_skilland'];
        $this->assertSame('mod_skilland\\external\\view_skilland', $function['classname']);
        $this->assertSame('execute', $function['methodname']);
        $this->assertSame('write', $function['type']);
        $this->assertTrue($function['ajax']);
        $this->assertTrue($function['loginrequired']);
        $this->assertSame('mod/skilland:view', $function['capabilities']);
        $this->assertSame([MOODLE_OFFICIAL_MOBILE_SERVICE], $function['services']);

        // The only student-facing function: the teacher-only ones stay out of the mobile service.
        foreach ($functions as $name => $other) {
            if ($name !== 'mod_skilland_view_skilland') {
                $this->assertArrayNotHasKey('services', $other, $name);
            }
        }
    }

    public function test_the_mobile_handler_opens_the_activity_through_the_output_class(): void {
        $CFG = $GLOBALS['CFG'];
        $addons = [];
        require realpath(__DIR__ . '/../../src') . '/db/mobile.php';

        $handler = $addons['mod_skilland']['handlers']['skillandview'];
        $this->assertSame('CoreCourseModuleDelegate', $handler['delegate']);
        $this->assertSame('mobile_course_view', $handler['method']);
        $this->assertSame($CFG->wwwroot . '/mod/skilland/pix/icon.png', $handler['displaydata']['icon']);
        $this->assertTrue($handler['displayrefresh']);
        $this->assertArrayNotHasKey('offlinefunctions', $handler, 'The lesson list is live progress: no offline promise');
        $this->assertTrue(method_exists(\mod_skilland\output\mobile::class, 'mobile_course_view'));
        $this->assertTrue((new \ReflectionMethod(\mod_skilland\output\mobile::class, 'mobile_course_view'))->isStatic());
        $this->assertFileExists(realpath(__DIR__ . '/../../src') . '/pix/icon.png');
    }
}
