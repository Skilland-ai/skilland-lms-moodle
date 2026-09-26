<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-664: the course-mapping field on the course settings page (source scan of classes/hooks.php),
 * the pending Studio path kept in the session, and create_course storing it instead of minting a token.
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

    /**
     * Returns the body of an inline JS function emitted from PHP, up to the line that closes it
     * at the declaration's indentation.
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
    // hooks.php source scan
    // ---------------------------------------------------------------

    public function test_no_create_option_in_the_dropdown(): void {
        $this->assertStringNotContainsString('__create_new__', self::hooks_source());
    }

    public function test_field_is_not_found_by_english_label_or_description(): void {
        $source = self::hooks_source();
        foreach (['Skilland Course ID', 'The Skilland Course ID associated', 'used to link all Skilland activities'] as $text) {
            $this->assertStringNotContainsString($text, $source);
        }
    }

    public function test_field_is_found_by_name_in_both_scripts(): void {
        $source = self::hooks_source();
        $this->assertSame(2, substr_count($source, 'document.querySelector(\'[name=\"customfield_skilland_course_id\"]\')'));
        $this->assertStringNotContainsString('input[id*=', $source);
        $this->assertStringContainsString("closest('.fitem')", $source);
    }

    public function test_only_the_select_submits_the_value(): void {
        $source = self::hooks_source();
        $this->assertDoesNotMatchRegularExpression("/type\s*=\s*'hidden'/", $source);
        $this->assertStringNotContainsString('hiddenInput', $source);
        $this->assertSame(1, preg_match_all('/\.name\s*=(?!=)/', $source), 'exactly one element gets a name');
        $this->assertSame(1, substr_count($source, 'select.name = fieldInput.name;'));
        $this->assertStringContainsString('fieldInput.disabled = true;', $source);
        $this->assertStringContainsString("fieldInput.id = originalId + '_raw';", $source);
        $this->assertStringContainsString('select.id = originalId;', $source);
    }

    public function test_no_submit_listener_and_no_window_open(): void {
        $source = self::hooks_source();
        $this->assertStringNotContainsString("addEventListener('submit'", $source);
        $this->assertStringNotContainsString('window.open', $source);
        $this->assertStringNotContainsString('pendingRedirectUrl', $source);
    }

    public function test_create_goes_through_the_button_and_a_confirmation(): void {
        $source = self::hooks_source();
        $this->assertStringContainsString("createBtn.id = 'skilland-create-course-btn';", $source);
        $this->assertStringContainsString("createBtn.type = 'button';", $source);
        $this->assertStringContainsString("createBtn.className = 'btn btn-secondary", $source);
        $this->assertStringContainsString("createBtn.addEventListener('click', onCreateClick);", $source);
        $this->assertStringNotContainsString("addEventListener('change'", $source);

        $this->assertSame(1, substr_count($source, 'saveCancelPromise'));
        $this->assertSame(1, substr_count($source, 'mod_skilland_create_course_ajax'));
        $click = $this->js_function_body($source, 'onCreateClick');
        $this->assertStringContainsString('saveCancelPromise', $click);
        $this->assertStringContainsString('mod_skilland_create_course_ajax', $click);
        // The AJAX call sits inside the confirmation's resolve handler.
        $this->assertLessThan(strpos($click, 'mod_skilland_create_course_ajax'), strpos($click, 'saveCancelPromise'));
        $this->assertMatchesRegularExpression('/saveCancelPromise\([^;]*?\)\.then\(function\(\)\s*\{/s', $click);
        // The course name is escaped before it goes into the dialog body.
        $this->assertStringContainsString('escapeHtml(courseName)', $click);
    }

    public function test_stale_mapping_shows_unknown_option_and_warning(): void {
        $source = self::hooks_source();
        $this->assertStringContainsString("json_encode(\\get_string('course_unknown', 'mod_skilland'", $source);
        $this->assertStringContainsString('unknown.value = currentValue;', $source);
        $this->assertStringContainsString('unknown.selected = true;', $source);
        $this->assertStringContainsString("'alert alert-warning", $source);
        $this->assertStringContainsString("warning.setAttribute('role', 'status');", $source);
        $this->assertStringContainsString('warning.textContent = strings.courseUnknownWarning;', $source);
    }

    public function test_fetch_failure_restores_the_text_input(): void {
        $body = $this->js_function_body(self::hooks_source(), 'restoreTextInput');
        $this->assertStringContainsString('container.parentNode.removeChild(container);', $body);
        $this->assertStringContainsString('fieldInput.id = originalId;', $body);
        $this->assertStringContainsString('fieldInput.disabled = false;', $body);
        $this->assertSame(2, substr_count(self::hooks_source(), 'restoreTextInput();'));
    }

    public function test_go_to_button_carries_the_course_id(): void {
        $this->assertMatchesRegularExpression(
            "#new \\\\moodle_url\('/mod/skilland/sso_redirect\.php', \[\s*'courseid' => \\\$courseid,\s*'sesskey' => sesskey\(\),#",
            self::hooks_source()
        );
    }

    public function test_new_strings_reach_js_as_hex_escaped_json(): void {
        $source = self::hooks_source();
        foreach (['create_course_confirm_title', 'create_course_confirm_body', 'create_course_confirm_replace',
                'create_course_confirm_yes', 'course_unknown', 'course_unknown_warning'] as $key) {
            $this->assertMatchesRegularExpression("/json_encode\(\\\\get_string\('$key', 'mod_skilland'/", $source,
                "$key is not emitted through json_encode");
        }
    }

    // ---------------------------------------------------------------
    // Pending Studio path helpers
    // ---------------------------------------------------------------

    public function test_take_returns_and_clears_the_pending_path(): void {
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills-studio/create/step/skill-1');

        $this->assertSame('/skills-studio/create/step/skill-1', mod_skilland_take_pending_studio_path(self::COURSE_ID));
        $this->assertNull(mod_skilland_take_pending_studio_path(self::COURSE_ID));
    }

    public function test_pending_paths_are_kept_per_course(): void {
        mod_skilland_set_pending_studio_path(10, '/skills-studio/create/step/a');
        mod_skilland_set_pending_studio_path(11, '/skills-studio/create/step/b');

        $this->assertNull(mod_skilland_take_pending_studio_path(12));
        $this->assertSame('/skills-studio/create/step/b', mod_skilland_take_pending_studio_path(11));
        $this->assertSame('/skills-studio/create/step/a', mod_skilland_peek_pending_studio_path(10));
        $this->assertSame('/skills-studio/create/step/a', mod_skilland_take_pending_studio_path(10));
    }

    public function test_peek_does_not_consume(): void {
        mod_skilland_set_pending_studio_path(self::COURSE_ID, '/skills-studio/create/step/x');

        $this->assertSame('/skills-studio/create/step/x', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
        $this->assertSame('/skills-studio/create/step/x', mod_skilland_peek_pending_studio_path(self::COURSE_ID));
    }

    public function test_a_path_outside_skills_studio_is_rejected(): void {
        try {
            mod_skilland_set_pending_studio_path(self::COURSE_ID, 'https://evil.example/skills-studio/x');
            $this->fail('An absolute URL must be rejected');
        } catch (\coding_exception $e) {
            $this->assertNull(mod_skilland_peek_pending_studio_path(self::COURSE_ID));
        }

        // A value planted in the session some other way is not handed out either.
        $GLOBALS['SESSION']->mod_skilland_pending_studio = [self::COURSE_ID => '/admin/'];
        $this->assertNull(mod_skilland_take_pending_studio_path(self::COURSE_ID));
    }

    // ---------------------------------------------------------------
    // create_course::execute
    // ---------------------------------------------------------------

    public function test_create_course_stores_the_pending_path_and_returns_no_redirect_url(): void {
        $GLOBALS['_test_customfield_value'] = [self::COURSE_ID => ''];
        $GLOBALS['_test_curl_response'] = ['body' => json_encode(['data' => ['createSkillFromMoodle' => [
            'id' => 'skill-new', 'name' => 'Test Course', 'status' => 'DRAFT', 'creationStep' => 'microcredential-upload',
        ]]]), 'http_code' => 200, 'errno' => 0, 'error' => ''];

        $result = \mod_skilland\external\create_course::execute(self::COURSE_ID);

        $this->assertNull($result['error']);
        $this->assertSame('skill-new', $result['skillid']);
        $this->assertSame('Test Course', $result['name']);
        $this->assertSame('', $result['redirect_url']);
        $this->assertSame(['skill-new'], $GLOBALS['_test_customfield_saved'] ?? []);
        $this->assertSame('/skills-studio/create/microcredential-upload/skill-new',
            mod_skilland_peek_pending_studio_path(self::COURSE_ID));
    }

    public function test_create_course_does_not_mint_an_sso_token(): void {
        $source = file_get_contents(__DIR__ . '/../../src/classes/external/create_course.php');
        $this->assertStringNotContainsString('skilland_generate_sso_token', $source);
        $this->assertStringNotContainsString('skilland_get_sso_url', $source);
        $this->assertStringContainsString('mod_skilland_set_pending_studio_path(', $source);
    }
}
