<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\admin_setting_sso_secret;

/**
 * Test double whose blocklist holds a fixture digest instead of the real one.
 */
class testable_admin_setting_sso_secret extends admin_setting_sso_secret {
    /** @var string[] */
    public static $hashes = [];

    protected function known_dev_secret_hashes(): array {
        return self::$hashes;
    }
}

/**
 * Unit tests for mod_skilland\admin_setting_sso_secret.
 */
class admin_setting_sso_secret_test extends TestCase {

    private const FIXTURE_SECRET = 'fixture-known-secret-not-a-real-one-0123456789';

    private function setting(): admin_setting_sso_secret {
        return new testable_admin_setting_sso_secret('mod_skilland/sso_secret', 'SSO', 'desc', '');
    }

    protected function setUp(): void {
        parent::setUp();
        testable_admin_setting_sso_secret::$hashes = [hash('sha256', self::FIXTURE_SECRET)];
    }

    public function test_accepts_empty_value(): void {
        $this->assertTrue($this->setting()->validate(''));
    }

    public function test_accepts_44_char_base64_secret(): void {
        $secret = base64_encode(str_repeat("\x5a", 32));
        $this->assertSame(44, strlen($secret));
        $this->assertTrue($this->setting()->validate($secret));
    }

    public function test_accepts_long_non_base64_secret(): void {
        $this->assertTrue($this->setting()->validate(str_repeat('x!', 20)));
    }

    public function test_rejects_20_char_secret(): void {
        $this->assertSame('error_sso_secret_too_short', $this->setting()->validate(str_repeat('a', 20)));
    }

    public function test_rejects_base64_secret_decoding_to_fewer_than_32_bytes(): void {
        // 40 base64 chars decode to 30 bytes.
        $secret = base64_encode(str_repeat("\x01", 30));
        $this->assertSame(40, strlen($secret));
        $this->assertSame('error_sso_secret_too_short', $this->setting()->validate($secret));
    }

    public function test_rejects_known_dev_secret(): void {
        $this->assertSame('error_sso_secret_known_dev', $this->setting()->validate(self::FIXTURE_SECRET));
    }

    public function test_accepts_exactly_32_raw_bytes(): void {
        // '!' is outside the base64 alphabet, so the value is measured as raw bytes.
        $secret = str_repeat('a!', 16);
        $this->assertSame(32, strlen($secret));
        $this->assertTrue($this->setting()->validate($secret));
    }

    public function test_rejects_31_raw_bytes(): void {
        $secret = str_repeat('a!', 15) . 'a';
        $this->assertSame(31, strlen($secret));
        $this->assertSame('error_sso_secret_too_short', $this->setting()->validate($secret));
    }

    public function test_rejects_44_char_base64_secret_of_31_bytes(): void {
        $secret = base64_encode(str_repeat("\x5a", 31));
        $this->assertSame(44, strlen($secret));
        $this->assertSame('error_sso_secret_too_short', $this->setting()->validate($secret));
    }

    public function test_measures_invalid_base64_of_at_least_32_chars_as_raw_bytes(): void {
        // Base64 of 30 bytes would be too short, but one '*' makes it invalid base64,
        // so the 41 characters count as raw bytes.
        $secret = base64_encode(str_repeat("\x01", 30)) . '*';
        $this->assertFalse(base64_decode($secret, true));
        $this->assertSame(41, strlen($secret));
        $this->assertTrue($this->setting()->validate($secret));
    }

    public function test_rejects_32_hex_chars_as_16_bytes_of_entropy(): void {
        // 32 hex chars are valid base64 and decode to 24 bytes, below the minimum.
        $this->assertSame('error_sso_secret_too_short', $this->setting()->validate(bin2hex(str_repeat("\xab", 16))));
    }

    public function test_accepts_64_hex_char_organization_secret(): void {
        // SKL-647: SkilLand hands out hex(HMAC-SHA256(master, 'skilland:moodle-sso:v1:' . orgId)).
        // 64 hex chars are valid base64 and decode to 48 bytes, above the minimum.
        $secret = hash_hmac('sha256', 'skilland:moodle-sso:v1:' . 'org-fixture', str_repeat('m', 32));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertTrue($this->setting()->validate($secret));
    }

    public function test_rejects_blocklisted_base64_secret_of_32_bytes(): void {
        $secret = base64_encode(str_repeat("\x7f", 32));
        testable_admin_setting_sso_secret::$hashes = [hash('sha256', $secret)];
        $this->assertSame('error_sso_secret_known_dev', $this->setting()->validate($secret));
    }

    public function test_blocklist_matches_exact_value_only(): void {
        $this->assertTrue($this->setting()->validate(self::FIXTURE_SECRET . 'x'));
    }

    public function test_production_blocklist_does_not_hold_fixture(): void {
        $this->assertNotContains(hash('sha256', self::FIXTURE_SECRET), admin_setting_sso_secret::KNOWN_DEV_SECRET_HASHES);
    }

    public function test_error_strings_are_defined_in_every_language(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include(__DIR__ . '/../../src/lang/' . $lang . '/skilland.php');
            foreach (['error_sso_secret_too_short', 'error_sso_secret_known_dev'] as $key) {
                $this->assertArrayHasKey($key, $string, "$lang is missing $key");
                $this->assertStringContainsString('32', $string[$key]);
            }
        }
    }

    public function test_production_blocklist_holds_sha256_digests(): void {
        $this->assertNotEmpty(admin_setting_sso_secret::KNOWN_DEV_SECRET_HASHES);
        foreach (admin_setting_sso_secret::KNOWN_DEV_SECRET_HASHES as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }
}
