<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-657: the activity form keeps the lesson selection per topic, derives
 * selected_lessons from the ticked checkboxes, drops stale lesson responses,
 * and validation() rejects lessons outside the submitted topic.
 *
 * The inline JS has no runner, so its state machine is guarded by source text.
 */
class form_lesson_selection_test extends TestCase {

    private static string $form;

    public static function setUpBeforeClass(): void {
        $contents = file_get_contents(realpath(__DIR__ . '/../../src') . '/mod_form.php');
        self::assertNotFalse($contents);
        self::$form = $contents;
    }

    /**
     * Returns the body of an inline JS function emitted from PHP, up to the
     * first line that closes it at the declaration's indentation.
     */
    private function js_function_body(string $name): string {
        $pattern = '/^([ \t]*)function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{\n(.*?)^\1\}/ms';
        $this->assertSame(1, preg_match($pattern, self::$form, $m), "JS function $name not found");
        return $m[2];
    }

    /**
     * Returns the body of the topic select's change handler.
     */
    private function change_handler_body(): string {
        $pattern = "/^([ \\t]*)topicSelect\\.addEventListener\\('change', function\\(\\) \\{\\n(.*?)^\\1\\}\\);/ms";
        $this->assertSame(1, preg_match($pattern, self::$form, $m), 'Topic change handler not found');
        return $m[2];
    }

    private function method_body(string $method): string {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\((.*?)(?=\n\s*(?:public|protected|private)?\s*(?:static\s+)?function\s)/s';
        $this->assertSame(1, preg_match($pattern, self::$form, $m), "Method $method not found");
        return $m[0];
    }

    private static function lessons(array $ids): array {
        return array_map(fn($id) => ['id' => $id, 'name' => "L$id", 'updatedAt' => '2026-09-01T00:00:00Z'], $ids);
    }

    // ---------------------------------------------------------------
    // skilland_lessons_outside_topic()
    // ---------------------------------------------------------------

    public function test_all_ids_in_topic_returns_empty(): void {
        $json = json_encode(['a' => ['updatedAt' => 'x', 'name' => 'A'], 'b' => ['updatedAt' => 'y', 'name' => 'B']]);
        $this->assertSame([], skilland_lessons_outside_topic($json, self::lessons(['a', 'b', 'c'])));
    }

    public function test_mixed_ids_return_only_foreign_ones_in_submitted_order(): void {
        $json = json_encode(['z' => [], 'a' => [], 'y' => []]);
        $this->assertSame(['z', 'y'], skilland_lessons_outside_topic($json, self::lessons(['a', 'b'])));
    }

    public static function non_object_values(): array {
        return [
            'empty object' => ['{}'],
            'empty string' => [''],
            'php array()' => ['array()'],
            'invalid json' => ['{"a":'],
            'empty json list' => ['[]'],
            'json scalar' => ['42'],
            'whitespace' => ['   '],
        ];
    }

    /**
     * @dataProvider non_object_values
     */
    public function test_non_object_values_return_empty(string $json): void {
        $this->assertSame([], skilland_lessons_outside_topic($json, self::lessons(['a'])));
    }

    public function test_non_empty_json_list_is_reported_as_outside_the_topic(): void {
        // skilland_process_selected_lessons() would save the indexes 0, 1 as lesson IDs.
        $this->assertSame(['0', '1'], skilland_lessons_outside_topic('["a","b"]', self::lessons(['a', 'b'])));
        $this->assertSame(['0', '1'], skilland_lessons_outside_topic(' [{"x":1},{"y":2}]', self::lessons([0, 1])));
    }

    public function test_validation_fetches_and_rejects_a_json_list(): void {
        // validation() only fetches the topic's lessons when the pre-check against [] is non-empty.
        $this->assertNotSame([], skilland_lessons_outside_topic('["a"]', []));
        $this->assertSame([], skilland_lessons_outside_topic('[]', []));
        $validation = $this->method_body('validation');
        $this->assertStringContainsString('skilland_lessons_outside_topic($selectedlessons, []) !== []', $validation);
        $this->assertMatchesRegularExpression(
            '/if \(skilland_lessons_outside_topic\(\$selectedlessons, \$topiclessons\) !== \[\]\) \{\s*\$errors\[\'skilland_topicid\'\] = get_string\(\'error_lessons_not_in_topic\'/',
            $validation
        );
    }

    public function test_numeric_and_string_ids_compare_equal(): void {
        $this->assertSame([], skilland_lessons_outside_topic('{"12":{},"34":{}}', self::lessons([12, '34'])));
        $this->assertSame(['56'], skilland_lessons_outside_topic('{"12":{},"56":{}}', self::lessons(['12'])));
    }

    public function test_empty_topic_returns_every_submitted_id(): void {
        $this->assertSame(['a', 'b'], skilland_lessons_outside_topic('{"a":{},"b":{}}', []));
    }

    public function test_malformed_topic_lessons_are_ignored(): void {
        $this->assertSame(['a'], skilland_lessons_outside_topic('{"a":{}}', ['a', ['name' => 'no id'], null]));
    }

    // ---------------------------------------------------------------
    // Inline JS source guards
    // ---------------------------------------------------------------

    public function test_state_variables_are_declared(): void {
        foreach (['selectionsByTopic = {}', 'renderedTopicId = null', 'activeTopicId = null', 'lessonsRequestSeq = 0'] as $decl) {
            $this->assertStringContainsString("var $decl;", self::$form);
        }
    }

    public function test_change_handler_no_longer_clears_by_saved_topic(): void {
        $handler = $this->change_handler_body();
        $this->assertStringNotContainsString('topicId !== currentTopicId', $handler);
        $this->assertStringNotContainsString('topicId !== currentTopicId', self::$form);
    }

    public function test_change_handler_stashes_only_a_rendered_topic_and_resets_the_hidden_value(): void {
        $handler = $this->change_handler_body();
        $this->assertMatchesRegularExpression(
            '/if \(renderedTopicId !== null\) \{\s*updateSelectedState\(\);\s*\}\s*selectedLessonsInput\.value = \'\{\}\';\s*renderedTopicId = null;\s*activeTopicId = topicId;/',
            $handler
        );
        $this->assertLessThan(strpos($handler, 'fetchLessons(topicId)'), strpos($handler, 'activeTopicId = topicId;'));
    }

    public function test_clearing_the_topic_invalidates_pending_requests(): void {
        $handler = $this->change_handler_body();
        $else = substr($handler, strrpos($handler, '} else {'));
        $this->assertStringContainsString('lessonsRequestSeq++;', $else);
        $this->assertStringContainsString("selectedLessonsInput.value = '{}';", $else);
        $this->assertStringContainsString('setLessonsLoading(false);', $else);
    }

    public function test_seed_uses_saved_topic_selection(): void {
        $this->assertStringContainsString('selectionsByTopic[currentTopicId] = seededSelection;', self::$form);
        $this->assertMatchesRegularExpression(
            '/String\(savedTopicInput\.value\) === String\(currentTopicId\)\) \{\s*seededSelection = parseSelectedLessons\(selectedLessonsInput\.value\);/',
            self::$form
        );
    }

    public function test_render_lessons_restores_per_topic_selection_and_derives_the_hidden_value(): void {
        $render = $this->js_function_body('renderLessons');
        $this->assertMatchesRegularExpression('/^\s*function renderLessons\(lessons, topicId\)/m', self::$form);
        $this->assertStringContainsString('selectionsByTopic', $render);
        $this->assertStringNotContainsString('JSON.stringify(selectedState)', $render);
        $this->assertDoesNotMatchRegularExpression('/selectedLessonsInput\.value\s*=/', $render);
        $this->assertMatchesRegularExpression('/renderedTopicId = topicId;\s*updateSelectedState\(\);\s*$/', $render);
    }

    public function test_update_selected_state_is_the_single_writer_of_the_per_topic_map(): void {
        $update = $this->js_function_body('updateSelectedState');
        $this->assertStringContainsString('selectionsByTopic[renderedTopicId] = state;', $update);
        $this->assertStringContainsString("querySelectorAll('#id_lessons_container .skilland-lesson-checkbox')", $update);
        $this->assertStringContainsString('selectedLessonsInput.value = JSON.stringify(state);', $update);
        $this->assertSame(1, substr_count(self::$form, 'selectionsByTopic[renderedTopicId] ='));
    }

    public function test_fetch_lessons_drops_stale_responses_on_both_paths(): void {
        $fetch = $this->js_function_body('fetchLessons');
        $this->assertStringContainsString('var seq = ++lessonsRequestSeq;', $fetch);
        $guard = 'if (seq !== lessonsRequestSeq || String(topicSelect.value) !== String(topicId)) {';
        $this->assertSame(2, substr_count($fetch, $guard));

        $then = strpos($fetch, '.then(function(response) {');
        $catch = strpos($fetch, '.catch(function(error) {');
        $this->assertNotFalse($then);
        $this->assertNotFalse($catch);
        $this->assertGreaterThan($then, strpos($fetch, $guard));
        $this->assertLessThan($catch, strpos($fetch, $guard));
        $this->assertGreaterThan($catch, strrpos($fetch, $guard));
    }

    public function test_fetch_lessons_locks_before_the_request_and_unlocks_on_every_settle(): void {
        $fetch = $this->js_function_body('fetchLessons');
        $lock = strpos($fetch, 'setLessonsLoading(true);');
        $this->assertNotFalse($lock);
        $this->assertLessThan(strpos($fetch, 'ajax.call('), $lock);
        // Rendered, empty, error response, rejected request.
        $this->assertSame(4, substr_count($fetch, 'setLessonsLoading(false);'));
        $this->assertStringContainsString('selectionsByTopic[topicId] = {};', $fetch);
    }

    public function test_loading_lock_disables_list_and_buttons(): void {
        $lock = $this->js_function_body('setLessonsLoading');
        $this->assertStringContainsString("setAttribute('aria-busy', 'true')", $lock);
        $this->assertStringContainsString('skilland-lessons-loading', $lock);
        foreach (['skilland-select-all', 'skilland-select-none', 'id_submitbutton', 'id_submitbutton2'] as $id) {
            $this->assertStringContainsString("'$id'", $lock);
        }
        $this->assertStringContainsString('control.disabled = loading;', $lock);
        $this->assertDoesNotMatchRegularExpression('/innerHTML/', $lock);
    }

    public function test_validation_rejects_lessons_outside_topic(): void {
        $validation = $this->method_body('validation');
        $this->assertStringContainsString('skilland_lessons_outside_topic(', $validation);
        $this->assertStringContainsString('mod_skilland_fetch_lessons(', $validation);
        $this->assertStringContainsString("get_string('error_lessons_not_in_topic', 'mod_skilland')", $validation);
        $this->assertMatchesRegularExpression('/catch \(moodle_exception \$e\) \{\s*\/\/[^\n]*\n\s*logger::debug\(/', $validation);
    }

    public function test_error_string_exists_in_both_languages(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include realpath(__DIR__ . '/../../src') . "/lang/$lang/skilland.php";
            $this->assertArrayHasKey('error_lessons_not_in_topic', $string, "Missing in $lang");
        }
    }

    public function test_version_bumped_for_skl_657(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        include realpath(__DIR__ . '/../../src') . '/version.php';
        $this->assertGreaterThanOrEqual(2026092603, $plugin->version);
    }

    // ---------------------------------------------------------------
    // Topic change confirmation on a provisioned activity (SKL-655)
    // ---------------------------------------------------------------

    public function test_has_scorm_is_emitted_from_the_saved_scormcmid(): void {
        $this->assertStringContainsString('$hasscorm = !empty($skilland->scormcmid);', self::$form);
        $this->assertStringContainsString('var hasScorm = " . json_encode($hasscorm) . ";', self::$form);
    }

    public function test_confirm_guard_sits_at_the_top_of_the_change_handler(): void {
        $handler = $this->change_handler_body();
        $guard = strpos($handler, 'if (hasScorm && activeTopicId !== null');
        $this->assertNotFalse($guard);
        $this->assertLessThan(strpos($handler, 'savedTopicInput.value = topicId;'), $guard);
        $this->assertLessThan(strpos($handler, 'fetchLessons(topicId)'), $guard);
        $this->assertStringContainsString('String(activeTopicId) === String(currentTopicId)', $handler);
        $this->assertStringContainsString('String(topicId) !== String(currentTopicId)', $handler);
    }

    public function test_guard_restores_the_previous_topic_and_returns_before_any_state_change(): void {
        $handler = $this->change_handler_body();
        $guardstart = strpos($handler, 'if (hasScorm');
        $guardend = strpos($handler, 'return;', $guardstart);
        $this->assertNotFalse($guardend);
        $guard = substr($handler, $guardstart, $guardend - $guardstart);
        $this->assertStringContainsString('topicSelect.value = previousTopicId;', $guard);
        $this->assertStringContainsString('Notification.confirm(', $guard);
        $this->assertStringContainsString('topicChangeConfirmTitle', $guard);
        $this->assertStringContainsString('topicChangeConfirmMessage', $guard);
        foreach (['savedTopicInput', 'updateSelectedState', 'fetchLessons', 'lessonsRequestSeq',
                'selectedLessonsInput', 'innerHTML', 'activeTopicId = '] as $untouched) {
            $this->assertStringNotContainsString($untouched, $guard, "Guard must not touch $untouched");
        }
    }

    public function test_confirm_strings_are_hex_escaped_lang_strings(): void {
        $flags = 'JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE';
        foreach (['topic_change_confirm_title', 'topic_change_confirm'] as $key) {
            $this->assertStringContainsString("json_encode(get_string('$key', 'mod_skilland'), $flags)", self::$form);
        }
    }
}
