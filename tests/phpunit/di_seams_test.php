<?php

namespace mod_skilland\tests;

use mod_skilland\local\api_client;
use mod_skilland\local\http_api_client;
use mod_skilland\local\retry_sleeper;
use mod_skilland\local\topic_scorm_updater;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * SKL-696: every call to SkilLand, every topic SCORM rebuild and every retry pause goes through a
 * \core\di seam, so tests replace them without test hooks in production code.
 */
class di_seams_test extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        \core\di::reset_container();
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    public function test_di_configuration_binds_the_http_client_by_default(): void {
        $hook = new \core\hook\di_configuration();
        \mod_skilland\hooks::di_configuration($hook);

        $definitions = $hook->get_definitions();
        $this->assertArrayHasKey(api_client::class, $definitions);
        $this->assertInstanceOf(http_api_client::class, ($definitions[api_client::class])());
    }

    public function test_db_hooks_registers_the_di_configuration_callback(): void {
        $callbacks = [];
        include(__DIR__ . '/../../src/db/hooks.php');

        $this->assertContains([
            'hook' => \core\hook\di_configuration::class,
            'callback' => 'mod_skilland\hooks::di_configuration',
        ], $callbacks);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_behat_site_binds_the_fixture_client_when_it_exists(): void {
        define('BEHAT_SITE_RUNNING', true);
        $hook = new \core\hook\di_configuration();
        \mod_skilland\hooks::di_configuration($hook);

        $client = ($hook->get_definitions()[api_client::class])();
        $fixture = \mod_skilland\local\testing\fixture_api_client::class;
        $this->assertInstanceOf(class_exists($fixture) ? $fixture : http_api_client::class, $client);
    }

    public function test_graphql_goes_through_the_bound_api_client(): void {
        $fake = \fake_api_client::install()->respond('Ping', ['pong' => true]);

        $this->assertSame(['pong' => true], mod_skilland_graphql('query Ping { pong }', ['a' => 1]));
        $this->assertSame([['operation' => 'Ping', 'variables' => ['a' => 1]]], $fake->calls);
    }

    public function test_download_package_goes_through_the_bound_api_client(): void {
        $fake = \fake_api_client::install()->respond_download('/tmp/package.zip');

        $this->assertSame('/tmp/package.zip', mod_skilland_download_package('https://cdn.example/p.zip', 42));
        $this->assertSame([['url' => 'https://cdn.example/p.zip', 'expectedsize' => 42]], $fake->downloads);
    }

    public function test_check_topic_snapshot_reads_the_hash_through_the_api_client(): void {
        $fake = \fake_api_client::topic_snapshot(['contentHash' => 'h1', 'hasPackage' => true]);

        $this->assertSame(['contentHash' => 'h1', 'hasPackage' => true], mod_skilland_check_topic_snapshot('t1'));
        $this->assertSame([['operation' => 'TopicScormHash', 'variables' => ['topicId' => 't1']]], $fake->calls);
    }

    public function test_check_topic_snapshot_returns_null_when_the_api_client_throws(): void {
        \fake_api_client::install()->respond('TopicScormHash', new \moodle_exception('error_graphql_http'));

        $this->assertNull(mod_skilland_check_topic_snapshot('t1'));
    }

    public function test_retry_sleep_goes_through_the_bound_sleeper(): void {
        $sleeper = new \recording_retry_sleeper();
        \core\di::set(retry_sleeper::class, $sleeper);

        mod_skilland_retry_sleep(375);

        $this->assertSame([375], $sleeper->sleeps);
    }

    public function test_suite_default_sleeper_records_instead_of_sleeping(): void {
        $this->assertInstanceOf(\recording_retry_sleeper::class, \core\di::get(retry_sleeper::class));
    }

    public function test_real_retry_sleeper_never_sleeps_a_negative_delay(): void {
        $started = microtime(true);
        (new retry_sleeper())->sleep_ms(-1000);
        $this->assertLessThan(0.5, microtime(true) - $started);
    }

    public function test_topic_scorm_updater_is_resolved_from_the_container(): void {
        $fake = \fake_topic_scorm_updater::install(321);

        $updater = \core\di::get(topic_scorm_updater::class);

        $this->assertSame($fake, $updater);
        $this->assertSame(321, $updater->update((object) ['id' => 1], (object) ['id' => 2], 3, 'hash'));
        $this->assertSame(3, $fake->calls[0][2]);
        $this->assertSame('hash', $fake->calls[0][3]);
    }

    public function test_default_topic_scorm_updater_is_the_real_one(): void {
        $this->assertSame(topic_scorm_updater::class, get_class(\core\di::get(topic_scorm_updater::class)));
    }

    public function test_production_code_carries_no_globals_test_hooks(): void {
        $src = realpath(__DIR__ . '/../../src');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($src) + 1);
            if (substr($path, -4) !== '.php' || strpos($relative, 'vendor/') === 0 || strpos($relative, 'tests/') === 0) {
                continue;
            }
            if (preg_match('/\$GLOBALS\[\s*[\'"]_test_/', (string) file_get_contents($path))) {
                $offenders[] = $relative;
            }
        }
        $this->assertSame([], $offenders);
    }
}
