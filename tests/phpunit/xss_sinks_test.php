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
     * Returns the body of a method, from its signature to the next method signature.
     */
    private function method_body(string $source, string $method): string {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\((.*?)(?=\n\s*(?:public|protected|private)?\s*(?:static\s+)?function\s)/s';
        $this->assertSame(1, preg_match($pattern, $source, $m), "Method $method not found");
        return $m[0];
    }

    public function test_topic_description_is_purified_server_side(): void {
        $external = $this->source('classes/external.php');

        $returns = $this->method_body($external, 'fetch_topics_ajax_returns');
        $this->assertMatchesRegularExpression(
            "/'description'\s*=>\s*new external_value\(PARAM_CLEANHTML\b/",
            $returns
        );
        $this->assertDoesNotMatchRegularExpression(
            "/'description'\s*=>\s*new external_value\(PARAM_RAW\b/",
            $returns
        );

        $body = $this->method_body($external, 'fetch_topics_ajax');
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
}
