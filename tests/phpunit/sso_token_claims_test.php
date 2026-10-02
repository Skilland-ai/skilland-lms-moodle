<?php

namespace mod_skilland\tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * SKL-687: the SSO token is short-lived, bound to the Skilland origin and to this Moodle, and only
 * ever leaves Moodle in the body of a POST.
 */
class sso_token_claims_test extends TestCase {

    private const FIXTURE_SECRET = 'fixture-sso-signing-secret-not-a-real-one-0123456789';

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_enrolled_courses'] = [];
        \mod_skilland\logger::reset_cache();
        // SKL-647: the token is only minted for an account the database says may sign in.
        $GLOBALS['DB']->seed('user', [
            (object) ['id' => 7, 'auth' => 'manual', 'confirmed' => 1, 'deleted' => 0, 'suspended' => 0],
        ]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_enrolled_courses']);
        $GLOBALS['DB']->seed('user', []);
        parent::tearDown();
    }

    private function mint(array $config): \stdClass {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) array_merge(['sso_secret' => self::FIXTURE_SECRET], $config);
        $user = (object) ['id' => 7, 'email' => 'teacher@school.com', 'firstname' => 'Jane', 'lastname' => 'Doe'];
        $token = skilland_generate_sso_token($user, 'org-9', 'Expert');
        return JWT::decode($token, new Key(self::FIXTURE_SECRET, 'HS256'));
    }

    public function test_token_expires_sixty_seconds_after_issue(): void {
        $claims = $this->mint(['frontend_url' => 'https://app.example.com/']);

        $this->assertIsInt($claims->iat);
        $this->assertIsInt($claims->exp);
        $this->assertSame(60, $claims->exp - $claims->iat);
    }

    public function test_audience_is_frontend_origin(): void {
        $claims = $this->mint(['frontend_url' => 'https://app.example.com/']);

        $this->assertSame('https://app.example.com', $claims->aud);
    }

    public function test_audience_from_the_skilland_url(): void {
        $claims = $this->mint(['frontend_url' => '', 'graphql_endpoint' => 'https://app.example.com/graphql']);

        $this->assertSame('https://app.example.com', $claims->aud);
    }

    public function test_issuer_is_moodle_wwwroot(): void {
        global $CFG;

        $claims = $this->mint(['frontend_url' => 'https://app.example.com/']);

        $this->assertSame($CFG->wwwroot, $claims->iss);
    }

    public function test_subject_is_the_moodle_user_id_as_a_string(): void {
        $claims = $this->mint(['frontend_url' => 'https://app.example.com/']);

        $this->assertIsString($claims->sub);
        $this->assertSame('7', $claims->sub);
    }

    public function test_existing_claims_unchanged(): void {
        $GLOBALS['_test_enrolled_courses'] = [];
        $claims = $this->mint(['frontend_url' => 'https://app.example.com/']);

        $this->assertSame('7', $claims->sub);
        $this->assertSame('teacher@school.com', $claims->email);
        $this->assertSame('Jane Doe', $claims->name);
        $this->assertSame('org-9', $claims->orgId);
        $this->assertSame('Expert', $claims->role);
        $this->assertSame('moodle', $claims->source);
        $this->assertSame(32, strlen($claims->nonce));
        $this->assertSame([], $claims->courseAccess);
        $this->assertEqualsCanonicalizing(
            ['sub', 'email', 'name', 'orgId', 'role', 'nonce', 'iat', 'exp', 'aud', 'iss', 'source', 'courseAccess'],
            array_keys((array) $claims)
        );
    }

    // ---------------------------------------------------------------
    // Source guards
    // ---------------------------------------------------------------

    /** @return array<string, string> path relative to src/ => contents, excluding bundled vendor code */
    private function sourceFiles(): array {
        $src = realpath(__DIR__ . '/../../src');
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($src) + 1);
            if (strpos($relative, 'vendor/') === 0 || !preg_match('/\.(php|js|mustache)$/', $relative)) {
                continue;
            }
            $files[$relative] = file_get_contents($path);
        }
        $this->assertArrayHasKey('locallib.php', $files);
        return $files;
    }

    public function test_no_token_query_string_in_src(): void {
        foreach ($this->sourceFiles() as $relative => $source) {
            $this->assertStringNotContainsString('?token=', $source, "$relative builds a ?token= URL");
            $this->assertStringNotContainsString('&token=', $source, "$relative builds a &token= URL");
        }
    }

    public function test_no_token_in_http_build_query(): void {
        foreach ($this->sourceFiles() as $relative => $source) {
            preg_match_all('/http_build_query\(\s*\[(.*?)\]\s*\)/s', $source, $m);
            foreach ($m[1] as $args) {
                $this->assertDoesNotMatchRegularExpression("/['\"]token['\"]\s*=>/", $args,
                    "$relative passes the token to http_build_query()");
            }
        }
    }

    public function test_token_is_minted_only_by_sso_redirect(): void {
        $callers = [];
        foreach ($this->sourceFiles() as $relative => $source) {
            if (strpos($relative, 'tests/') === 0) {
                // The real-Moodle PHPUnit suite (src/tests/, never shipped) calls it to test refusals.
                continue;
            }
            $source = preg_replace('/function\s+skilland_generate_sso_token\s*\(/', '', $source);
            if (strpos($source, 'skilland_generate_sso_token(') !== false) {
                $callers[] = $relative;
            }
        }
        $this->assertSame(['sso_redirect.php'], $callers);
    }

    public function test_sso_redirect_posts_instead_of_redirecting(): void {
        $source = file_get_contents(__DIR__ . '/../../src/sso_redirect.php');

        $this->assertStringNotContainsString('redirect($', $source);
        $this->assertStringContainsString('echo skilland_render_sso_post_form($token, $redirect);', $source);
        $this->assertStringContainsString("header('Cache-Control: no-store');", $source);
        $this->assertStringContainsString("header('Referrer-Policy: no-referrer');", $source);
        $this->assertStringContainsString("header('Content-Type: text/html; charset=utf-8');", $source);
        $this->assertLessThan(strpos($source, 'echo skilland_render_sso_post_form'),
            strpos($source, "header('Cache-Control: no-store');"));
    }
}
