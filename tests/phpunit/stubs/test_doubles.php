<?php
// Test doubles for the plugin's injectable seams (\core\di), used instead of test hooks in
// production code. Install one with \core\di::set(<seam>::class, $double) — or the install()
// helpers below — and call \core\di::reset_container() in tearDown().

use mod_skilland\local\api_client;
use mod_skilland\local\http_api_client;
use mod_skilland\local\retry_sleeper;
use mod_skilland\local\topic_scorm_updater;

/**
 * api_client answering canned responses keyed by GraphQL operation name (graphql()) or by the
 * route's last path segment (rest_get()).
 *
 * A response is the `data` array to return, a Throwable to throw, or a Closure($query, $variables)
 * returning either. Operations and routes with no canned response go to the fallback client — by default the
 * real http_api_client, so tests driving the curl stub keep working alongside canned answers.
 */
class fake_api_client implements api_client {
    /** @var array<string, mixed> */
    private $responses = [];

    /** @var mixed Canned download_package() result (path, Throwable or Closure); null = fallback. */
    private $download = null;

    /** @var api_client|null */
    private $fallback;

    /** @var array<int, array{operation: string, variables: array}> Every graphql() call, in order. */
    public $calls = [];

    /** @var array<int, array{url: string, expectedsize: int}> Every download_package() call. */
    public $downloads = [];

    /** @var array<string, mixed> Canned rest_get() answers keyed by the route's last path segment. */
    private $restresponses = [];

    /** @var string[] Every rest_get() path, in order. */
    public $restcalls = [];

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

    public function respond(string $operation, $response): self {
        $this->responses[$operation] = $response;
        return $this;
    }

    public function respond_download($result): self {
        $this->download = $result;
        return $this;
    }

    /** @return string[] The operation names called, in order. */
    public function operations(): array {
        return array_column($this->calls, 'operation');
    }

    public function graphql(string $query, array $variables = []): array {
        $operation = mod_skilland_graphql_operation_name($query);
        $this->calls[] = ['operation' => $operation, 'variables' => $variables];
        if (!array_key_exists($operation, $this->responses)) {
            return $this->fallback()->graphql($query, $variables);
        }
        $response = $this->responses[$operation];
        if ($response instanceof \Closure) {
            $response = $response($query, $variables);
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }
        return $response;
    }

    public function rest_get(string $path): array {
        $this->restcalls[] = $path;
        $segments = explode('/', trim($path, '/'));
        $route = (string) end($segments);
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
