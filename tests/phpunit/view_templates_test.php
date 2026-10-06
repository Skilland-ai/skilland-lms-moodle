<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * view.php renders through Mustache templates and the plugin renderer (SKL-681): the
 * templates are documented Moodle templates, and the provision prompt and the fullscreen
 * player keep the ids, classes, ARIA and AMD calls their scripts, CSS and Behat rely on.
 */
class view_templates_test extends TestCase {

    private const TEMPLATES = ['lesson_list', 'lesson_card', 'player', 'fullscreen_navigation', 'provision'];

    /** @var string|null The dirroot restored after a test that fakes mod/scorm. */
    private $originaldirroot;

    /** @var string|null A temporary dirroot holding a fake mod/scorm/locallib.php. */
    private $fakedirroot;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['DB'] = new \FakeDatabase();
        $GLOBALS['USER'] = (object) ['id' => 1];
        $GLOBALS['PAGE'] = new \test_moodle_page();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_get_coursemodule_from_id'], $GLOBALS['_test_cm_from_db']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        if ($this->originaldirroot !== null) {
            $GLOBALS['CFG']->dirroot = $this->originaldirroot;
        }
        if ($this->fakedirroot !== null) {
            @unlink($this->fakedirroot . '/mod/scorm/locallib.php');
            @rmdir($this->fakedirroot . '/mod/scorm');
            @rmdir($this->fakedirroot . '/mod');
            @rmdir($this->fakedirroot);
        }
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        parent::tearDown();
    }

    private function template(string $name): string {
        $source = file_get_contents(__DIR__ . '/../../src/templates/' . $name . '.mustache');
        $this->assertNotFalse($source, "Template $name is missing");
        return $source;
    }

    public static function templates(): array {
        return array_combine(self::TEMPLATES, array_map(fn($t) => [$t], self::TEMPLATES));
    }

    /**
     * @dataProvider templates
     */
    public function test_template_has_boilerplate_docblock_and_valid_example(string $name): void {
        $source = $this->template($name);

        $this->assertStringStartsWith("{{!\n    This file is part of Moodle - http://moodle.org/", $source);
        $this->assertStringContainsString("@template mod_skilland/$name", $source);
        $this->assertStringContainsString('Context variables required for this template:', $source);
        $this->assertSame(1, preg_match('/Example context \(json\):\s*(\{.*?\n    \})\n\}\}/s', $source, $m),
            "$name has no Example context");
        $this->assertIsArray(json_decode($m[1], true), "$name example context is not valid JSON");
    }

    /**
     * @dataProvider templates
     */
    public function test_template_renders_its_example_context(string $name): void {
        preg_match('/Example context \(json\):\s*(\{.*?\n    \})\n\}\}/s', $this->template($name), $m);
        $renderer = $GLOBALS['PAGE']->get_renderer('mod_skilland');

        $html = $renderer->render_from_template('mod_skilland/' . $name, json_decode($m[1]));

        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('{{', $html);
    }

    public function test_templates_have_no_inline_styles(): void {
        foreach (self::TEMPLATES as $name) {
            $this->assertStringNotContainsString('style=', $this->template($name), $name);
        }
    }

    public function test_view_functions_render_through_the_plugin_renderer(): void {
        $viewsource = file_get_contents(__DIR__ . '/../../src/view.php');
        $this->assertStringNotContainsString('html_writer::start_div', $viewsource);

        // The render helpers view.php calls live in locallib.php (SKL-691).
        $locallibsource = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertStringContainsString("\$PAGE->get_renderer('mod_skilland')", $locallibsource);
        $this->assertInstanceOf(\mod_skilland\output\renderer::class, skilland_view_renderer());
    }

    // ---------------------------------------------------------------
    // Provision prompt
    // ---------------------------------------------------------------

    public function test_provision_view_keeps_ids_aria_and_amd_call(): void {
        $html = skilland_render_provision_view((object) ['id' => 7], (object) ['id' => 42]);

        $this->assertStringContainsString('class="skilland-provision-container text-center py-5" ' .
            'id="skilland-provision-container" data-skillandid="7" data-cmid="42"', $html);
        $this->assertStringContainsString('id="skilland-provision-btn" data-skillandid="7" data-cmid="42"', $html);
        $this->assertStringContainsString('>provision_topic</button>', $html);
        $this->assertStringContainsString('class="skilland-provision-loading d-none" id="skilland-provision-loading" ' .
            'role="status" aria-live="polite"', $html);
        $this->assertStringContainsString('id="skilland-provision-elapsed"', $html);
        $this->assertStringContainsString('id="skilland-provision-error" role="alert"', $html);
        $this->assertStringContainsString('<h4>content_not_provisioned</h4>', $html);
        $this->assertSame([[
            'module' => 'mod_skilland/provision_scorm',
            'function' => 'init',
            'params' => [['skillandid' => 7, 'cmid' => 42, 'debug' => false]],
        ]], $GLOBALS['PAGE']->requires->calls);
    }

    // ---------------------------------------------------------------
    // Fullscreen player
    // ---------------------------------------------------------------

    private function fake_scorm_locallib(): void {
        $this->fakedirroot = sys_get_temp_dir() . '/skl_view_dirroot_' . getmypid();
        @mkdir($this->fakedirroot . '/mod/scorm', 0777, true);
        file_put_contents($this->fakedirroot . '/mod/scorm/locallib.php',
            "<?php\nif (!function_exists('scorm_get_last_attempt')) {\n" .
            "    function scorm_get_last_attempt(\$scormid, \$userid) {\n        return 1;\n    }\n}\n");
        $this->originaldirroot = $GLOBALS['CFG']->dirroot;
        $GLOBALS['CFG']->dirroot = $this->fakedirroot;
    }

    private function render_player(array $lessons, int $current, int $hidelabels = 0): string {
        $this->fake_scorm_locallib();
        $GLOBALS['_test_get_coursemodule_from_id'] = (object) ['id' => 40, 'instance' => 5, 'course' => 3];
        $GLOBALS['DB']->seed('scorm', [(object) ['id' => 5, 'course' => 3]]);
        $skilland = (object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => $hidelabels];

        return skilland_render_player_view($skilland, $lessons[$current], (object) ['id' => 2], $lessons, 1);
    }

    private function lessons(): array {
        return [
            10 => (object) ['id' => 10, 'title' => 'First', 'scoid' => 110],
            11 => (object) ['id' => 11, 'title' => 'Say "hi" & <go>', 'scoid' => 111],
            12 => (object) ['id' => 12, 'title' => 'Third', 'scoid' => 112],
        ];
    }

    public function test_player_keeps_wrapper_header_iframe_and_navigation(): void {
        $html = $this->render_player($this->lessons(), 11);

        $this->assertStringContainsString('class="skilland-fullscreen-wrapper" id="skilland-fullscreen-wrapper" ' .
            'data-fullscreen="true"', $html);
        $this->assertStringContainsString('<a href="/mod/skilland/view.php?id=2" class="skilland-fullscreen-back">← ' .
            'back_to_lessons</a>', $html);
        // The title is format_string() output (HTML), rendered as is like before.
        $this->assertStringContainsString('class="skilland-fullscreen-title" id="skilland-fullscreen-title" ' .
            'tabindex="-1">L1.2 - Say', $html);
        $this->assertStringContainsString('class="skilland-fullscreen-close" title="back_to_lessons" ' .
            'aria-label="back_to_lessons">×</a>', $html);
        $this->assertStringContainsString('<iframe src="/mod/scorm/player.php?scoid=111&amp;cm=40&amp;mode=normal' .
            '&amp;newattempt=off&amp;display=popup" class="skilland-fullscreen-iframe" allowfullscreen="true" ' .
            'allow="fullscreen" title="Say &quot;hi&quot; &amp; &lt;go&gt;"></iframe>', $html);
        $this->assertStringContainsString('<a href="/mod/skilland/view.php?id=2&amp;play=10" ' .
            'class="skilland-fullscreen-nav-prev" aria-label="aria_previous_lesson">', $html);
        $this->assertStringContainsString('<span class="skilland-fullscreen-nav-text">L1.3 - Third</span>' .
            '<span class="skilland-fullscreen-nav-arrow" aria-hidden="true">→</span>', $html);
        $this->assertSame([[
            'module' => 'mod_skilland/fullscreen_player',
            'function' => 'init',
            'params' => [['debug' => false, 'backurl' => '/mod/skilland/view.php?id=2']],
        ]], $GLOBALS['PAGE']->requires->calls);
    }

    public function test_player_title_drops_label_when_hidelabels(): void {
        $html = $this->render_player($this->lessons(), 10, 1);

        $this->assertStringContainsString('tabindex="-1">First</h2>', $html);
        $this->assertDoesNotMatchRegularExpression('/L\d+\.\d+/', $html);
        $this->assertStringContainsString('class="skilland-fullscreen-nav-prev skilland-fullscreen-nav-disabled" ' .
            'aria-disabled="true"><span class="visually-hidden">no_previous_lesson</span></span>', $html);
    }

    public function test_not_ready_player_has_no_amd_call(): void {
        $lesson = (object) ['id' => 10, 'title' => 'Lesson', 'scoid' => null];

        $html = skilland_render_player_view((object) ['id' => 7, 'scormcmid' => 40, 'hidelabels' => 0], $lesson,
            (object) ['id' => 2], [10 => $lesson], 1);

        $this->assertStringContainsString('<div class="alert alert-warning">scorm_not_ready</div>', $html);
        $this->assertStringContainsString('<a href="/mod/skilland/view.php?id=2" class="btn btn-secondary">' .
            'back_to_lessons</a>', $html);
        $this->assertSame([], $GLOBALS['PAGE']->requires->calls);
    }

    // ---------------------------------------------------------------
    // Lesson list escaping
    // ---------------------------------------------------------------

    public function test_lesson_list_escapes_meta_and_keeps_card_markup(): void {
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_scoes_value', false);
        $GLOBALS['DB']->get_manager()->set_table_exists('scorm_attempt', false);
        $GLOBALS['DB']->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => 7, 'lessonid' => 10, 'userid' => 1, 'status' => 'failed',
                'score' => '40'],
        ]);
        $lessons = [10 => (object) ['id' => 10, 'title' => 'First', 'scoid' => 110, 'updatedat' => 0]];

        $html = skilland_render_lesson_list((object) ['id' => 7, 'scormcmid' => 40], $lessons, (object) ['id' => 2]);

        $this->assertStringContainsString('<a href="/mod/skilland/view.php?id=2&amp;play=10" ' .
            'class="skilland-lesson-card skilland-lesson-failed skilland-lesson-target" aria-current="step">', $html);
        $this->assertStringContainsString('<div class="skilland-lesson-number">L1.1</div>', $html);
        $this->assertStringContainsString('<span class="skilland-lesson-title">First</span>', $html);
        $this->assertStringContainsString('<div class="skilland-lesson-meta">score: 40%</div>', $html);
        $this->assertStringContainsString('<i class="fa fa-times-circle skilland-status-icon" aria-hidden="true"></i>',
            $html);
        $this->assertStringContainsString('<span class="skilland-status-text">failed</span>', $html);
        $this->assertStringContainsString('<h3 class="skilland-lessons-header">lessons</h3>', $html);
    }
}
