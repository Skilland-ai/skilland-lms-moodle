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
        // The token is only minted for an account the database says may sign in.
        $this->seedAccounts([$this->account(1), $this->account(42)]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_enrolled_courses']);
        unset($GLOBALS['CFG']->siteguest);
        $GLOBALS['DB']->seed('user', []);
        parent::tearDown();
    }

    /** An active, confirmed manual account as the user table holds it. */
    private function account(int $id, array $overrides = []): \stdClass {
        return (object) array_merge([
            'id' => $id,
            'username' => 'user' . $id,
            'auth' => 'manual',
            'confirmed' => 1,
            'deleted' => 0,
            'suspended' => 0,
        ], $overrides);
    }

    private function seedAccounts(array $accounts): void {
        $GLOBALS['DB']->seed('user', $accounts);
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
            skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('SSO Shared Secret', $e->debuginfo);
        }
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — accounts that may not sign in (SKL-647)
    // ---------------------------------------------------------------

    /** @return array<string, array{0: array, 1: string}> account overrides => logged reason */
    public static function refused_accounts(): array {
        return [
            'suspended' => [['suspended' => 1], 'suspended'],
            'deleted' => [['deleted' => 1], 'deleted'],
            'unconfirmed' => [['confirmed' => 0], 'unconfirmed'],
            'nologin' => [['auth' => 'nologin'], 'nologin'],
        ];
    }

    /**
     * @dataProvider refused_accounts
     */
    public function test_generate_token_refuses_account_that_may_not_sign_in(array $overrides, string $reason): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
            'devmode' => true,
        ];
        $this->seedAccounts([$this->account(1, $overrides)]);

        try {
            skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
            $this->assertSame('mod_skilland', $e->module);
        }

        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringContainsString('Refused token for user id 1: ' . $reason, $messages);
        $this->assertStringNotContainsString('teacher@school.com', $messages);
        $this->assertStringNotContainsString('Generated token', $messages);
    }

    public function test_generate_token_refuses_guest_user(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => $this->ssoSecret];
        $GLOBALS['CFG']->siteguest = 2;
        $this->seedAccounts([$this->account(2, ['username' => 'guest'])]);

        try {
            skilland_generate_sso_token($this->makeUser(['id' => 2, 'email' => 'root@localhost']), 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
        }
    }

    public function test_generate_token_refuses_account_missing_from_database(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => $this->ssoSecret];

        try {
            skilland_generate_sso_token($this->makeUser(['id' => 99]), 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
        }
    }

    public function test_generate_token_refuses_user_id_zero(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => $this->ssoSecret];
        $this->seedAccounts([$this->account(0)]);

        try {
            skilland_generate_sso_token($this->makeUser(['id' => 0]), 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
        }
    }

    public function test_generate_token_reads_account_state_fresh_from_database(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => $this->ssoSecret];
        // The session copy still says active; the account was suspended after sign-in.
        $sessionuser = $this->makeUser(['auth' => 'manual', 'confirmed' => 1, 'deleted' => 0, 'suspended' => 0]);
        $this->seedAccounts([$this->account(1, ['suspended' => 1])]);

        try {
            skilland_generate_sso_token($sessionuser, 'org1', 'Expert');
            $this->fail('Expected exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
        }

        $reads = array_values(array_filter($GLOBALS['DB']->get_calls_for('get_record'),
            fn($call) => $call['table'] === 'user'));
        $this->assertNotEmpty($reads);
        $this->assertSame(['id' => 1], $reads[count($reads) - 1]['conditions']);
    }

    public function test_generate_token_ignores_stale_session_flags_of_an_active_account(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)['sso_secret' => $this->ssoSecret];
        // A session copy flagged suspended does not refuse an account the database says is active.
        $token = skilland_generate_sso_token($this->makeUser(['suspended' => 1]), 'org1', 'Expert');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertSame('1', $decoded->sub);
    }

    public function test_refused_account_error_string_exists_in_every_language(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            include(__DIR__ . '/../../src/lang/' . $lang . '/skilland.php');
            $this->assertArrayHasKey('error_sso_user_not_allowed', $string, "$lang is missing error_sso_user_not_allowed");
            $this->assertStringContainsString('Skilland', $string['error_sso_user_not_allowed']);
        }
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — payload construction
    // ---------------------------------------------------------------

    public function test_generate_token_subject_is_the_moodle_user_id_as_a_string(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $token = skilland_generate_sso_token($this->makeUser(['id' => 42]), 'org1', 'Expert');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertSame('42', $decoded->sub);
        // A JSON string, not a number: Skilland compares it as text.
        [, $body] = explode('.', $token);
        $this->assertStringContainsString('"sub":"42"', JWT::urlsafeB64Decode($body));
    }

    public function test_generate_token_returns_valid_jwt(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $token = skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');

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
        $token = skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');

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
        $token1 = skilland_generate_sso_token($user, 'org1', 'Expert');
        $token2 = skilland_generate_sso_token($user, 'org1', 'Expert');

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

        $token = skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEmpty($decoded->courseAccess);
    }

    public function test_generate_token_course_access_lists_only_teaching_courses(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];
        $GLOBALS['_test_enrolled_courses'] = [
            10 => (object)['id' => 10],
            20 => (object)['id' => 20],
        ];
        $GLOBALS['_test_customfield_value'] = [10 => 'skill-teach', 20 => 'skill-study'];
        // The user teaches course 10 and is only a student in course 20.
        $GLOBALS['_test_capability_course_ids'] = [10];

        try {
            $token = skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');
        } finally {
            unset($GLOBALS['_test_customfield_value'], $GLOBALS['_test_capability_course_ids']);
        }

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertCount(1, $decoded->courseAccess);
        $this->assertSame(10, $decoded->courseAccess[0]->moodleCourseId);
        $this->assertSame('skill-teach', $decoded->courseAccess[0]->skillandSkillId);
    }

    public function test_generate_token_course_access_is_empty_for_student_only_enrolments(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];
        $GLOBALS['_test_enrolled_courses'] = [20 => (object)['id' => 20]];
        $GLOBALS['_test_customfield_value'] = [20 => 'skill-study'];
        $GLOBALS['_test_capability_course_ids'] = [];

        try {
            $token = skilland_generate_sso_token($this->makeUser(), 'org1', 'Learner');
        } finally {
            unset($GLOBALS['_test_customfield_value'], $GLOBALS['_test_capability_course_ids']);
        }

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertSame([], $decoded->courseAccess);
    }

    public function test_generate_token_preserves_orgid(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $token = skilland_generate_sso_token($this->makeUser(), 'my-org-42', 'Expert');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEquals('my-org-42', $decoded->orgId);
    }

    public function test_generate_token_uses_fullname(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
        ];

        $user = $this->makeUser(['firstname' => 'María', 'lastname' => 'García']);
        $token = skilland_generate_sso_token($user, 'org1', 'Expert');

        $decoded = JWT::decode($token, new Key($this->ssoSecret, 'HS256'));
        $this->assertEquals('María García', $decoded->name);
    }

    public function test_generate_token_debug_log_carries_user_id_not_email(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
            'devmode' => true,
        ];

        skilland_generate_sso_token($this->makeUser(['id' => 42]), 'org1', 'Expert');

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

    public function test_generate_token_debug_log_never_carries_the_secret(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'sso_secret' => $this->ssoSecret,
            'devmode' => true,
        ];

        skilland_generate_sso_token($this->makeUser(), 'org1', 'Expert');

        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringNotContainsString($this->ssoSecret, $messages);
    }

    public function test_redact_url_strips_fragment_only_url(): void {
        $this->assertSame('https://example.com/pkg.zip?[redacted]', mod_skilland_redact_url('https://example.com/pkg.zip#token=abc'));
    }
}
