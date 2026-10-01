<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-664: the course-mapping field on the course settings page (source scan of classes/hooks.php
 * and the mod_skilland/course_mapping AMD module it loads, SKL-681), the pending Studio path kept
 * in the session, and create_course storing it instead of minting a token.
 */
class course_mapping_field_test extends TestCase {

    private const COURSE_ID = 10;

    private const GLOBALS_TO_RESET = [
        '_test_validated_contexts', '_test_call_order', '_test_denied_capabilities', '_test_capability_course_ids',
        '_test_curl_response', '_test_curl_responses', '_test_curl_requests', '_test_curl_last',
        '_test_customfield_value', '_test_customfield_saved', '_test_get_course',
    ];

    private static function hooks_source(): string {
        return file_get_contents(__DIR__ . '/../../src/classes/hooks.php');
    }

    private static function module_source(): string {
        return file_get_contents(__DIR__ . '/../../src/amd/src/course_mapping.js');
    }

    /**
     * Returns the body of a JS function declaration, up to the line that closes it at the
     * declaration's indentation.
     */
    private function js_function_body(string $source, string $name): string {
        $pattern = '/^([ \t]*)function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{\n(.*?)^\1\}/ms';
        $this->assertSame(1, preg_match($pattern, $source, $m), "JS function $name not found");
        return $m[2];
    }

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['SESSION'] = new \stdClass();
        $db = new \FakeDatabase();
        $db->seed('modules', [(object) ['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $GLOBALS['DB'] = $db;
        $GLOBALS['USER'] = (object) ['id' => 2, 'email' => 'teacher@example.com', 'firstname' => 'T',
            'lastname' => 'Eacher'];
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ]];
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['SESSION'] = new \stdClass();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // hooks.php + amd/src/course_mapping.js source scan
    // ---------------------------------------------------------------

    public function test_no_create_option_in_the_dropdown(): void {
        $this->assertStringNotContainsString('__create_new__', self::hooks_source());
        $this->assertStringNotContainsString('__create_new__', self::module_source());
    }

    public function test_field_is_not_found_by_english_label_or_description(): void {
        foreach ([self::hooks_source(), self::module_source()] as $source) {
            foreach (['Skilland Course ID', 'The Skilland Course ID associated', 'used to link all Skilland activities'] as $text) {
                $this->assertStringNotContainsString($text, $source);
            }
        }
    }

    public function test_field_is_found_by_name_once_for_both_controls(): void {
        $source = self::module_source();
        // One lookup by name feeds both the dropdown and the "Go to SkilLand" button.
        $this->assertSame(1, substr_count($source, 'document.querySelector(\'[name="customfield_skilland_course_id"]\')'));
        $this->assertStringNotContainsString('input[id*=', $source);
        $this->assertStringNotContainsString('input[id*=', self::hooks_source());
        $this->assertStringContainsString("closest('.fitem')", $source);
        $start = $this->js_function_body($source, 'start');
        $this->assertStringContainsString('insertGoToSkillandButton(fieldInput,', $start);
        $this->assertStringContainsString('buildMappingField(fieldInput,', $start);
    }

    public function test_only_the_select_submits_the_value(): void {
        $source = self::module_source();
        foreach ([$source, self::hooks_source()] as $scanned) {
            $this->assertDoesNotMatchRegularExpression("/type\s*=\s*'hidden'/", $scanned);
            $this->assertStringNotContainsString('hiddenInput', $scanned);
        }
        $this->assertSame(1, preg_match_all('/\.name\s*=(?!=)/', $source), 'exactly one element gets a name');
        $this->assertSame(1, substr_count($source, 'select.name = fieldInput.name;'));
        $this->assertStringContainsString('fieldInput.disabled = true;', $source);
        $this->assertStringContainsString("fieldInput.id = originalId + '_raw';", $source);
        $this->assertStringContainsString('select.id = originalId;', $source);
    }

    public function test_no_submit_listener_and_no_window_open(): void {
        foreach ([self::hooks_source(), self::module_source()] as $source) {
            $this->assertStringNotContainsString("addEventListener('submit'", $source);
            $this->assertStringNotContainsString('window.open', $source);
            $this->assertStringNotContainsString('pendingRedirectUrl', $source);
        }
    }

    public function test_create_goes_through_the_button_and_a_confirmation(): void {
        $source = self::module_source();
        $this->assertStringContainsString("createBtn.id = 'skilland-create-course-btn';", $source);
        $this->assertStringContainsString("createBtn.type = 'button';", $source);
        $this->assertStringContainsString("createBtn.className = 'btn btn-secondary", $source);
        $this->assertStringContainsString("createBtn.addEventListener('click', onCreateClick);", $source);
        $this->assertStringNotContainsString("addEventListener('change'", $source);

        $this->assertSame(1, substr_count($source, 'saveCancelPromise'));
        $this->assertSame(1, substr_count($source, 'mod_skilland_create_course_ajax'));
        $this->assertStringNotContainsString('saveCancelPromise', self::hooks_source());
        $this->assertStringNotContainsString('mod_skilland_create_course_ajax', self::hooks_source());
        $click = $this->js_function_body($source, 'onCreateClick');
        $this->assertStringContainsString('saveCancelPromise', $click);
        $this->assertStringContainsString('mod_skilland_create_course_ajax', $click);
        // The AJAX call sits inside the confirmation's resolve handler.
        $this->assertLessThan(strpos($click, 'mod_skilland_create_course_ajax'), strpos($click, 'saveCancelPromise'));
        $this->assertMatchesRegularExpression('/saveCancelPromise\([^;]*?\)\.then\(function\(\)\s*\{/s', $click);
        // The course name is escaped before it goes into the dialog body.
        $this->assertStringContainsString('escapeHtml(courseName)', $click);
    }

    public function test_create_button_is_built_only_after_the_strings_resolve(): void {
        $source = self::module_source();
        $start = $this->js_function_body($source, 'start');
        $this->assertMatchesRegularExpression(
            '/StringLoader\.loadStrings\(STRINGS\)\.then\(function\(strings\)\s*\{[^}]*buildMappingField\(fieldInput, config, strings\);/s',
            $start
        );
        $this->assertStringContainsString('.catch(Notification.exception);', $start);
        // Enabled only once the course list has loaded.
        $this->assertStringContainsString('createBtn.disabled = true;', $source);
        $this->assertStringContainsString('createBtn.disabled = false;', $source);
    }

    public function test_stale_mapping_shows_unknown_option_and_warning(): void {
        $source = self::module_source();
        $this->assertMatchesRegularExpression("/key:\s*'course_unknown',\s*param:\s*idPlaceholder\b/", $source);
        $this->assertStringContainsString('strings.courseUnknown.split(idPlaceholder).join(currentValue);', $source);
        $this->assertStringContainsString('unknown.value = currentValue;', $source);
        $this->assertStringContainsString('unknown.selected = true;', $source);
        $this->assertStringContainsString("'alert alert-warning", $source);
        $this->assertStringContainsString("warning.setAttribute('role', 'status');", $source);
        $this->assertStringContainsString('warning.textContent = strings.courseUnknownWarning;', $source);
    }

    public function test_fetch_failure_restores_the_text_input(): void {
        $body = $this->js_function_body(self::module_source(), 'restoreTextInput');
        $this->assertStringContainsString('container.parentNode.removeChild(container);', $body);
        $this->assertStringContainsString('fieldInput.id = originalId;', $body);
        $this->assertStringContainsString('fieldInput.disabled = false;', $body);
        $this->assertSame(2, substr_count(self::module_source(), 'restoreTextInput();'));
    }

    public function test_go_to_button_carries_the_course_id(): void {
        $this->assertMatchesRegularExpression(
            "#new \\\\moodle_url\('/mod/skilland/sso_redirect\.php', \[\s*'courseid' => \\\$courseid,\s*'sesskey' => sesskey\(\),#",
            self::hooks_source()
        );
        $this->assertStringContainsString("'ssourl' => \$ssourl->out(false),", self::hooks_source());
        $body = $this->js_function_body(self::module_source(), 'insertGoToSkillandButton');
        $this->assertStringContainsString('link.href = ssoUrl;', $body);
        $this->assertStringContainsString("div.id = 'skilland-goto-btn';", $body);
        $this->assertStringNotContainsString('insertEdukamButton', self::module_source());
    }

    public function test_hooks_loads_the_course_mapping_module(): void {
        $hooks = self::hooks_source();
        $this->assertSame(1, substr_count($hooks, "\$PAGE->requires->js_call_amd('mod_skilland/course_mapping', 'init', [["));
        foreach (['<script', 'js_amd_inline(', 'js_init_code(', 'json_encode('] as $needle) {
            $this->assertStringNotContainsString($needle, $hooks);
        }
        foreach (['courseid', 'coursename', 'linked', 'ssourl', 'debug'] as $key) {
            $this->assertMatchesRegularExpression("/'$key' => /", $hooks, "init config lacks $key");
        }
        $this->assertFileDoesNotExist(__DIR__ . '/../../src/amd/src/course_mapping_field.js');
        $this->assertFileDoesNotExist(__DIR__ . '/../../src/amd/build/course_mapping_field.min.js');
        $this->assertFileExists(__DIR__ . '/../../src/amd/build/course_mapping.min.js');
    }

    public function test_new_strings_reach_js_through_core_str(): void {
        $source = self::module_source();
        $hooks = self::hooks_source();
        $loader = file_get_contents(__DIR__ . '/../../src/amd/src/local/string_loader.js');
        $this->assertStringContainsString('mod_skilland/local/string_loader', $source);
        $this->assertStringContainsString('Str.get_strings(', $loader);
        foreach (['create_course_confirm_title', 'create_course_confirm_body', 'create_course_confirm_replace',
                'create_course_confirm_yes', 'course_unknown', 'course_unknown_warning'] as $key) {
            $this->assertMatchesRegularExpression("/key:\s*'$key'/", $source, "$key is not fetched through core/str");
            $this->assertStringNotContainsString("get_string('$key'", $hooks, "$key is still rendered in PHP");
        }
    }

    // ---------------------------------------------------------------
    // Pending Studio path helpers
    // ---------------------------------------------------------------

    public function test_take_returns_and_clears_the_pending_path(): void {
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills/new?draft=skill-1');

        $this->assertSame('/skills/new?draft=skill-1', mod_skilland_take_pending_studio_path(self::COURSE_ID));
        $this->assertNull(mod_skilland_take_pending_studio_path(self::COURSE_ID));
    }

    public function test_pending_paths_are_kept_per_course(): void {
        mod_skilland_set_pending_studio_path(10, '/skills/new?draft=a');
        mod_skilland_set_pending_studio_path(11, '/skills/new?draft=b');

        $this->assertNull(mod_skilland_take_pending_studio_path(12));
        $this->assertSame('/skills/new?draft=b', mod_skilland_take_pending_studio_path(11));
        $this->assertSame('/skills/new?draft=a', mod_skilland_peek_pending_studio_path(10));
        $this->assertSame('/skills/new?draft=a', mod_skilland_take_pending_studio_path(10));
    }

    public function test_peek_does_not_consume(): void {
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills/x');

        $this->assertSame('/skills/x', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
        $this->assertSame('/skills/x', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
    }

    /**
     * @dataProvider rejected_studio_paths
     */
    public function test_a_path_outside_skills_studio_is_rejected(string $path): void {
        try {
            mod_skilland_set_pending_studio_path(self::COURSE_ID, $path);
            $this->fail('Path must be rejected: ' . $path);
        } catch (\coding_exception $e) {
            $this->assertNull(mod_skilland_peek_pending_studio_path(self::COURSE_ID));
        }

        // A value planted in the session some other way is not handed out either.
        $GLOBALS['SESSION']->mod_skilland_pending_studio = [self::COURSE_ID => '/admin/'];
        $this->assertNull(mod_skilland_take_pending_studio_path(self::COURSE_ID));
    }

    public static function rejected_studio_paths(): array {
        return [
            'absolute url' => ['https://evil.example/skills/x'],
            'protocol-relative' => ['//evil.example/skills/x'],
            'double slash inside' => ['/skills//evil.example'],
            'old studio path' => ['/skills-studio/create/step/x'],
            'parent segment' => ['/skills/../admin'],
            'other page' => ['/admin/'],
            'other query' => ['/skills/x?next=https://evil.example'],
            'fragment' => ['/skills/x#y'],
            'empty draft' => ['/skills/new?draft='],
            'backslash' => ['/skills/\\evil.example'],
            'trailing newline' => ["/skills/x\n"],
        ];
    }

    /**
     * @dataProvider accepted_studio_paths
     */
    public function test_native_studio_paths_are_accepted(string $path): void {
        $this->assertTrue(mod_skilland_is_studio_path($path));
    }

    public static function accepted_studio_paths(): array {
        return [
            'skills list' => ['/skills'],
            'skill' => ['/skills/3f2a9c1e-0000-4000-8000-000000000001'],
            'topic' => ['/skills/skill-1?topic=topic-1'],
            'new course' => ['/skills/new?draft=skill-1'],
            'encoded id' => ['/skills/a%20b'],
        ];
    }

    // ---------------------------------------------------------------
    // create_course::execute
    // ---------------------------------------------------------------

    public function test_create_course_stores_the_pending_path_and_returns_no_redirect_url(): void {
        $GLOBALS['_test_customfield_value'] = [self::COURSE_ID => ''];
        $GLOBALS['_test_curl_response'] = ['body' => json_encode([
            'id' => 'skill-new', 'path' => '/skills/new?draft=skill-new', 'name' => 'Test Course',
        ]), 'http_code' => 201, 'errno' => 0, 'error' => ''];

        $result = \mod_skilland\external\create_course::execute(self::COURSE_ID);

        $this->assertNull($result['error']);
        $this->assertSame('skill-new', $result['skillid']);
        $this->assertSame('Test Course', $result['name']);
        $this->assertSame('', $result['redirect_url']);
        $this->assertSame(['skill-new'], $GLOBALS['_test_customfield_saved'] ?? []);
        $this->assertSame('/skills/new?draft=skill-new', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
        $sent = json_decode($GLOBALS['_test_curl_last']['body'], true);
        $this->assertSame((string) $GLOBALS['USER']->id, $sent['moodleUserId']);
        $this->assertSame($GLOBALS['CFG']->wwwroot, $sent['issuer']);
    }

    public function test_create_course_does_not_mint_an_sso_token(): void {
        $source = file_get_contents(__DIR__ . '/../../src/classes/external/create_course.php');
        $this->assertStringNotContainsString('skilland_generate_sso_token', $source);
        $this->assertStringNotContainsString('skilland_get_sso_url', $source);
        $this->assertStringContainsString('mod_skilland_set_pending_studio_path(', $source);
    }
}
