<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-670: source scan — no email in log lines, no raw exception text returned by web services,
 * and browser console output only through a single devmode-gated `log` helper per file.
 */
class no_pii_logging_test extends TestCase {

    private const SRC = __DIR__ . '/../../src';

    /**
     * Every plugin PHP file under src/, without vendor/ and lang/.
     *
     * @return string[]
     */
    private function php_files(): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC,
            \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if ($file->getExtension() !== 'php' || strpos($path, '/vendor/') !== false ||
                    strpos($path, '/lang/') !== false) {
                continue;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    public function test_no_log_call_mentions_an_email(): void {
        $offenders = [];
        foreach ($this->php_files() as $path) {
            $source = file_get_contents($path);
            preg_match_all('/(?:logger::\w+|\bdebugging)\s*\(.*?\);/s', $source, $calls);
            foreach ($calls[0] as $call) {
                if (stripos($call, 'email') !== false) {
                    $offenders[] = basename($path) . ': ' . trim($call);
                }
            }
        }

        $this->assertSame([], $offenders, 'Log calls must not include email addresses; log the user id instead');
    }

    public function test_external_functions_never_return_the_raw_exception_message(): void {
        foreach (glob(self::SRC . '/classes/external/*.php') as $path) {
            $source = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression("/'error'\\s*=>\\s*\\\$e->getMessage\\(\\)/", $source,
                basename($path) . ' must return self::client_error($e, ...)');
        }
    }

    public static function js_sources(): array {
        // SKL-681: the form and course settings scripts are AMD modules; the PHP files that load
        // them stay in the list so no console call can come back inline.
        $files = [
            'mod_form.php' => ['mod_form.php'],
            'classes/hooks.php' => ['classes/hooks.php'],
            'amd/src/mod_form.js' => ['amd/src/mod_form.js'],
            'amd/src/course_mapping.js' => ['amd/src/course_mapping.js'],
        ];
        foreach (glob(self::SRC . '/amd/src/*.js') as $path) {
            $relative = 'amd/src/' . basename($path);
            $files[$relative] = [$relative];
        }
        return $files;
    }

    /**
     * Remove the body of the file's `log` helper, returning the rest of the source.
     *
     * @param string $source
     * @param string $file For assertion messages.
     * @return string
     */
    private function strip_log_helper(string $source, string $file): string {
        preg_match_all('/(?:function\s+log\s*\(|\blog\s*=\s*function\s*\()/', $source, $matches, PREG_OFFSET_CAPTURE);
        $this->assertLessThanOrEqual(1, count($matches[0]), $file . ' may define at most one log helper');
        if (!$matches[0]) {
            return $source;
        }
        $start = $matches[0][0][1];
        $open = strpos($source, '{', $start);
        $depth = 0;
        for ($i = $open; $i < strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } else if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }
        $body = substr($source, $open, $i - $open + 1);
        $this->assertMatchesRegularExpression('/\bdebug\s*&&/', $body, $file . ': the log helper must be gated on debug');
        return substr($source, 0, $start) . substr($source, $i + 1);
    }

    /**
     * @dataProvider js_sources
     */
    public function test_console_output_only_through_the_log_helper(string $file): void {
        $source = file_get_contents(self::SRC . '/' . $file);
        $this->assertNotFalse($source, "Cannot read $file");

        $rest = $this->strip_log_helper($source, $file);

        $this->assertDoesNotMatchRegularExpression('/\bconsole\.(log|error|warn|debug)\b/', $rest,
            $file . ' must log through its devmode-gated log helper');
        $this->assertStringNotContainsString('Object.keys(error)', $source);
    }

    public function test_modules_receive_the_devmode_flag(): void {
        $modules = ['mod_form.php' => 'amd/src/mod_form.js', 'classes/hooks.php' => 'amd/src/course_mapping.js'];
        foreach ($modules as $php => $module) {
            $source = file_get_contents(self::SRC . '/' . $php);
            $this->assertMatchesRegularExpression("/'\\w+'\\s*=>\\s*\\(bool\\) get_config\\('mod_skilland', 'devmode'\\)/",
                $source, "$php must pass the devmode flag in the module's init config");
            $this->assertMatchesRegularExpression('/\\bdebug\\s*=\\s*[^;]*\\bconfig\\.\\w+/',
                file_get_contents(self::SRC . '/' . $module), "$module must take debug from its init config");
        }
    }

    public function test_form_submit_logging_listener_is_gone(): void {
        $this->assertStringNotContainsString('Form submitting with', file_get_contents(self::SRC . '/mod_form.php'));
    }

    public function test_graphql_client_does_not_dump_curl_info(): void {
        $this->assertStringNotContainsString('print_r($info', file_get_contents(self::SRC . '/locallib.php'));
    }
}
