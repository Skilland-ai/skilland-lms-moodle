<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class locallib_sso_token_test extends TestCase {

    private $ssoSecret = 'test-secret-key-for-jwt-signing';

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_enrolled_courses'] = [];
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_enrolled_courses']);
        parent::tearDown();
    }

    private function makeUser(array $overrides = []): \stdClass {
        return (object)array_merge([
            'id' => 1,
            'email' => 'teacher@school.com',
            'firstname' => 'Jane',
            'lastname' => 'Doe',
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — validation
    // ---------------------------------------------------------------

    public function test_generate_token_throws_without_sso_secret(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => ''];

        try {
            skilland_generate_sso_token($this->makeUser(), 'org1');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('SSO Shared Secret', $e->debuginfo);
        }
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — payload construction
    // ---------------------------------------------------------------

    public function test_generate_token_returns_valid_jwt(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $token = skilland_generate_sso_token($this->makeUser(), 'org1');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Decode and verify.
        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEquals('teacher@school.com', $decoded->email);
        $this->assertEquals('Jane Doe', $decoded->name);
        $this->assertEquals('org1', $decoded->orgId);
        $this->assertEquals('Expert', $decoded->role);
        $this->assertEquals('moodle', $decoded->source);
    }

    public function test_generate_token_includes_iat_and_nonce(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $before = time();
        $token = skilland_generate_sso_token($this->makeUser(), 'org1');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertGreaterThanOrEqual($before, $decoded->iat);
        $this->assertNotEmpty($decoded->nonce);
        $this->assertEquals(32, strlen($decoded->nonce)); // 16 bytes = 32 hex chars
    }

    public function test_generate_token_each_call_has_unique_nonce(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $user = $this->makeUser();
        $token1 = skilland_generate_sso_token($user, 'org1');
        $token2 = skilland_generate_sso_token($user, 'org1');

        $decoded1 = JWT::decode($token1, new Key($this->ssoSecret, 'HS256'));
        $decoded2 = JWT::decode($token2, new Key($this->ssoSecret, 'HS256'));

        $this->assertNotEquals($decoded1->nonce, $decoded2->nonce);
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — course access
    // ---------------------------------------------------------------

    public function test_generate_token_empty_course_access_when_no_enrollments(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];
        $GLOBALS['_test_enrolled_courses'] = [];

        $token = skilland_generate_sso_token($this->makeUser(), 'org1');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEmpty($decoded->courseAccess);
    }

    public function test_generate_token_preserves_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $token = skilland_generate_sso_token($this->makeUser(), 'my-org-42');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEquals('my-org-42', $decoded->orgId);
    }

    public function test_generate_token_uses_fullname(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $user = $this->makeUser(['firstname' => 'María', 'lastname' => 'García']);
        $token = skilland_generate_sso_token($user, 'org1');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEquals('María García', $decoded->name);
    }

    public function test_generate_token_debug_log_carries_user_id_not_email(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
            'devmode' => true,
        ];

        skilland_generate_sso_token($this->makeUser(['id' => 42]), 'org1');

        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('Generated token for user id 42', $messages);
        $this->assertStringNotContainsString('teacher@school.com', $messages);
    }

    public function test_redact_url_strips_query_and_fragment(): void {
        $this->assertSame(
            'https://bucket.s3.amazonaws.com/pkg.zip?[redacted]',
            mod_skilland_redact_url('https://bucket.s3.amazonaws.com/pkg.zip?X-Amz-Signature=abc#frag')
        );
        $this->assertSame('https://example.com/pkg.zip', mod_skilland_redact_url('https://example.com/pkg.zip'));
    }
}
