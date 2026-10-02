<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * skilland_parse_timestamp() never hands an unparseable date to an int column (SKL-684).
 */
class parse_timestamp_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
    }

    public function test_ints_and_numeric_strings(): void {
        $this->assertSame(1767261600, skilland_parse_timestamp(1767261600));
        $this->assertSame(1767261600, skilland_parse_timestamp('1767261600'));
        $this->assertSame(1767261600, skilland_parse_timestamp(1767261600.7));
    }

    public function test_iso_with_fractional_seconds(): void {
        $this->assertSame(strtotime('2026-01-01T10:00:00Z'), skilland_parse_timestamp('2026-01-01T10:00:00.123Z'));
        $this->assertSame(strtotime('2026-01-01T10:00:00Z'), skilland_parse_timestamp('2026-01-01T10:00:00Z'));
    }

    public function test_empty_input_is_zero(): void {
        $this->assertSame(0, skilland_parse_timestamp(''));
        $this->assertSame(0, skilland_parse_timestamp('  '));
        $this->assertSame(0, skilland_parse_timestamp(null));
        $this->assertSame(0, skilland_parse_timestamp([]));
    }

    public function test_garbage_is_zero_and_the_value_is_never_logged(): void {
        $this->assertSame(0, skilland_parse_timestamp('not a date'));

        $logged = json_encode($GLOBALS['_test_debug_messages']);
        $this->assertStringNotContainsString('not a date', $logged);
    }
}
