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

    public function test_production_blocklist_holds_sha256_digests(): void {
        $this->assertNotEmpty(admin_setting_sso_secret::KNOWN_DEV_SECRET_HASHES);
        foreach (admin_setting_sso_secret::KNOWN_DEV_SECRET_HASHES as $hash) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        }
    }
}
