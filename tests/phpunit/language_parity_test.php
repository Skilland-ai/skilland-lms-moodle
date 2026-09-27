<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Dedicated language-parity guard for lang/en/skilland.php and lang/es/skilland.php (SKL-671).
 *
 * The full Moodle get_string_manager() is not available in this standalone PHPUnit harness
 * (see tests/phpunit/bootstrap.php: get_string() is a stub that just echoes back the
 * identifier), so parity is checked by parsing both lang files directly, the same technique
 * integrity_test.php already uses for its own EN/ES parity checks. This file exists
 * specifically so a lang-key change is caught even if integrity_test.php's broader checks
 * are ever split apart or skipped.
 */
class language_parity_test extends TestCase {

    private static string $srcDir;
    private static array $enStrings = [];
    private static array $esStrings = [];

    public static function setUpBeforeClass(): void {
        self::$srcDir = realpath(__DIR__ . '/../../src');

        $string = [];
        require self::$srcDir . '/lang/en/skilland.php';
        self::$enStrings = $string;

        $string = [];
        require self::$srcDir . '/lang/es/skilland.php';
        self::$esStrings = $string;
    }

    public function test_en_and_es_key_sets_are_identical(): void {
        $enKeys = array_keys(self::$enStrings);
        $esKeys = array_keys(self::$esStrings);

        sort($enKeys);
        sort($esKeys);

        $this->assertSame(
            $enKeys,
            $esKeys,
            "EN and ES lang files must define exactly the same set of keys.\n" .
            'Missing in ES: ' . implode(', ', array_diff($enKeys, $esKeys)) . "\n" .
            'Missing in EN: ' . implode(', ', array_diff($esKeys, $enKeys))
        );
    }

    public function test_en_and_es_key_counts_match(): void {
        $this->assertSame(
            count(self::$enStrings),
            count(self::$esStrings),
            'EN and ES lang files must define the same number of string keys'
        );
    }

    public function test_no_duplicate_keys_in_en(): void {
        // require executes top-to-bottom into the same $string array, so a duplicate
        // key definition silently overwrites the earlier one instead of raising an error.
        // Parse the source text itself to catch that.
        $source = file_get_contents(self::$srcDir . '/lang/en/skilland.php');
        preg_match_all("/\\\$string\\['([a-zA-Z0-9_:]+)'\\]\\s*=/", $source, $matches);
        $keys = $matches[1];

        $duplicates = array_keys(array_filter(array_count_values($keys), fn($count) => $count > 1));

        $this->assertEmpty($duplicates, 'Duplicate keys in EN lang file: ' . implode(', ', $duplicates));
    }

    public function test_no_duplicate_keys_in_es(): void {
        $source = file_get_contents(self::$srcDir . '/lang/es/skilland.php');
        preg_match_all("/\\\$string\\['([a-zA-Z0-9_:]+)'\\]\\s*=/", $source, $matches);
        $keys = $matches[1];

        $duplicates = array_keys(array_filter(array_count_values($keys), fn($count) => $count > 1));

        $this->assertEmpty($duplicates, 'Duplicate keys in ES lang file: ' . implode(', ', $duplicates));
    }

    public function test_no_empty_values_in_either_language(): void {
        foreach (['en' => self::$enStrings, 'es' => self::$esStrings] as $lang => $strings) {
            $empty = [];
            foreach ($strings as $key => $value) {
                if (trim((string) $value) === '') {
                    $empty[] = $key;
                }
            }
            $this->assertEmpty($empty, "$lang lang file has empty string values: " . implode(', ', $empty));
        }
    }
}
