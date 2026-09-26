<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\local\cli_config;

/**
 * Unit tests for mod_skilland\local\cli_config, the logic behind cli/configure_api.php.
 */
class cli_config_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
    }

    protected function tearDown(): void {
        unset($GLOBALS['CFG']->mod_skilland_allow_http);
        parent::tearDown();
    }

    public function test_no_endpoint_writes_no_endpoint(): void {
        $writes = cli_config::plan(['endpoint' => '', 'orgid' => ''], null);
        $this->assertArrayNotHasKey('graphql_endpoint', $writes);
        $this->assertSame([], $writes);
    }

    public function test_https_endpoint_is_written(): void {
        $writes = cli_config::plan(['endpoint' => 'https://api.skilland.ai/graphql'], null);
        $this->assertSame('https://api.skilland.ai/graphql', $writes['graphql_endpoint']);
    }

    public function test_http_endpoint_rejected_by_default(): void {
        $this->assertNotNull(cli_config::validate_endpoint('http://host.docker.internal:8000/graphql', false));
        $this->expectException(\InvalidArgumentException::class);
        cli_config::plan(['endpoint' => 'http://host.docker.internal:8000/graphql'], 'secret-key');
    }

    public function test_http_endpoint_accepted_with_allow_insecure(): void {
        $this->assertNull(cli_config::validate_endpoint('http://host.docker.internal:8000/graphql', true));
        $writes = cli_config::plan(['endpoint' => 'http://localhost:8000/graphql', 'allow-insecure' => true], null);
        $this->assertSame('http://localhost:8000/graphql', $writes['graphql_endpoint']);
    }

    public function test_http_endpoint_accepted_with_cfg_allow_http(): void {
        $GLOBALS['CFG']->mod_skilland_allow_http = true;
        $this->assertNull(cli_config::validate_endpoint('http://localhost:8000/graphql', false));
    }

    public function test_invalid_url_rejected(): void {
        $this->assertNotNull(cli_config::validate_endpoint('not a url', true));
        $this->assertNotNull(cli_config::validate_endpoint('ftp://example.com/graphql', true));
        $this->assertNotNull(cli_config::validate_endpoint('javascript:alert(1)', true));
    }

    public function test_https_without_host_rejected(): void {
        $this->assertNotNull(cli_config::validate_endpoint('https:', false));
        $this->assertNotNull(cli_config::validate_endpoint('https:///graphql', false));
        $this->expectException(\InvalidArgumentException::class);
        cli_config::plan(['endpoint' => 'https:'], null);
    }

    public function test_uppercase_https_scheme_accepted(): void {
        $this->assertNull(cli_config::validate_endpoint('HTTPS://api.skilland.ai/graphql', false));
        $writes = cli_config::plan(['endpoint' => 'HTTPS://api.skilland.ai/graphql'], null);
        $this->assertSame('HTTPS://api.skilland.ai/graphql', $writes['graphql_endpoint']);
    }

    public function test_uppercase_http_scheme_still_needs_allow_insecure(): void {
        $this->assertNotNull(cli_config::validate_endpoint('HTTP://localhost:8000/graphql', false));
    }

    public function test_surrounding_whitespace_is_trimmed(): void {
        $this->assertNull(cli_config::validate_endpoint("  https://api.skilland.ai/graphql \n", false));
        $writes = cli_config::plan(
            ['endpoint' => "  https://api.skilland.ai/graphql \n", 'orgid' => ' org_1 '],
            "  sk_live_abc\n"
        );
        $this->assertSame('https://api.skilland.ai/graphql', $writes['graphql_endpoint']);
        $this->assertSame('org_1', $writes['orgid']);
        $this->assertSame('sk_live_abc', $writes['apikey']);
    }

    public function test_orgid_with_space_rejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        cli_config::plan(['orgid' => 'my org'], null);
    }

    public function test_orgid_with_markup_rejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        cli_config::plan(['orgid' => '<b>org</b>'], null);
    }

    public function test_uuid_orgid_accepted(): void {
        $writes = cli_config::plan(['orgid' => '3f2b8c1e-9a4d-4e7b-8c21-5d6f7a8b9c0d'], null);
        $this->assertSame('3f2b8c1e-9a4d-4e7b-8c21-5d6f7a8b9c0d', $writes['orgid']);
    }

    public function test_empty_apikey_writes_nothing(): void {
        $this->assertArrayNotHasKey('apikey', cli_config::plan([], ''));
        $this->assertArrayNotHasKey('apikey', cli_config::plan([], null));
        $this->assertArrayNotHasKey('apikey', cli_config::plan([], "   \n"));
    }

    public function test_apikey_written_when_given(): void {
        $this->assertSame('sk_live_abc', cli_config::plan([], 'sk_live_abc')['apikey']);
    }

    public function test_one_invalid_option_blocks_every_write(): void {
        try {
            cli_config::plan(['endpoint' => 'https://api.skilland.ai/graphql', 'orgid' => 'bad id'], 'key');
            $this->fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('organization id', $e->getMessage());
        }
    }
}
