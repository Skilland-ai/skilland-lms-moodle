<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Guards the plugin's capability model (SKL-685): which capability each entry point
 * requires, and how the plugin-specific capabilities are declared in db/access.php.
 * view.php and the web services are scripts/closures that cannot run standalone, so
 * their wiring is checked on the source text.
 */
class capabilities_test extends TestCase {

    private static string $srcDir;
    private static array $capabilities = [];

    public static function setUpBeforeClass(): void {
        self::$srcDir = realpath(__DIR__ . '/../../src');

        foreach ([
            'RISK_XSS' => 0x0004, 'RISK_SPAM' => 0x0010,
            'CONTEXT_COURSE' => 50, 'CONTEXT_MODULE' => 70,
            'CAP_ALLOW' => 1,
        ] as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }

        $capabilities = [];
        require self::$srcDir . '/db/access.php';
        self::$capabilities = $capabilities;
    }

    private function src(string $rel): string {
        return file_get_contents(self::$srcDir . '/' . $rel);
    }

    /**
     * Body of a public static method in external.php, up to the next method.
     */
    private function externalMethodBody(string $name): string {
        $source = $this->src('classes/external.php');
        $pattern = '/public static function ' . preg_quote($name, '/') . '\(.*?(?=\n    (?:public|private|protected) static function |\z)/s';
        $this->assertSame(1, preg_match($pattern, $source, $m), "method $name not found in external.php");
        return $m[0];
    }

    // ---------------------------------------------------------------
    // db/access.php
    // ---------------------------------------------------------------

    public function test_submit_capability_is_removed(): void {
        $this->assertArrayNotHasKey('mod/skilland:submit', self::$capabilities);
        $this->assertStringNotContainsString('mod/skilland:submit', $this->src('db/access.php'));
    }

    public function test_view_capability_is_declared_for_learners(): void {
        $cap = self::$capabilities['mod/skilland:view'];
        $this->assertSame('read', $cap['captype']);
        $this->assertSame(CONTEXT_MODULE, $cap['contextlevel']);
        $this->assertSame(CAP_ALLOW, $cap['archetypes']['student']);
    }

    public function test_provision_capability_clones_manageactivities_at_module_level(): void {
        $cap = self::$capabilities['mod/skilland:provision'];
        $this->assertSame('write', $cap['captype']);
        $this->assertSame(CONTEXT_MODULE, $cap['contextlevel']);
        $this->assertSame(RISK_XSS, $cap['riskbitmask']);
        $this->assertSame('moodle/course:manageactivities', $cap['clonepermissionsfrom']);
        $this->assertSame(['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW], $cap['archetypes']);
    }

    public function test_accessstudio_capability_clones_course_update_at_course_level(): void {
        $cap = self::$capabilities['mod/skilland:accessstudio'];
        $this->assertSame('write', $cap['captype']);
        $this->assertSame(CONTEXT_COURSE, $cap['contextlevel']);
        $this->assertSame('moodle/course:update', $cap['clonepermissionsfrom']);
        $this->assertSame(['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW], $cap['archetypes']);
    }

    // ---------------------------------------------------------------
    // view.php
    // ---------------------------------------------------------------

    public function test_view_requires_view_capability_and_logs_view_before_play_branch(): void {
        $source = $this->src('view.php');
        $login = strpos($source, 'require_login($course, true, $cm);');
        $require = strpos($source, "require_capability('mod/skilland:view', \$context);");
        $view = strpos($source, 'skilland_view($skilland, $course, $cm, $context);');
        $play = strpos($source, 'if ($play > 0)');

        $this->assertNotFalse($login);
        $this->assertNotFalse($require);
        $this->assertNotFalse($view);
        $this->assertNotFalse($play);
        $this->assertGreaterThan($login, $require);
        $this->assertGreaterThan($require, $view);
        $this->assertLessThan($play, $view);
    }

    public function test_view_gates_provisioning_on_provision_capability(): void {
        $source = $this->src('view.php');
        $this->assertStringContainsString("\$canprovision = has_capability('mod/skilland:provision', \$context);", $source);
        $this->assertStringContainsString("\$canmanage = has_capability('mod/skilland:provision', \$context);", $source);
    }

    public function test_view_and_external_no_longer_use_manageactivities(): void {
        $this->assertStringNotContainsString('moodle/course:manageactivities', $this->src('view.php'));
        $this->assertStringNotContainsString('moodle/course:manageactivities', $this->src('classes/external.php'));
    }

    // ---------------------------------------------------------------
    // Web services, SSO and navigation
    // ---------------------------------------------------------------

    public static function external_capability_provider(): array {
        return [
            'fetch_courses_ajax' => ['fetch_courses_ajax', 'mod/skilland:accessstudio'],
            'create_course_ajax' => ['create_course_ajax', 'mod/skilland:accessstudio'],
            'fetch_topics_ajax' => ['fetch_topics_ajax', 'moodle/course:update'],
            'fetch_lessons_ajax' => ['fetch_lessons_ajax', 'moodle/course:update'],
            'provision_topic_scorm_ajax' => ['provision_topic_scorm_ajax', 'mod/skilland:provision'],
            'update_topic_scorm_ajax' => ['update_topic_scorm_ajax', 'mod/skilland:provision'],
            'check_topic_snapshot' => ['check_topic_snapshot', 'mod/skilland:provision'],
        ];
    }

    /**
     * @dataProvider external_capability_provider
     */
    public function test_external_function_requires_expected_capability(string $method, string $capability): void {
        $body = $this->externalMethodBody($method);
        preg_match_all("/require_capability\\('([^']+)'/", $body, $m);
        $this->assertSame([$capability], $m[1], "$method must require exactly $capability");
    }

    public function test_sso_redirect_requires_accessstudio(): void {
        $source = $this->src('sso_redirect.php');
        $this->assertStringContainsString("require_capability('mod/skilland:accessstudio', \$context);", $source);
        $this->assertStringNotContainsString('mod/skilland:addinstance', $source);
    }

    public function test_course_navigation_requires_accessstudio(): void {
        $source = $this->src('lib.php');
        $this->assertSame(1, preg_match('/function mod_skilland_extend_navigation_course\(.*?\n}\n/s', $source, $m));
        $this->assertStringContainsString("has_capability('mod/skilland:accessstudio', \$context)", $m[0]);
        $this->assertStringNotContainsString('mod/skilland:addinstance', $m[0]);
    }

    // ---------------------------------------------------------------
    // Viewed event
    // ---------------------------------------------------------------

    public function test_viewed_event_class_targets_skilland_table(): void {
        $source = $this->src('classes/event/course_module_viewed.php');
        $this->assertStringContainsString('namespace mod_skilland\\event;', $source);
        $this->assertStringContainsString('extends \\core\\event\\course_module_viewed', $source);
        $this->assertStringContainsString("\$this->data['objecttable'] = 'skilland';", $source);
    }
}
