<?php
// Test doubles for the plugin's injectable seams (\core\di), used instead of test hooks in
// production code. Install one with \core\di::set(<seam>::class, $double) — or the install()
// helpers below — and call \core\di::reset_container() in tearDown().

use mod_skilland\local\api_client;
use mod_skilland\local\http_api_client;
use mod_skilland\local\retry_sleeper;
use mod_skilland\local\topic_scorm_updater;

/**
 * api_client answering canned responses keyed by the route's last path segment, query string
 * excluded (rest_get(): scorm-hash, scorm, contents, topics, skills, courses; rest_post(): skills).
 *
 * A response is the decoded body to return, a Throwable to throw, or a Closure returning either.
 * Routes with no canned response go to the fallback client — by default the real
 * http_api_client, so tests driving the curl stub keep working alongside canned answers.
 */
class fake_api_client implements api_client {
    /** @var mixed Canned download_package() result (path, Throwable or Closure); null = fallback. */
    private $download = null;

    /** @var api_client|null */
    private $fallback;

    /** @var array<int, array{url: string, expectedsize: int}> Every download_package() call. */
    public $downloads = [];

    /** @var array<string, mixed> Canned rest_get() answers keyed by the route's last path segment. */
    private $restresponses = [];

    /** @var string[] Every rest_get() path, in order. */
    public $restcalls = [];

    /** @var array<string, mixed> Canned rest_post() answers keyed by the route's last path segment. */
    private $postresponses = [];

    /** @var array<int, array{path: string, body: array}> Every rest_post() call, in order. */
    public $restposts = [];

    public function __construct(?api_client $fallback = null) {
        $this->fallback = $fallback;
    }

    /** Create a fake and bind it as the api_client for the current test. */
    public static function install(?api_client $fallback = null): self {
        $fake = new self($fallback);
        \core\di::set(api_client::class, $fake);
        return $fake;
    }

    /**
     * The installed fake, installing one when the current api_client is not a fake yet.
     */
    public static function current(): self {
        $client = \core\di::get(api_client::class);
        return $client instanceof self ? $client : self::install();
    }

    /**
     * What mod_skilland_check_topic_snapshot() sees for the next scorm-hash REST calls:
     * the hash array, or null for "no snapshot" (an empty body; the check returns null).
     */
    public static function topic_snapshot(?array $snapshot): self {
        return self::current()->respond_rest('scorm-hash', $snapshot ?? []);
    }

    /**
     * Answer rest_get() for every path whose last segment is $route (scorm-hash, scorm): the decoded
     * body, a Throwable to throw, or a Closure($path) returning either.
     */
    public function respond_rest(string $route, $response): self {
        $this->restresponses[$route] = $response;
        return $this;
    }

    /**
     * Answer rest_post() for every path whose last segment is $route: the decoded answer, a
     * Throwable to throw, or a Closure($path, $body) returning either.
     */
    public function respond_post(string $route, $response): self {
        $this->postresponses[$route] = $response;
        return $this;
    }

    public function respond_download($result): self {
        $this->download = $result;
        return $this;
    }

    /** The last path segment of a route path, query string excluded. */
    private static function route_of(string $path): string {
        $segments = explode('/', trim((string) parse_url($path, PHP_URL_PATH), '/'));
        return (string) end($segments);
    }

    public function rest_get(string $path): array {
        $this->restcalls[] = $path;
        $route = self::route_of($path);
        if (!array_key_exists($route, $this->restresponses)) {
            return $this->fallback()->rest_get($path);
        }
        $response = $this->restresponses[$route];
        if ($response instanceof \Closure) {
            $response = $response($path);
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }

    public function rest_post(string $path, array $body): array {
        $this->restposts[] = ['path' => $path, 'body' => $body];
        $route = self::route_of($path);
        if (!array_key_exists($route, $this->postresponses)) {
            return $this->fallback()->rest_post($path, $body);
        }
        $response = $this->postresponses[$route];
        if ($response instanceof \Closure) {
            $response = $response($path, $body);
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }

    public function download_package(string $packageurl, int $expectedsize): string {
        $this->downloads[] = ['url' => $packageurl, 'expectedsize' => $expectedsize];
        if ($this->download === null) {
            return $this->fallback()->download_package($packageurl, $expectedsize);
        }
        $result = $this->download instanceof \Closure ? ($this->download)($packageurl, $expectedsize) : $this->download;
        if ($result instanceof \Throwable) {
            throw $result;
        }
        return $result;
    }

    private function fallback(): api_client {
        return $this->fallback ??= new http_api_client();
    }
}

/**
 * topic_scorm_updater that never rebuilds: returns a fixed cmid, or runs a Closure with the
 * update() arguments ($skilland, $course, $sectionnum, $contenthash) and returns its result.
 */
class fake_topic_scorm_updater extends topic_scorm_updater {
    /** @var mixed */
    private $result;

    /** @var array<int, array> The arguments of every update() call, in order. */
    public $calls = [];

    public function __construct($result) {
        $this->result = $result;
    }

    /** Create a fake and bind it as the topic_scorm_updater for the current test. */
    public static function install($result): self {
        $fake = new self($result);
        \core\di::set(topic_scorm_updater::class, $fake);
        return $fake;
    }

    public function update($skilland, $course, $sectionnum = 0, ?string $contenthash = null) {
        $this->calls[] = [$skilland, $course, $sectionnum, $contenthash];
        return $this->result instanceof \Closure
            ? ($this->result)($skilland, $course, $sectionnum, $contenthash)
            : $this->result;
    }
}

/**
 * update_notifier that records each notify() call instead of messaging anyone, returning a fixed
 * count or running a Closure($skilland, $cm, $course) and returning its result.
 */
class fake_update_notifier extends \mod_skilland\local\update_notifier {
    /** @var array<int, array{0: \stdClass, 1: \stdClass, 2: \stdClass}> The arguments of every call. */
    public $calls = [];

    /** @var int|\Closure What notify() returns, or computes. */
    private $result;

    public function __construct($result = 1) {
        $this->result = $result;
    }

    /** Create a fake and bind it as the update_notifier for the current test. */
    public static function install($result = 1): self {
        $fake = new self($result);
        \core\di::set(\mod_skilland\local\update_notifier::class, $fake);
        return $fake;
    }

    public function notify(\stdClass $skilland, \stdClass $cm, \stdClass $course): int {
        $this->calls[] = [$skilland, $cm, $course];
        return $this->result instanceof \Closure ? ($this->result)($skilland, $cm, $course) : $this->result;
    }
}

/**
 * retry_sleeper that records each delay instead of sleeping. bootstrap.php makes it the suite
 * default, so no stub test ever waits on a retry.
 */
class recording_retry_sleeper extends retry_sleeper {
    /** @var int[] Delays in ms, in call order. */
    public $sleeps = [];

    public function sleep_ms(int $ms): void {
        $this->sleeps[] = $ms;
    }
}

/**
 * Signs topic SCORM answers the way SkilLand does (SKL-650), with a test-only key derived from a
 * fixed public seed, and trusts that key in the current test's plugin config.
 */
class test_package_signer {
    /** Key id of the test key. */
    const KEY_ID = 'stub-test-key';

    /** The test-only keypair: never trust it on a real site. */
    public static function keypair(): string {
        return sodium_crypto_sign_seed_keypair(hash('sha256', 'mod_skilland stub suite package signing key', true));
    }

    /** The mod_skilland/signingkeys line of the test key. */
    public static function key_line(): string {
        return self::KEY_ID . ':' . base64_encode(sodium_crypto_sign_publickey(self::keypair()));
    }

    /** Add a keyid:base64publickey line (the test key's by default) to the current plugin config. */
    public static function trust(?string $line = null): void {
        $line = $line ?? self::key_line();
        $config = $GLOBALS['_test_plugin_config']['mod_skilland'] ?? new \stdClass();
        $current = trim((string) ($config->signingkeys ?? ''));
        $lines = $current === '' ? [] : preg_split('/\r\n|\r|\n/', $current);
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
        }
        $config->signingkeys = implode("\n", $lines);
        $GLOBALS['_test_plugin_config']['mod_skilland'] = $config;
    }

    /**
     * Sign a topic SCORM answer for the package $zipbytes, trusting the test key.
     *
     * The signature covers the real sha256 of $zipbytes; an answer without packageHash (or with an
     * empty one) and without contentHash gets the real hash and a fixed content hash.
     */
    public static function sign(string $topicid, array $body, string $zipbytes, ?string $secretkey = null,
            string $keyid = self::KEY_ID): array {
        if ($secretkey === null) {
            self::trust();
            $secretkey = sodium_crypto_sign_secretkey(self::keypair());
        }
        $packagehash = hash('sha256', $zipbytes);
        if (!array_key_exists('packageHash', $body) || $body['packageHash'] === '') {
            $body['packageHash'] = $packagehash;
        }
        if (!array_key_exists('contentHash', $body)) {
            $body['contentHash'] = 'content-hash-v1';
        }
        $message = \mod_skilland\local\package_signature::message($topicid, (string) $body['contentHash'],
            $packagehash, (string) ($body['generatedAt'] ?? ''));
        $body['keyId'] = $keyid;
        $body['signature'] = base64_encode(sodium_crypto_sign_detached($message, $secretkey));
        return $body;
    }
}
