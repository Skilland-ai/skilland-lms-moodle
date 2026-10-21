<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Check that every plugin language defines the same strings as English.
 *
 * The full Moodle get_string_manager() is not available in this standalone PHPUnit harness
 * (see tests/phpunit/bootstrap.php: get_string() is a stub that just echoes back the
 * identifier), so parity is checked by loading every lang file directly, the same technique
 * integrity_test.php uses for its own EN/ES parity checks. This file exists
 * specifically so a lang-key change is caught even if integrity_test.php's broader checks
 * are ever split apart or skipped.
 */
class language_parity_test extends TestCase {

    private static string $srcDir;
    private static array $strings = [];

    public static function setUpBeforeClass(): void {
        self::$srcDir = realpath(__DIR__ . '/../../src');

        foreach (glob(self::$srcDir . '/lang/*/skilland.php') as $file) {
            $lang = basename(dirname($file));
            $string = [];
            require $file;
            self::$strings[$lang] = $string;
        }
    }

    public function test_required_languages_are_present(): void {
        foreach (['en', 'es', 'fr', 'de', 'it', 'pt'] as $lang) {
            $this->assertArrayHasKey($lang, self::$strings, "Missing $lang lang file");
        }
    }

    public function test_key_sets_match_en(): void {
        $enKeys = array_keys(self::$strings['en']);

        sort($enKeys);
        foreach (self::$strings as $lang => $strings) {
            if ($lang === 'en') {
                continue;
            }
            $keys = array_keys($strings);
            sort($keys);
            $this->assertSame(
                $enKeys,
                $keys,
                "EN and $lang lang files must define exactly the same set of keys.\n" .
                "Missing in $lang: " . implode(', ', array_diff($enKeys, $keys)) . "\n" .
                "Extra in $lang: " . implode(', ', array_diff($keys, $enKeys))
            );
        }
    }

    public function test_key_counts_match_en(): void {
        foreach (self::$strings as $lang => $strings) {
            $this->assertCount(count(self::$strings['en']), $strings, "$lang lang file has a different key count from EN");
        }
    }

    public function test_key_order_matches_en(): void {
        foreach (self::$strings as $lang => $strings) {
            $this->assertSame(array_keys(self::$strings['en']), array_keys($strings), "$lang lang file key order differs from EN");
        }
    }

    public function test_no_duplicate_keys(): void {
        // require executes top-to-bottom into the same $string array, so a duplicate
        // key definition silently overwrites the earlier one instead of raising an error.
        // Parse the source text itself to catch that.
        foreach (self::$strings as $lang => $strings) {
            $source = file_get_contents(self::$srcDir . "/lang/$lang/skilland.php");
            preg_match_all("/\\\$string\\['([a-zA-Z0-9_:]+)'\\]\\s*=/", $source, $matches);
            $duplicates = array_keys(array_filter(array_count_values($matches[1]), fn($count) => $count > 1));
            $this->assertEmpty($duplicates, "Duplicate keys in $lang lang file: " . implode(', ', $duplicates));
        }
    }

    public function test_no_empty_values(): void {
        foreach (self::$strings as $lang => $strings) {
            $empty = [];
            foreach ($strings as $key => $value) {
                if (trim((string) $value) === '') {
                    $empty[] = $key;
                }
            }
            $this->assertEmpty($empty, "$lang lang file has empty string values: " . implode(', ', $empty));
        }
    }

    public function test_placeholders_match_en(): void {
        foreach (self::$strings as $lang => $strings) {
            if ($lang === 'en') {
                continue;
            }
            foreach (self::$strings['en'] as $key => $value) {
                if (!array_key_exists($key, $strings)) {
                    continue;
                }
                preg_match_all('/\{\$a(?:->[a-zA-Z0-9_]+)?\}/', $value, $enMatches);
                preg_match_all('/\{\$a(?:->[a-zA-Z0-9_]+)?\}/', $strings[$key], $langMatches);
                $expected = array_unique($enMatches[0]);
                $actual = array_unique($langMatches[0]);
                sort($expected);
                sort($actual);
                $this->assertSame($expected, $actual, "Placeholder mismatch in $lang: $key");
            }
        }
    }

    public function test_html_tags_match_en(): void {
        foreach (self::$strings as $lang => $strings) {
            if ($lang === 'en') {
                continue;
            }
            foreach (self::$strings['en'] as $key => $value) {
                if (!array_key_exists($key, $strings)) {
                    continue;
                }
                preg_match_all('/<\/?[a-zA-Z][^>]*>/', $value, $enMatches);
                preg_match_all('/<\/?[a-zA-Z][^>]*>/', $strings[$key], $langMatches);
                $this->assertSame($enMatches[0], $langMatches[0], "HTML tag mismatch in $lang: $key");
            }
        }
    }
}
