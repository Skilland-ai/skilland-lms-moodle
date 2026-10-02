<?php

namespace mod_skilland\tests;

use mod_skilland\local\connection_check;
use mod_skilland\rest_exception;
use PHPUnit\Framework\TestCase;

/**
 * Test connection (SKL-992): the request the plugin sends to POST /api/moodle/connection-check
 * and the one-line results it shows for each answer.
 *
 * get_string() is stubbed to return the identifier, so results are asserted by identifier.
 */
class connection_check_test extends TestCase {

    /** Fixed SSO proof vector, shared with the web route's test. */
    private const VECTOR_SECRET = 'connection-check-test-secret-0123456789abcdef';
    private const VECTOR_ORG = '9F1C2E3D-4B5A-4C6D-8E7F-0A1B2C3D4E5F';
    private const VECTOR_TS = 1759370000;
    private const VECTOR_NONCE = 'abcdef0123456789abcdef0123456789';
    private const VECTOR_SIGNATURE = '7cd8ed26aee05108f314652d611670d527f5d4f66384e51f70cde99ba2de1ddc';

    /** @var \fake_api_client */
    private $client;

    protected function setUp(): void {
        parent::setUp();
        \core\di::reset_container();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'graphql_endpoint' => 'https://skilland.university.example',
            'frontend_url' => '',
            'apikey' => 'key1',
            'orgid' => ' Org-1 ',
            'sso_secret' => 'a-sso-secret-that-is-long-enough-0123456789',
        ];
        \mod_skilland\logger::reset_cache();
        $this->client = new \fake_api_client(new \mod_skilland\tests\no_network_api_client());
        \core\di::set(\mod_skilland\local\api_client::class, $this->client);
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        $GLOBALS['_test_plugin_config'] = [];
        parent::tearDown();
    }

    private function config(array $values): void {
        foreach ($values as $key => $value) {
            $GLOBALS['_test_plugin_config']['mod_skilland']->$key = $value;
        }
    }

    private static function answer(string $orgcheck = 'ok', string $ssocheck = 'ok'): array {
        return [
            'ok' => $orgcheck === 'ok' && $ssocheck === 'ok',
            'organization' => ['id' => 'org-1', 'name' => 'University'],
            'checks' => ['organizationId' => $orgcheck, 'ssoSecret' => $ssocheck],
        ];
    }

    /**
     * @param array $results
     * @return array<string, array{0: string, 1: string}> check => [status, message identifier]
     */
    private static function by_check(array $results): array {
        $map = [];
        foreach ($results as $result) {
            $map[$result['check']] = [$result['status'], $result['message']];
        }
        return $map;
    }

    private function run_with(array|\Throwable $response): array {
        $this->client->respond_post('connection-check', $response);
        return (new connection_check())->run();
    }

    /** The proof signature matches the fixed vector shared with the web route. */
    public function test_signature_matches_the_known_vector(): void {
        $this->assertSame(
            self::VECTOR_SIGNATURE,
            connection_check::signature(self::VECTOR_SECRET, '  ' . self::VECTOR_ORG . ' ', self::VECTOR_TS, self::VECTOR_NONCE)
        );
        $this->assertSame(
            "skilland:moodle-connection-check:v1\n" . strtolower(self::VECTOR_ORG) . "\n1759370000\n" . self::VECTOR_NONCE,
            connection_check::proof_message(self::VECTOR_ORG, self::VECTOR_TS, self::VECTOR_NONCE)
        );
    }

    /** Everything ok: a summary first, then one ok line per setting; the request matches the contract. */
    public function test_all_ok(): void {
        $before = time();
        $results = $this->run_with(self::answer());

        $this->assertSame('summary', $results[0]['check']);
        $this->assertSame(
            [
                'summary' => ['ok', 'connectioncheck_connected'],
                'apikey' => ['ok', 'connectioncheck_apikey_ok'],
                'orgid' => ['ok', 'connectioncheck_orgid_ok'],
                'ssosecret' => ['ok', 'connectioncheck_ssosecret_ok'],
            ],
            self::by_check($results)
        );
        $this->assertTrue(connection_check::all_ok($results));

        $this->assertCount(1, $this->client->restposts);
        $post = $this->client->restposts[0];
        $this->assertSame('/api/moodle/connection-check', $post['path']);
        $this->assertSame('Org-1', $post['body']['organizationId']);
        $proof = $post['body']['ssoProof'];
        $this->assertSame(['timestamp', 'nonce', 'signature'], array_keys($proof));
        $this->assertGreaterThanOrEqual($before, $proof['timestamp']);
        $this->assertLessThanOrEqual(time(), $proof['timestamp']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16,64}$/', $proof['nonce']);
        $this->assertSame(
            hash_hmac(
                'sha256',
                "skilland:moodle-connection-check:v1\norg-1\n" . $proof['timestamp'] . "\n" . $proof['nonce'],
                'a-sso-secret-that-is-long-enough-0123456789'
            ),
            $proof['signature']
        );
    }

    /** Each check gets a fresh nonce. */
    public function test_nonce_is_fresh_per_run(): void {
        $this->run_with(self::answer());
        (new connection_check())->run();

        $this->assertNotSame(
            $this->client->restposts[0]['body']['ssoProof']['nonce'],
            $this->client->restposts[1]['body']['ssoProof']['nonce']
        );
    }

    /** An empty SSO secret sends a null proof and reads as missing. */
    public function test_sso_secret_missing_sends_no_proof(): void {
        $this->config(['sso_secret' => '']);
        $results = $this->run_with(self::answer('ok', 'missing'));

        $this->assertNull($this->client->restposts[0]['body']['ssoProof']);
        $this->assertArrayHasKey('ssoProof', $this->client->restposts[0]['body']);
        $this->assertSame(['fail', 'connectioncheck_ssosecret_missing'], self::by_check($results)['ssosecret']);
        $this->assertArrayNotHasKey('summary', self::by_check($results));
        $this->assertFalse(connection_check::all_ok($results));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function sso_provider(): array {
        return [
            'mismatch' => ['mismatch', 'fail', 'connectioncheck_ssosecret_mismatch'],
            'expired' => ['expired', 'fail', 'connectioncheck_ssosecret_expired'],
            'unavailable' => ['unavailable', 'fail', 'connectioncheck_ssosecret_unavailable'],
            'unknown value' => ['something-new', 'warn', 'connectioncheck_unknown_answer'],
        ];
    }

    /**
     * Each SSO secret verdict maps to its line.
     *
     * @dataProvider sso_provider
     */
    public function test_sso_secret_verdicts(string $verdict, string $status, string $identifier): void {
        $checks = self::by_check($this->run_with(self::answer('ok', $verdict)));

        $this->assertSame([$status, $identifier], $checks['ssosecret']);
        $this->assertSame(['ok', 'connectioncheck_orgid_ok'], $checks['orgid']);
        $this->assertArrayNotHasKey('summary', $checks);
    }

    /** An empty Organization ID is sent as an empty string and reads as missing. */
    public function test_org_missing(): void {
        $this->config(['orgid' => '']);
        $checks = self::by_check($this->run_with(self::answer('missing', 'ok')));

        $this->assertSame('', $this->client->restposts[0]['body']['organizationId']);
        $this->assertSame(['fail', 'connectioncheck_orgid_missing'], $checks['orgid']);
    }

    /** A wrong Organization ID with the right secret reads as an org mismatch only. */
    public function test_org_mismatch(): void {
        $checks = self::by_check($this->run_with(self::answer('mismatch', 'ok')));

        $this->assertSame(['fail', 'connectioncheck_orgid_mismatch'], $checks['orgid']);
        $this->assertSame(['ok', 'connectioncheck_ssosecret_ok'], $checks['ssosecret']);
        $this->assertSame(['ok', 'connectioncheck_apikey_ok'], $checks['apikey']);
    }

    /**
     * @return array<string, array{\Throwable, string, string, string}>
     */
    public static function failure_provider(): array {
        return [
            'unreachable' => [new rest_exception('error_graphql_http', 0, 'HTTP 0'), 'url', 'fail',
                'connectioncheck_unreachable'],
            '404' => [new rest_exception('error_graphql_http', 404, 'HTTP 404'), 'url', 'fail',
                'connectioncheck_not_skilland'],
            '401' => [new rest_exception('error_graphql_http', 401, 'HTTP 401', 'unauthorized'), 'apikey', 'fail',
                'connectioncheck_apikey_rejected'],
            '429' => [new rest_exception('error_graphql_http', 429, 'HTTP 429'), 'url', 'warn',
                'connectioncheck_rate_limited'],
            '500' => [new rest_exception('error_graphql_http', 500, 'HTTP 500'), 'url', 'fail',
                'connectioncheck_http_error'],
            'not JSON' => [new rest_exception('error_graphql_invalid_json', 200), 'url', 'fail',
                'connectioncheck_not_skilland'],
            'insecure URL' => [new \moodle_exception('error_insecure_url', 'mod_skilland', '', 'frontend'), 'url', 'fail',
                'connectioncheck_insecure_url'],
            'redirect' => [new \moodle_exception('error_http_redirect', 'mod_skilland', '', 302), 'url', 'fail',
                'connectioncheck_redirect'],
            'unexpected' => [new \RuntimeException('boom'), 'url', 'fail', 'connectioncheck_unexpected'],
        ];
    }

    /**
     * A failed request gives one line naming the setting to fix.
     *
     * @dataProvider failure_provider
     */
    public function test_failures(\Throwable $failure, string $check, string $status, string $identifier): void {
        $results = $this->run_with($failure);

        $this->assertSame([['check' => $check, 'status' => $status, 'message' => $identifier]], $results);
        $this->assertFalse(connection_check::all_ok($results));
    }

    /** A 200 answer without checks or organization is not a Skilland connection check. */
    public function test_answer_without_checks_is_not_skilland(): void {
        $checks = self::by_check($this->run_with(['ok' => true]));

        $this->assertSame(['url' => ['fail', 'connectioncheck_not_skilland']], $checks);
    }

    /** No Skilland URL and no Frontend URL: one line, no HTTP call. */
    public function test_empty_urls_short_circuit(): void {
        $this->config(['graphql_endpoint' => '', 'frontend_url' => '  ']);
        $results = $this->run_with(self::answer());

        $this->assertSame([['check' => 'url', 'status' => 'fail', 'message' => 'connectioncheck_skillandurl_missing']],
            $results);
        $this->assertSame([], $this->client->restposts);
    }

    /** A Frontend URL alone is enough to run the check. */
    public function test_frontend_url_alone_runs_the_check(): void {
        $this->config(['graphql_endpoint' => '', 'frontend_url' => 'https://skilland.internal.example']);
        $results = $this->run_with(self::answer());

        $this->assertTrue(connection_check::all_ok($results));
        $this->assertCount(1, $this->client->restposts);
    }

    /** An empty API key: one line, no HTTP call. */
    public function test_empty_api_key_short_circuits(): void {
        $this->config(['apikey' => '']);
        $results = $this->run_with(self::answer());

        $this->assertSame([['check' => 'apikey', 'status' => 'fail', 'message' => 'connectioncheck_apikey_missing']],
            $results);
        $this->assertSame([], $this->client->restposts);
    }

    /** An injected client is used instead of the \core\di binding. */
    public function test_injected_client_is_used(): void {
        $injected = new \fake_api_client(new no_network_api_client());
        $injected->respond_post('connection-check', self::answer());

        $results = (new connection_check($injected))->run();

        $this->assertTrue(connection_check::all_ok($results));
        $this->assertCount(1, $injected->restposts);
        $this->assertSame([], $this->client->restposts);
    }

    /** Neither the API key, the SSO secret, the signature nor the nonce reaches the log. */
    public function test_nothing_secret_is_logged(): void {
        $this->config(['devmode' => 1]);
        \mod_skilland\logger::reset_cache();
        $this->run_with(self::answer('mismatch', 'mismatch'));
        $proof = $this->client->restposts[0]['body']['ssoProof'];

        $log = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        foreach (['key1', 'a-sso-secret-that-is-long-enough-0123456789', $proof['signature'], $proof['nonce']] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    /** The class's own source never logs the proof or the settings it reads. */
    public function test_source_never_logs_secrets(): void {
        $source = file_get_contents(__DIR__ . '/../../src/classes/local/connection_check.php');
        preg_match_all('/logger::\w+\((.*?)\);/s', $source, $m);
        foreach ($m[1] as $call) {
            $this->assertDoesNotMatchRegularExpression('/\$(ssosecret|proof|nonce|signature|apikey)\b/', $call);
        }
    }
}

/**
 * api_client that fails any call a test did not answer, so nothing reaches the curl stub.
 */
class no_network_api_client implements \mod_skilland\local\api_client {
    /** Never called: fail loudly. */
    public function rest_get(string $path): array {
        throw new \LogicException('unexpected GET ' . $path);
    }

    /** Never called: fail loudly. */
    public function rest_post(string $path, array $body): array {
        throw new \LogicException('unexpected POST ' . $path);
    }

    /** Never called: fail loudly. */
    public function download_package(string $packageurl, int $expectedsize): string {
        throw new \LogicException('unexpected download ' . $packageurl);
    }
}
