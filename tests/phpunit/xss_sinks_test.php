<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Source-text guards for the XSS sinks fixed in SKL-674: SkilLand API data and
 * language strings must never reach innerHTML or addslashes-built JS literals.
 */
class xss_sinks_test extends TestCase {

    private static string $srcDir;

    public static function setUpBeforeClass(): void {
        self::$srcDir = realpath(__DIR__ . '/../../src');
    }

    private function source(string $relative): string {
        $contents = file_get_contents(self::$srcDir . '/' . $relative);
        $this->assertNotFalse($contents, "Cannot read $relative");
        return $contents;
    }

    /**
     * Returns the body of a method, from its signature to the next method signature (or the end of the file).
     */
    private function method_body(string $source, string $method): string {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\((.*?)(?=\n\s*(?:public|protected|private)?\s*(?:static\s+)?function\s|\z)/s';
        $this->assertSame(1, preg_match($pattern, $source, $m), "Method $method not found");
        return $m[0];
    }

    public function test_topic_description_is_purified_server_side(): void {
        $external = $this->source('classes/external/fetch_topics.php');

        $returns = $this->method_body($external, 'execute_returns');
        $this->assertMatchesRegularExpression(
            "/'description'\s*=>\s*new external_value\(PARAM_CLEANHTML\b/",
            $returns
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'description'\s*=>\s*new external_value\(PARAM_RAW\b/",
            $returns
        );

        $body = $this->method_body($external, 'execute');
        $this->assertMatchesRegularExpression(
            "/'description'\s*=>\s*clean_text\(\\\$topic\['description'\]/",
            $body
        );
    }

    public function test_clean_text_stub_strips_script_and_handlers(): void {
        $clean = clean_text('<p>ok</p><script>alert(1)</script><img src="x" onerror="alert(1)">', FORMAT_HTML);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringContainsString('<p>ok</p>', $clean);
    }

    public function test_hooks_does_not_use_addslashes(): void {
        $this->assertStringNotContainsString('addslashes(', $this->source('classes/hooks.php'));
    }

    public static function innerhtml_files(): array {
        return [
            'mod_form.php' => ['mod_form.php'],
            'classes/hooks.php' => ['classes/hooks.php'],
        ];
    }

    /**
     * @dataProvider innerhtml_files
     */
    public function test_no_innerhtml_assignment_concatenates_untrusted_data(string $file): void {
        $source = $this->source($file);
        $tainted = ['response\.', 'error\.message', 'errorText', 'displayText', '\$buttontext', '\$ssourl'];
        $pattern = '/\.innerHTML\s*[+]?=([^;]*)(' . implode('|', $tainted) . ')/';

        $offenders = [];
        if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $offenders[] = trim($match[0]);
            }
        }
        $this->assertSame([], $offenders, "Untrusted data assigned to innerHTML in $file");
    }

    /**
     * Returns the body of an inline JS function emitted from PHP, up to the
     * first line that closes it at the declaration's indentation.
     */
    private function js_function_body(string $source, string $name): string {
        $pattern = '/^([ \t]*)function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{\n(.*?)^\1\}/ms';
        $this->assertSame(1, preg_match($pattern, $source, $m), "JS function $name not found");
        return $m[2];
    }

    /**
     * @dataProvider notification_files
     */
    public function test_escape_html_helper_escapes_all_five_characters_ampersand_first(string $file): void {
        $body = $this->js_function_body($this->source($file), 'escapeHtml');

        // The source is a PHP double-quoted string, so \" in it is a literal " in the emitted JS.
        $this->assertGreaterThan(0, preg_match_all("#\.replace\(/(.+?)/g,\s*'([^']*)'\)#", $body, $m, PREG_SET_ORDER));
        $pairs = [];
        foreach ($m as $match) {
            $pairs[] = [str_replace('\\"', '"', $match[1]), $match[2]];
        }

        // Apply the replacements in source order: a wrong order (e.g. & after <) double-escapes.
        $input = '<a href="x" onclick=\'y\'>&amp;';
        $output = $input;
        foreach ($pairs as [$search, $replace]) {
            $output = str_replace($search, $replace, $output);
        }
        $this->assertSame('&lt;a href=&quot;x&quot; onclick=&#39;y&#39;&gt;&amp;amp;', $output);
        $this->assertSame('&', $pairs[0][0], 'escapeHtml must replace & before the other characters');
        $this->assertStringContainsString("str === undefined || str === null ? ''", $body);
    }

    public static function notification_files(): array {
        return [
            'mod_form.php' => ['mod_form.php'],
            'classes/hooks.php' => ['classes/hooks.php'],
        ];
    }

    /**
     * core/notification.addNotification renders its message as HTML.
     *
     * @dataProvider notification_files
     */
    public function test_notifications_with_dynamic_data_are_escaped(string $file): void {
        $source = $this->source($file);
        $this->assertGreaterThan(0, preg_match_all('/addNotification\(\{\s*message:\s*([^\n]*?),?\n/', $source, $m));

        $offenders = [];
        foreach ($m[1] as $message) {
            $message = trim($message);
            $dynamic = str_contains($message, '+') || preg_match('/\b(response|resp|error)\b/', $message);
            if ($dynamic && !str_starts_with($message, 'escapeHtml(')) {
                $offenders[] = $message;
            }
        }
        $this->assertSame([], $offenders, "Unescaped dynamic notification message in $file");
    }

    public function test_error_helpers_render_messages_as_text(): void {
        $source = $this->source('mod_form.php');

        $lessons = $this->js_function_body($source, 'showLessonsError');
        $this->assertStringContainsString('errorSpan.textContent = message;', $lessons);
        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[^;]*message/', $lessons);

        $topics = $this->js_function_body($source, 'showTopicSelectError');
        $this->assertStringContainsString('errorOption.textContent = message;', $topics);
        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[^;]*message/', $topics);

        $this->assertMatchesRegularExpression('/showLessonsError\(lessonsContainer,\s*response\.error\)/', $source);
        $this->assertMatchesRegularExpression('/showLessonsError\(lessonsContainer,\s*error\.message\)/', $source);
        $this->assertSame(2, substr_count($source, 'showTopicSelectError(topicSelect, errorText)'));
    }

    public function test_course_name_and_code_are_rendered_as_text(): void {
        $source = $this->source('mod_form.php');
        $this->assertStringContainsString('nameSpan.textContent = response.course.name;', $source);
        $this->assertStringContainsString("codeSpan.textContent = ' (' + response.course.code + ')';", $source);
        $this->assertStringNotContainsString('displayText', $source);
        // The only innerHTML left in the course display is the server-built edit link.
        $this->assertMatchesRegularExpression("/editLinkSpan\.innerHTML = '\(' \+ editLinkHtml \+ '\)';/", $source);
        $this->assertMatchesRegularExpression('/var editLinkHtml = " \. json_encode\(\$editlink\) \. ";/', $source);
    }

    public function test_topic_description_fills_the_intro_editor_as_escaped_text(): void {
        $source = $this->source('mod_form.php');

        // The intro is an editor on #id_introeditor; #id_intro does not exist on the form (SKL-759).
        $this->assertStringNotContainsString("getElementById('id_intro')", $source);
        $this->assertStringNotContainsString("get('id_intro')", $source);
        $this->assertStringNotContainsString("'#id_introeditable'", $source);

        $set = $this->js_function_body($source, 'setIntroDescription');
        $this->assertStringContainsString("document.getElementById('id_introeditor')", $set);
        $this->assertStringContainsString('descriptionToIntroHtml(description)', $set);
        $this->assertStringContainsString('lastAutoIntroHtml', $set);

        $html = $this->js_function_body($source, 'descriptionToIntroHtml');
        $this->assertStringContainsString("'<p>' + escapeHtml(text) + '</p>'", $html);

        $text = $this->js_function_body($source, 'htmlToText');
        $this->assertStringContainsString("parseFromString(text, 'text/html')", $text);
        $this->assertStringNotContainsString('innerHTML', $text);

        $this->assertMatchesRegularExpression("/tiny\.get\('id_introeditor'\)/", $source);
        $this->assertStringContainsString("getInstanceForElementId('id_introeditor')", $source);
        $this->assertStringContainsString("document.getElementById('id_introeditoreditable')", $source);
    }

    public function test_topic_options_are_built_with_textcontent(): void {
        $source = $this->source('mod_form.php');
        $this->assertStringContainsString('option.textContent = optionText;', $source);
        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*[+]?=[^;]*(topic\.name|optionText|description)/', $source);
    }

    public function test_hooks_emit_php_values_into_js_as_hex_escaped_json(): void {
        $hooks = $this->source('classes/hooks.php');

        // No PHP value spliced inside a hand-quoted JS string literal.
        $this->assertDoesNotMatchRegularExpression("/'\"\s*\.\s*[\\\\\\\$a-z_]/i", $hooks);

        $this->assertGreaterThan(0, preg_match_all('/json_encode\(([^;]*)\);?/', $hooks, $m));
        foreach ($m[1] as $args) {
            foreach (['JSON_HEX_TAG', 'JSON_HEX_APOS', 'JSON_HEX_QUOT', 'JSON_HEX_AMP'] as $flag) {
                $this->assertStringContainsString($flag, $args, "json_encode in hooks.php without $flag: $args");
            }
        }

        foreach (['creating_course', 'create_in_skilland', 'go_to_skilland'] as $key) {
            $this->assertMatchesRegularExpression(
                "/json_encode\(\\\\?get_string\('$key', 'mod_skilland'\), JSON_HEX_TAG/",
                $hooks,
                "get_string('$key') is not emitted through json_encode"
            );
        }

        $this->assertStringContainsString('link.href = " . $ssourljs . ";', $hooks);
        $this->assertStringContainsString('label.textContent = " . $buttontext . ";', $hooks);
        $this->assertStringNotContainsString('div.innerHTML', $hooks);
    }

    public function test_hex_flags_neutralise_script_breakout(): void {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
        $encoded = json_encode("</script><script>alert('x')</script> & \"q\" ñ", $flags);
        $this->assertStringNotContainsString('<', $encoded);
        $this->assertStringNotContainsString("'", $encoded);
        $this->assertStringNotContainsString('&', $encoded);
        $this->assertStringContainsString('ñ', $encoded);
        $this->assertSame("</script><script>alert('x')</script> & \"q\" ñ", json_decode($encoded));
    }

    public function test_version_bumped_for_skl_674(): void {
        $this->assertSame(1, preg_match('/\$plugin->version\s*=\s*(\d{10})\s*;/', $this->source('version.php'), $m));
        $this->assertGreaterThan(2026092519, (int)$m[1]);
    }

    public function test_hooks_notifications_escape_api_errors(): void {
        $hooks = $this->source('classes/hooks.php');
        $this->assertMatchesRegularExpression('/function\s+escapeHtml\s*\(/', $hooks);
        $this->assertSame(0, preg_match('/message:(?![^\n]*escapeHtml\()[^\n]*\b(resp|response)\.error\b/', $hooks),
            'addNotification message concatenates resp.error/response.error without escapeHtml');
        $this->assertGreaterThanOrEqual(2, preg_match_all('/message:\s*escapeHtml\([^\n]*\b(resp|response)\.error\b/', $hooks));
    }

    public function test_create_course_redirect_url_is_validated(): void {
        $returns = $this->method_body($this->source('classes/external/create_course.php'), 'execute_returns');
        $this->assertMatchesRegularExpression(
            "/'redirect_url'\s*=>\s*new external_value\(PARAM_URL\b/",
            $returns
        );

        $hooks = $this->source('classes/hooks.php');
        $this->assertStringContainsString('/^https?:\\/\\//i.test(pendingRedirectUrl)', $hooks);
        $this->assertSame(1, substr_count($hooks, 'window.open(pendingRedirectUrl'));
        $this->assertMatchesRegularExpression(
            '/\.test\(pendingRedirectUrl\)\)\s*\{\s*window\.open\(pendingRedirectUrl/',
            $hooks,
            'window.open(pendingRedirectUrl) must sit behind the http(s) scheme check'
        );
    }
}
