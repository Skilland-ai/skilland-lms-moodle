<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-657: the activity form keeps the lesson selection per topic, derives
 * selected_lessons from the ticked checkboxes, drops stale lesson responses,
 * and validation() rejects lessons outside the submitted topic.
 *
 * The form's script (amd/src/mod_form.js since SKL-681) has no runner, so its state machine is
 * guarded by source text; PHP-side checks (validation(), the init config) stay on mod_form.php.
 */
class form_lesson_selection_test extends TestCase {

    private static string $form;

    private static string $js;

    private static string $css;

    public static function setUpBeforeClass(): void {
        $src = realpath(__DIR__ . '/../../src');
        foreach (['form' => '/mod_form.php', 'js' => '/amd/src/mod_form.js', 'css' => '/styles.css'] as $prop => $file) {
            $contents = file_get_contents($src . $file);
            self::assertNotFalse($contents, "Cannot read $file");
            self::${$prop} = $contents;
        }
    }

    /**
     * Returns the body of a JS function declaration in the form's AMD module, up to the
     * first line that closes it at the declaration's indentation.
     */
    private function js_function_body(string $name): string {
        $pattern = '/^([ \t]*)function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{\n(.*?)^\1\}/ms';
        $this->assertSame(1, preg_match($pattern, self::$js, $m), "JS function $name not found");
        return $m[2];
    }

    /**
     * Returns the body of the topic select's change handler.
     */
    private function change_handler_body(): string {
        $pattern = "/^([ \\t]*)topicSelect\\.addEventListener\\('change', function\\(\\) \\{\\n(.*?)^\\1\\}\\);/ms";
        $this->assertSame(1, preg_match($pattern, self::$js, $m), 'Topic change handler not found');
        return $m[2];
    }

    /**
     * The language string keys the module requests through core/str, with their component.
     *
     * @return array<string, string> key => component
     */
    private function module_string_keys(): array {
        $this->assertMatchesRegularExpression("#define\\(\\[[^\\]]*'core/str'#", self::$js);
        $this->assertStringContainsString('Str.get_strings(', self::$js);
        preg_match_all("/\\{key: '(\\w+)', component: '(\\w+)'/", self::$js, $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $match) {
            $keys[$match[1]] = $match[2];
        }
        return $keys;
    }

    /**
     * Asserts a key is requested through core/str by the module and exists in both language packs.
     */
    private function assert_module_string(string $key, string $component = 'mod_skilland'): void {
        $keys = $this->module_string_keys();
        $this->assertArrayHasKey($key, $keys, "'$key' is not fetched through core/str");
        $this->assertSame($component, $keys[$key]);
        if ($component !== 'mod_skilland') {
            return;
        }
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include realpath(__DIR__ . '/../../src') . "/lang/$lang/skilland.php";
            $this->assertArrayHasKey($key, $string, "Missing '$key' in $lang");
        }
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
    // Form script (amd/src/mod_form.js) source guards
    // ---------------------------------------------------------------

    public function test_state_variables_are_declared(): void {
        foreach (['selectionsByTopic = {}', 'renderedTopicId = null', 'activeTopicId = null', 'lessonsRequestSeq = 0'] as $decl) {
            $this->assertStringContainsString("var $decl;", self::$js);
        }
    }

    public function test_change_handler_no_longer_clears_by_saved_topic(): void {
        $handler = $this->change_handler_body();
        $this->assertStringNotContainsString('topicId !== currentTopicId', $handler);
        $this->assertStringNotContainsString('topicId !== currentTopicId', self::$js);
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
        $this->assertStringContainsString('selectionsByTopic[currentTopicId] = seededSelection;', self::$js);
        $this->assertMatchesRegularExpression(
            '/String\(savedTopicInput\.value\) === String\(currentTopicId\)\) \{\s*seededSelection = parseSelectedLessons\(selectedLessonsInput\.value\);/',
            self::$js
        );
    }

    public function test_render_lessons_restores_per_topic_selection_and_derives_the_hidden_value(): void {
        $render = $this->js_function_body('renderLessons');
        $this->assertMatchesRegularExpression('/^\s*function renderLessons\(lessons, topicId\)/m', self::$js);
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
        $this->assertSame(1, substr_count(self::$js, 'selectionsByTopic[renderedTopicId] ='));
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
        $this->assertLessThan(strpos($fetch, 'Ajax.call('), $lock);
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
        // SKL-681: handed to the module in its init config instead of a json_encode() splice.
        $this->assertStringContainsString("'hasscorm' => \$hasscorm,", self::$form);
        $this->assertStringContainsString('var hasScorm = !!config.hasscorm;', self::$js);
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

    public function test_confirm_strings_come_from_core_str(): void {
        // SKL-681: the confirmation copy is no longer composed and hex-escaped in PHP; the module
        // fetches every piece through core/str and composes it from the student count (SKL-697).
        $this->assertStringNotContainsString('json_encode(get_string(', self::$form);
        $this->assertStringNotContainsString('$topicchangeconfirmmessagetext', self::$form);
        foreach (['topic_change_confirm_title', 'topic_change_confirm', 'topic_change_confirm_students',
                'destructive_confirm_action', 'lockafterfirstaccess', 'lockafterfirstaccess_hint'] as $key) {
            $this->assert_module_string($key);
        }
        $this->assert_module_string('yes', 'core');
        $this->assert_module_string('no', 'core');
        $this->assertStringContainsString(
            "{key: 'topic_change_confirm_students', component: 'mod_skilland', param: studentCount}", self::$js);
        $this->assertStringContainsString("'studentattemptcount' => (int) \$topicstudentattemptcount,", self::$form);
        $this->assertStringContainsString('var topicChangeConfirmTitle = strings.topic_change_confirm_title;', self::$js);
        $this->assertMatchesRegularExpression(
            '/var topicChangeConfirmMessage = \(studentAttemptCount > 0 \?\s*strings\.topic_change_confirm_students : strings\.topic_change_confirm\) \+ \' \' \+ lockAfterFirstAccessHint;/',
            self::$js
        );
        $this->assertStringContainsString(
            'var topicChangeConfirmActionLabel = studentAttemptCount > 0 ? strings.destructive_confirm_action : strings.yes;',
            self::$js
        );
    }

    // ---------------------------------------------------------------
    // Stored lesson timestamps are never replaced by the API value (SKL-683)
    // ---------------------------------------------------------------

    public function test_update_selected_state_keeps_the_stored_updated_at_of_stored_lessons(): void {
        $update = $this->js_function_body('updateSelectedState');
        $this->assertMatchesRegularExpression('/var stored = currentSelectedLessons\[cb\.value\];/', $update);
        $this->assertMatchesRegularExpression(
            '/updatedAt: stored \? stored\.updatedAt : cb\.dataset\.updatedAt/', $update);
        $this->assertDoesNotMatchRegularExpression('/updatedAt:\s*cb\.dataset\.updatedAt\s*,/', $update);
    }

    public function test_render_lessons_never_writes_the_api_updated_at_into_state(): void {
        $render = $this->js_function_body('renderLessons');
        $this->assertDoesNotMatchRegularExpression('/updatedAt:\s*lesson\.updatedAt/', $render);
        $this->assertDoesNotMatchRegularExpression('/(selectedState|savedSelection|selectionsByTopic)\[[^\]]+\]\s*=/', $render);
        $this->assertDoesNotMatchRegularExpression('/(?<!\$)currentSelectedLessons\[[^\]]+\]\s*=[^=]/', self::$js);
        $this->assertDoesNotMatchRegularExpression('/(?<!\$)currentSelectedLessons\[[^\]]+\]\s*=[^=]/', self::$form);
    }

    public function test_version_bumped_for_skl_683(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        include realpath(__DIR__ . '/../../src') . '/version.php';
        $this->assertGreaterThanOrEqual(2026092605, $plugin->version);
    }

    // ---------------------------------------------------------------
    // SKL-688: the activity form stays usable when the SkilLand API fails
    // or the saved topic/lessons vanish upstream.
    // ---------------------------------------------------------------

    public function test_topicid_has_no_client_required_rule(): void {
        $this->assertDoesNotMatchRegularExpression(
            "/addRule\\('skilland_topicid', null, 'required'/",
            self::$form
        );
    }

    public function test_validation_still_requires_a_non_empty_topic_id(): void {
        $validation = $this->method_body('validation');
        $this->assertMatchesRegularExpression(
            '/if \(\$topicid === \'\'\) \{\s*\$errors\[\'skilland_topicid\'\] = get_string\(\'error_topicid_required\', \'mod_skilland\'\);/',
            $validation
        );
        // The rest of the topic/lesson checks only run once a topic id is present.
        $this->assertStringContainsString(
            "if (\$topicid === '') {\n                \$errors['skilland_topicid'] = get_string('error_topicid_required', 'mod_skilland');\n            } else {",
            self::$form
        );
    }

    public function test_error_topicid_required_string_exists_in_both_languages(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include realpath(__DIR__ . '/../../src') . "/lang/$lang/skilland.php";
            $this->assertArrayHasKey('error_topicid_required', $string, "Missing in $lang");
            $this->assertNotSame('', trim($string['error_topicid_required']));
        }
    }

    public function test_general_section_hidden_by_toggled_class_not_unconditional_css(): void {
        // SKL-681: the rule lives in the plugin stylesheet, no longer in an inline <style>.
        $this->assertStringNotContainsString('#id_general { display: none; }', self::$css);
        $this->assertStringNotContainsString('#id_general { display: none; }', self::$form);
        $this->assertStringContainsString('#id_general.skilland-hide-general { display: none; }', self::$css);
    }

    public function test_general_section_visibility_skips_hiding_on_a_name_error(): void {
        $fn = $this->js_function_body('applyGeneralSectionVisibility');
        $this->assertStringContainsString("getElementById('id_error_name')", $fn);
        $this->assertStringContainsString('hasNameError', $fn);
        $this->assertMatchesRegularExpression('/if \(hasNameError\) \{\s*return;\s*\}/', $fn);
        $this->assertStringContainsString("classList.add('skilland-hide-general')", $fn);
        // Only hides once the name has a value; an empty new-activity name stays visible.
        $this->assertMatchesRegularExpression('/if \(nameField && nameField\.value\) \{\s*generalHeader\.classList\.add/', $fn);
    }

    public function test_topic_fetch_is_a_named_retryable_function(): void {
        $this->assertMatchesRegularExpression('/^\s*function fetchTopics\(\)/m', self::$js);
        // Called once to load the form, and once more from the Retry control's click handler.
        $this->assertSame(2, substr_count(self::$js, 'fetchTopics();'));
    }

    public function test_fetch_topics_clears_the_retry_control_on_every_call(): void {
        // A retry succeeding after a prior failure must hide the Retry control it showed;
        // this is the first statement in the function body, before the request even fires.
        $fn = $this->js_function_body('fetchTopics');
        $this->assertMatchesRegularExpression('/^\s*hideTopicRetryControl\(\);/', $fn);
    }

    public function test_topic_fetch_failure_keeps_the_select_usable_and_offers_retry(): void {
        $fn = $this->js_function_body('showTopicFetchError');
        $this->assertStringContainsString('currentTopicId', $fn);
        $this->assertStringContainsString('keepOption.selected = true;', $fn);
        $this->assertStringContainsString('showTopicRetryControl();', $fn);

        $retry = $this->js_function_body('showTopicRetryControl');
        $this->assertStringContainsString("id = 'skilland-topic-retry'", $retry);
        $this->assertStringContainsString('fetchTopics();', $retry);

        // Both the response.error and the rejected-promise branches keep the form usable.
        $this->assertMatchesRegularExpression('/if \(response\.error\) \{\s*showTopicFetchError\(\);/', self::$js);
        $this->assertMatchesRegularExpression('/\}\)\.catch\(function\(error\) \{\s*log\([^\n]*\);\s*showTopicFetchError\(\);/', self::$js);
    }

    public function test_stale_saved_topic_is_kept_as_a_disabled_selected_option(): void {
        $fn = $this->js_function_body('addStaleTopicOption');
        $this->assertStringContainsString('option.disabled = true;', $fn);
        $this->assertStringContainsString('option.selected = true;', $fn);
        $this->assertStringContainsString('topicNoLongerAvailableText', $fn);

        // The success handler only follows the fetchLessons/dispatch path when the saved topic
        // is still in the fresh list; otherwise it keeps the saved topic id and lessons intact.
        $this->assertMatchesRegularExpression(
            '/if \(topicsMap\[currentTopicId\]\) \{.*?\} else \{\s*addStaleTopicOption\(currentTopicId\);/s',
            self::$js
        );
        $this->assertStringContainsString("type: 'warning'", self::$js);
    }

    public function test_stale_topic_lessons_are_not_wiped(): void {
        $fn = $this->js_function_body('renderStaleTopicLessons');
        $this->assertStringNotContainsString('selectedLessonsInput', $fn);
        $this->assertStringNotContainsString('selectionsByTopic', $fn);
        $this->assertStringContainsString('currentSelectedLessons', $fn);
    }

    public function test_missing_lessons_are_flagged_with_a_remove_action(): void {
        $render = $this->js_function_body('renderLessons');
        $this->assertStringContainsString('missingLessonsByTopic', $render);
        $this->assertStringContainsString('skilland-missing-lessons-warning', $render);
        $this->assertStringContainsString('removeMissingLessonText', $render);
        $this->assertMatchesRegularExpression(
            '/removeBtn\.addEventListener\(\'click\', function\(e\) \{\s*e\.preventDefault\(\);\s*removeMissingLesson\(topicId, lessonId, row\);/',
            $render
        );
    }

    public function test_missing_lessons_stay_selected_until_explicitly_removed(): void {
        $update = $this->js_function_body('updateSelectedState');
        $this->assertStringContainsString('missingLessonsByTopic[renderedTopicId]', $update);
        $this->assertStringContainsString('state[lessonId] = pendingMissing[lessonId];', $update);

        $remove = $this->js_function_body('removeMissingLesson');
        $this->assertStringContainsString('delete currentSelectedLessons[lessonId];', $remove);
        $this->assertStringContainsString('updateSelectedState();', $remove);
    }

    public function test_missing_lesson_strings_exist_in_both_languages(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include realpath(__DIR__ . '/../../src') . "/lang/$lang/skilland.php";
            foreach (['missing_lessons_warning', 'remove_from_activity', 'current_topic_unavailable',
                    'topic_no_longer_available', 'topic_no_longer_available_warning', 'retry'] as $key) {
                $this->assertArrayHasKey($key, $string, "Missing '$key' in $lang");
                $this->assertNotSame('', trim($string[$key]));
            }
        }
    }

    public function test_version_bumped_for_skl_688(): void {
        if (!defined('MATURITY_BETA')) {
            define('MATURITY_BETA', 100);
        }
        $plugin = new \stdClass();
        include realpath(__DIR__ . '/../../src') . '/version.php';
        $this->assertGreaterThan(2026092621, $plugin->version);
    }

    // ---------------------------------------------------------------
    // SKL-681: the form's JS and CSS live in amd/src/mod_form.js and styles.css
    // ---------------------------------------------------------------

    public function test_form_emits_no_inline_script_or_style(): void {
        foreach (['<script', '<style', 'style="', "'style' =>", '$js = "', 'json_encode(get_string('] as $needle) {
            $this->assertStringNotContainsString($needle, self::$form, "mod_form.php still contains $needle");
        }
        // Loaded as an AMD module, both for the full form and for the missing Skilland Course ID notice.
        $this->assertSame(2, substr_count(self::$form, "\$PAGE->requires->js_call_amd('mod_skilland/mod_form', 'init', [["));
        $this->assertStringContainsString("[['missingcourseid' => true]]", self::$form);
    }

    public function test_init_config_carries_every_php_value_the_script_reads(): void {
        foreach (['debug', 'skillandcourseid', 'moodlecourseid', 'currenttopicid', 'currentlessonsid', 'hasscorm',
                'studentattemptcount', 'skillandinstanceid', 'cmid', 'ssourl', 'editlinkhtml'] as $key) {
            $this->assertMatchesRegularExpression("/'$key' => /", self::$form, "'$key' missing from the init config");
            $this->assertStringContainsString("config.$key", self::$js, "the module never reads config.$key");
        }
        $this->assertStringContainsString("'debug' => (bool) get_config('mod_skilland', 'devmode'),", self::$form);
        $this->assertStringContainsString("'ssourl' => (new moodle_url('/mod/skilland/sso_redirect.php'))->out(false),",
            self::$form);
        // The saved lessons can exceed js_call_amd's argument budget: they travel as a data attribute.
        $this->assertStringContainsString("'data-lessons' => json_encode(\$currentselectedlessons),", self::$form);
        $this->assertStringContainsString("holder.getAttribute('data-lessons')", self::$js);
    }

    public function test_every_script_string_comes_from_core_str_and_exists_in_both_languages(): void {
        // Each of these was a json_encode(get_string(...)) literal in mod_form.php before SKL-681.
        foreach (['loading', 'error_fetch_topics', 'error_fetch_topics_detail', 'select_topic', 'no_topics_available',
                'no_lessons_found', 'current_topic_unavailable', 'topic_no_longer_available',
                'topic_no_longer_available_warning', 'retry', 'missing_lessons_warning', 'remove_from_activity',
                'new_content_available', 'updated_on', 'update_confirm_title', 'update_confirm_message',
                'update_confirm_message_students', 'update_success', 'update_error'] as $key) {
            $this->assert_module_string($key);
        }
        $this->assertStringNotContainsString('get_new_content_string', self::$form);
    }

    public function test_placeholder_strings_are_filled_through_core_str_not_a_literal_replace(): void {
        // SKL-696 passed '{$a}' through literally and replaced it in JS; SKL-681 hands core/str a
        // placeholder as the parameter and swaps it with a literal split/join (no "$&" patterns).
        $this->assertStringNotContainsString("replace('{\$a}'", self::$js);
        $this->assertStringNotContainsString("replace('{\\\$a}'", self::$js);
        foreach (['updated_on' => 'datePlaceholder', 'error_fetch_topics_detail' => 'errorPlaceholder',
                'lockafterfirstaccess_hint' => 'settingPlaceholder'] as $key => $placeholder) {
            $this->assertStringContainsString("{key: '$key', component: 'mod_skilland', param: $placeholder}", self::$js);
        }
        $fill = $this->js_function_body('fillIn');
        $this->assertStringContainsString('.split(placeholder).join(String(value))', $fill);
        $this->assertStringContainsString('fillIn(strings.updated_on, datePlaceholder, date.toLocaleString())', self::$js);
        $this->assertSame(2, substr_count(self::$js, 'escapeHtml(fillIn(strings.error_fetch_topics_detail, errorPlaceholder,'));
        // The count-dependent variants take the student count straight through core/str.
        $this->assertStringContainsString(
            "{key: 'update_confirm_message_students', component: 'mod_skilland', param: studentCount}", self::$js);
    }

    public function test_handlers_are_bound_only_after_the_strings_resolve(): void {
        $init = substr(self::$js, strpos(self::$js, 'var init = function(config) {'));
        $disable = strpos($init, 'topicSelect.disabled = true;');
        $fetch = strpos($init, 'Str.get_strings(requests)');
        $this->assertNotFalse($disable);
        $this->assertNotFalse($fetch);
        $this->assertLessThan($fetch, $disable, 'The topic select must be disabled before the strings are requested');
        $this->assertStringContainsString('updateBtn.disabled = true;', $init);
        // The form proper only starts from the strings promise, on success or failure.
        $this->assertSame(1, substr_count(self::$js, 'initActivityForm(config, strings);'));
        $this->assertMatchesRegularExpression(
            '/Str\.get_strings\(requests\)\.done\(start\)\.fail\(function\(error\) \{[^}]*Notification\.exception\(error\);\s*start\(null\);/s',
            $init
        );
    }

    public function test_stylesheet_is_scoped_to_the_plugin(): void {
        // styles.css is loaded on every Moodle page: every rule must target something only this plugin renders.
        $css = preg_replace('#/\*.*?\*/#s', '', self::$css);
        $this->assertGreaterThan(0, preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $m));
        foreach ($m[1] as $selectorlist) {
            foreach (explode(',', $selectorlist) as $selector) {
                $this->assertStringContainsString('skilland', $selector, 'Unscoped selector: ' . trim($selector));
            }
        }
        // The "no Skilland Course ID" rules only match a form that holds the warning PHP renders.
        $this->assertStringContainsString("'alert alert-warning skilland-missing-courseid'", self::$form);
        $this->assertStringContainsString('form.mform:has(.skilland-missing-courseid) .fheader,', self::$css);
        $this->assertStringNotContainsString("\nform.mform .fheader", self::$css);
    }

    public function test_hidden_containers_are_toggled_by_class(): void {
        // The inline display:none moved to d-none, so the module toggles the class, not style.display.
        $this->assertStringContainsString('id="skilland-edit-button-container" class="form-group row fitem d-none"', self::$form);
        $this->assertStringContainsString('id="skilland-select-actions" class="mb-2 d-none"', self::$form);
        $edit = $this->js_function_body('updateEditButton');
        $this->assertStringContainsString("editButtonContainer.classList.remove('d-none');", $edit);
        $this->assertStringContainsString("editButtonContainer.classList.add('d-none');", $edit);
        $this->assertStringContainsString("selectActions.classList.remove('d-none');", $this->js_function_body('renderLessons'));
    }

    public function test_built_module_is_committed(): void {
        $this->assertFileExists(realpath(__DIR__ . '/../../src') . '/amd/build/mod_form.min.js');
    }
}
