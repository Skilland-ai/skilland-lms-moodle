<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-664: after the course settings form saves, a SkilLand course created from it is offered
 * through one info notification; the pending path is only consumed by sso_redirect.php.
 */
class course_updated_observer_test extends TestCase {

    private const COURSE_ID = 10;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['SESSION'] = new \stdClass();
        $GLOBALS['_test_notifications'] = [];
        $GLOBALS['_test_sesskey'] = 'abc123';
        $this->setEnabled(true);
    }

    protected function tearDown(): void {
        $GLOBALS['SESSION'] = new \stdClass();
        unset($GLOBALS['_test_notifications'], $GLOBALS['_test_sesskey']);
        parent::tearDown();
    }

    private function setEnabled(bool $enabled): void {
        $db = new \FakeDatabase();
        $db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => $enabled ? 1 : 0]]);
        $GLOBALS['DB'] = $db;
    }

    private function event(int $courseid = self::COURSE_ID): \core\event\course_updated {
        return \core\event\course_updated::create(['objectid' => $courseid, 'courseid' => $courseid]);
    }

    public function test_pending_path_queues_one_info_notification_and_stays_pending(): void {
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills/new?draft=skill-1');

        \mod_skilland\observer::course_updated($this->event());

        $this->assertCount(1, $GLOBALS['_test_notifications']);
        $notification = $GLOBALS['_test_notifications'][0];
        $this->assertSame(\core\notification::INFO, $notification['type']);
        $this->assertStringContainsString('open_new_course_in_skilland', $notification['message']);
        $this->assertStringContainsString('/mod/skilland/sso_redirect.php?', $notification['message']);
        $this->assertStringContainsString('courseid=' . self::COURSE_ID, $notification['message']);
        $this->assertStringContainsString('pending=1', $notification['message']);
        $this->assertStringContainsString('sesskey=abc123', $notification['message']);
        $this->assertStringNotContainsString('token=', $notification['message']);

        $this->assertSame('/skills/new?draft=skill-1', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
    }

    public function test_no_pending_path_no_notification(): void {
        mod_skilland_set_pending_studio_path(11, '/skills/new?draft=other');

        \mod_skilland\observer::course_updated($this->event());

        $this->assertSame([], $GLOBALS['_test_notifications']);
    }

    public function test_plugin_disabled_does_nothing(): void {
        $this->setEnabled(false);
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills/new?draft=skill-1');

        \mod_skilland\observer::course_updated($this->event());

        $this->assertSame([], $GLOBALS['_test_notifications']);
        $this->assertSame('/skills/new?draft=skill-1', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
    }

    public function test_sso_redirect_consumes_the_pending_path_only_with_pending(): void {
        $source = file_get_contents(__DIR__ . '/../../src/sso_redirect.php');
        $this->assertStringContainsString("optional_param('pending', false, PARAM_BOOL)", $source);
        $this->assertMatchesRegularExpression(
            '/if \(\$pending\) \{\s*\$pendingpath = mod_skilland_take_pending_studio_path\(\$courseid\);/',
            $source
        );
        // The capability check runs before the pending path is read.
        $this->assertLessThan(strpos($source, 'mod_skilland_take_pending_studio_path'),
            strpos($source, "require_capability('mod/skilland:accessstudio'"));
    }
}
