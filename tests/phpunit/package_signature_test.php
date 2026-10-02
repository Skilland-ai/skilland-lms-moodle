<?php

namespace mod_skilland\tests;

use mod_skilland\admin_setting_signing_keys;
use mod_skilland\local\package_signature;
use PHPUnit\Framework\TestCase;

/**
 * package_signature with a key pinned in the plugin instead of the admin setting.
 */
class pinned_package_signature extends package_signature {
    /** @var array<string, string> Pinned keys for the test (key id => base64 public key). */
    public static $pinned = [];

    public static function trusted_keys(): array {
        $keys = parent::trusted_keys();
        foreach (self::$pinned as $keyid => $publickey) {
            $keys[$keyid] = base64_decode($publickey, true);
        }
        return $keys;
    }
}

/**
 * Ed25519 verification of topic SCORM packages and the signing keys setting (SKL-650).
 */
class package_signature_test extends TestCase {

    private const TOPIC = '3f2a9c1e-7b4d-4e8a-9c61-0d5b2f8e4a17';

    /** @var string[] Temp files removed in tearDown. */
    private $files = [];

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => new \stdClass()];
        pinned_package_signature::$pinned = [];
    }

    protected function tearDown(): void {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        $GLOBALS['_test_plugin_config'] = [];
        parent::tearDown();
    }

    private function zipbytes(): string {
        return base64_decode('UEsDBBQAAAAAAGC1OV3tN8mLCwAAAAsAAAAPAAAAaW1zbWFuaWZlc3QueG1sPG1hbmlmZXN0Lz5QSwECFAMUAAAAAABgtTld7TfJiwsAAAALAAAADwAAAAAAAAAAAAAAgAEAAAAAaW1zbWFuaWZlc3QueG1sUEsFBgAAAAABAAEAPQAAADgAAAAAAA==');
    }

    private function zip(?string $bytes = null): string {
        $path = tempnam(sys_get_temp_dir(), 'sklsig');
        file_put_contents($path, $bytes ?? $this->zipbytes());
        $this->files[] = $path;
        return $path;
    }

    /** A response signed by the (trusted) test key for self::TOPIC. */
    private function signed(array $overrides = [], string $topicid = self::TOPIC): array {
        return \test_package_signer::sign($topicid, $overrides + [
            'packageUrl' => 'https://cdn.skilland.ai/p.zip',
            'generatedAt' => '2026-09-01T10:00:00.000Z',
            'contentHash' => str_repeat('c', 64),
        ], $this->zipbytes());
    }

    private function expect_code(callable $fn, string $code): void {
        try {
            $fn();
        } catch (\moodle_exception $e) {
            $this->assertSame($code, $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
            return;
        }
        $this->fail('Expected moodle_exception ' . $code);
    }

    // ---------------------------------------------------------------
    // verify()
    // ---------------------------------------------------------------

    public function test_a_valid_signature_verifies(): void {
        package_signature::verify(self::TOPIC, $this->signed(), $this->zip());
        $this->addToAssertionCount(1);
    }

    public function test_the_requested_topic_id_is_lower_cased(): void {
        package_signature::verify(strtoupper(self::TOPIC), $this->signed(), $this->zip());
        $this->addToAssertionCount(1);
    }

    public function test_an_upper_case_package_hash_is_accepted(): void {
        $response = $this->signed();
        $response['packageHash'] = strtoupper($response['packageHash']);

        package_signature::verify(self::TOPIC, $response, $this->zip());
        $this->addToAssertionCount(1);
    }

    public function test_a_tampered_zip_is_a_hash_mismatch(): void {
        $bytes = $this->zipbytes();
        $bytes[40] = chr(ord($bytes[40]) ^ 0x01);

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $this->signed(), $this->zip($bytes)),
            'error_scorm_hash_mismatch');
    }

    public function test_a_tampered_zip_announced_with_its_own_hash_fails_the_signature(): void {
        $bytes = $this->zipbytes();
        $bytes[40] = chr(ord($bytes[40]) ^ 0x01);
        $response = $this->signed();
        $response['packageHash'] = hash('sha256', $bytes);

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip($bytes)),
            'error_scorm_signature_invalid');
    }

    public function test_an_empty_or_missing_package_hash_is_a_mismatch(): void {
        $response = $this->signed();
        $response['packageHash'] = '';
        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_hash_mismatch');

        unset($response['packageHash']);
        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_hash_mismatch');
    }

    public function test_a_replayed_signature_for_another_topic_is_refused(): void {
        $response = $this->signed([], 'topic-a');

        $this->expect_code(fn() => package_signature::verify('topic-b', $response, $this->zip()),
            'error_scorm_signature_invalid');
    }

    public function test_a_changed_content_hash_or_generated_at_is_refused(): void {
        $response = $this->signed();
        $response['contentHash'] = str_repeat('d', 64);
        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_invalid');

        $response = $this->signed();
        $response['generatedAt'] = '2026-09-01T10:00:00Z';
        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_invalid');
    }

    public function test_a_field_carrying_a_line_break_is_refused(): void {
        $response = $this->signed(['contentHash' => "c\nd"]);

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_invalid');
    }

    public function test_an_unknown_key_id_is_refused(): void {
        $response = $this->signed();
        $response['keyId'] = 'not-trusted';

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_unknown_key');
    }

    public function test_a_signature_by_another_key_under_a_trusted_id_is_refused(): void {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $response = \test_package_signer::sign(self::TOPIC, [
            'packageUrl' => 'https://cdn.skilland.ai/p.zip',
            'generatedAt' => '2026-09-01T10:00:00.000Z',
        ], $this->zipbytes(), $other);
        \test_package_signer::trust();

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_invalid');
    }

    public function test_a_malformed_key_id_is_refused(): void {
        $response = $this->signed();
        $response['keyId'] = 'bad key/id';

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_unknown_key');
    }

    public function test_a_key_that_is_neither_pinned_nor_in_the_setting_is_refused(): void {
        $response = $this->signed();
        $GLOBALS['_test_plugin_config']['mod_skilland']->signingkeys = '';
        $this->assertArrayNotHasKey(\test_package_signer::KEY_ID, package_signature::PINNED_KEYS);

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_unknown_key');
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function unsigned_provider(): array {
        return [
            'both null' => [null, null],
            'signature null' => [null, \test_package_signer::KEY_ID],
            'key id null' => ['c2ln', null],
            'signature empty' => ['', \test_package_signer::KEY_ID],
            'signature not a string' => [['x'], \test_package_signer::KEY_ID],
        ];
    }

    /**
     * @dataProvider unsigned_provider
     */
    public function test_a_missing_signature_or_key_id_is_refused($signature, $keyid): void {
        $response = $this->signed();
        $response['signature'] = $signature;
        $response['keyId'] = $keyid;

        $this->expect_code(fn() => package_signature::require_signed($response), 'error_scorm_signature_missing');
        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_missing');
    }

    public function test_absent_signature_fields_are_refused(): void {
        $response = $this->signed();
        unset($response['signature'], $response['keyId']);

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_missing');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformed_signature_provider(): array {
        return [
            'not base64' => ['***not base64***'],
            'too short' => [base64_encode(str_repeat("\x01", 63))],
            'too long' => [base64_encode(str_repeat("\x01", 65))],
            'whitespace inside' => ['c2ln c2ln'],
        ];
    }

    /**
     * @dataProvider malformed_signature_provider
     */
    public function test_a_malformed_signature_is_refused(string $signature): void {
        $response = $this->signed();
        $response['signature'] = $signature;

        $this->expect_code(fn() => package_signature::verify(self::TOPIC, $response, $this->zip()),
            'error_scorm_signature_malformed');
    }

    public function test_a_pinned_key_verifies_without_the_admin_setting(): void {
        $response = $this->signed();
        $GLOBALS['_test_plugin_config']['mod_skilland']->signingkeys = '';
        pinned_package_signature::$pinned = [\test_package_signer::KEY_ID =>
            base64_encode(sodium_crypto_sign_publickey(\test_package_signer::keypair()))];

        pinned_package_signature::verify(self::TOPIC, $response, $this->zip());
        $this->addToAssertionCount(1);
    }

    public function test_the_shipped_pinned_keys_are_well_formed(): void {
        foreach (package_signature::PINNED_KEYS as $keyid => $publickey) {
            $this->assertMatchesRegularExpression(package_signature::KEY_ID_PATTERN, (string) $keyid);
            $this->assertNotNull(package_signature::decode_public_key($publickey), "Pinned key $keyid is not 32 bytes");
        }
        $this->addToAssertionCount(1);
    }

    public function test_the_production_key_2026_09_is_pinned_and_trusted_without_the_setting(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland']->signingkeys = '';

        $keys = package_signature::trusted_keys();

        $this->assertArrayHasKey('2026-09', $keys);
        $this->assertSame(base64_decode('KkupKiNBdlqIAAAJOnPb+qNtLQCopb0o8xE437GiC9Q=', true), $keys['2026-09']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($keys['2026-09']));
    }

    public function test_an_admin_line_cannot_replace_the_pinned_2026_09_key(): void {
        \test_package_signer::trust('2026-09:' . base64_encode(sodium_crypto_sign_publickey(\test_package_signer::keypair())));

        $this->assertSame(base64_decode(package_signature::PINNED_KEYS['2026-09'], true),
            package_signature::trusted_keys()['2026-09']);
    }

    public function test_pinned_and_admin_keys_are_both_trusted(): void {
        $pinned = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
        pinned_package_signature::$pinned = ['pinned-1' => base64_encode($pinned)];
        \test_package_signer::trust();

        $keys = pinned_package_signature::trusted_keys();

        $this->assertSame($pinned, $keys['pinned-1']);
        $this->assertSame(sodium_crypto_sign_publickey(\test_package_signer::keypair()),
            $keys[\test_package_signer::KEY_ID]);
    }

    // ---------------------------------------------------------------
    // Cross-language vector from the Skilland backend
    // ---------------------------------------------------------------

    public function test_backend_signature_vector_verifies(): void {
        // A fake fixed seed shared with the backend's tests, not a real key.
        $seed = hex2bin('0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20');
        $publickey = 'ebVWLo/mVPlAeLES6KmLp5AfhTrmlb7X4OORC60ElmQ=';
        $expected = "skilland-scorm-package-v1\n3f2a9c1e-7b4d-4e8a-9c61-0d5b2f8e4a17\n" .
            str_repeat('a', 64) . "\n" . str_repeat('b', 64) . "\n2026-09-01T10:00:00.000Z";
        $signature = '3k+GX4MyfX1CM+rtGXbMRj53IiDEaR5cCc7ZldruJgP+RWCrucHpVcaseUS74AGjNLS+hGmr7vzQdVxJvezqBA==';

        $this->assertSame($publickey, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed))));
        $message = package_signature::message('3F2A9C1E-7B4D-4E8A-9C61-0D5B2F8E4A17', str_repeat('a', 64),
            str_repeat('b', 64), '2026-09-01T10:00:00.000Z');
        $this->assertSame($expected, $message);

        // The key goes through the admin setting's parser, as a site would add it.
        $keys = package_signature::parse_keys("backend-vector:$publickey");
        $this->assertSame([], $keys['errors']);
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($signature, true), $message,
            $keys['keys']['backend-vector']));
        $this->assertFalse(sodium_crypto_sign_verify_detached(base64_decode($signature, true), $message . 'x',
            $keys['keys']['backend-vector']));
    }

    // ---------------------------------------------------------------
    // parse_keys() and the admin setting
    // ---------------------------------------------------------------

    public function test_parse_keys_reads_valid_lines_and_skips_blanks_and_comments(): void {
        $a = str_repeat("\x01", 32);
        $b = str_repeat("\x02", 32);
        $text = "# rotation, September\r\n\nkey-2026.09:" . base64_encode($a) . "\n  old_key : " . base64_encode($b) . "  \n";

        $parsed = package_signature::parse_keys($text);

        $this->assertSame([], $parsed['errors']);
        $this->assertSame(['key-2026.09' => $a, 'old_key' => $b], $parsed['keys']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalid_key_line_provider(): array {
        $good = base64_encode(str_repeat("\x01", 32));
        return [
            'no colon' => ['justakey'],
            'empty key id' => [':' . $good],
            'key id with a space' => ['bad id:' . $good],
            'key id too long' => [str_repeat('k', 65) . ':' . $good],
            'not base64' => ['k1:***'],
            '31 bytes' => ['k1:' . base64_encode(str_repeat("\x01", 31))],
            '33 bytes' => ['k1:' . base64_encode(str_repeat("\x01", 33))],
        ];
    }

    /**
     * @dataProvider invalid_key_line_provider
     */
    public function test_parse_keys_rejects_invalid_lines(string $line): void {
        $good = 'k0:' . base64_encode(str_repeat("\x01", 32));

        $parsed = package_signature::parse_keys($good . "\n" . $line);

        $this->assertSame([2], $parsed['errors']);
        $this->assertSame(['k0'], array_keys($parsed['keys']));
    }

    public function test_parse_keys_rejects_a_repeated_key_id(): void {
        $line = 'k1:' . base64_encode(str_repeat("\x01", 32));

        $this->assertSame([2], package_signature::parse_keys($line . "\n" . $line)['errors']);
    }

    public function test_the_admin_setting_accepts_valid_keys_and_an_empty_value(): void {
        $setting = new admin_setting_signing_keys('mod_skilland/signingkeys', 'Keys', 'desc', '');

        $this->assertTrue($setting->validate(''));
        $this->assertTrue($setting->validate(\test_package_signer::key_line()));
    }

    public function test_the_admin_setting_rejects_a_value_with_an_invalid_line(): void {
        $setting = new admin_setting_signing_keys('mod_skilland/signingkeys', 'Keys', 'desc', '');

        $result = $setting->validate(\test_package_signer::key_line() . "\nk2:" . base64_encode('short'));

        $this->assertSame('error_signing_keys_invalid', $result);
    }

    public function test_settings_register_the_signing_keys_textarea(): void {
        $source = file_get_contents(__DIR__ . '/../../src/settings.php');
        $this->assertMatchesRegularExpression(
            "/new \\\\mod_skilland\\\\admin_setting_signing_keys\\(\\s*'mod_skilland\\/signingkeys'/",
            $source
        );
    }
}
