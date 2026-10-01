<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_skilland\local\testing;

use mod_skilland\local\api_client;

/**
 * SkilLand API client answering from canned fixtures, for PHPUnit and Behat.
 *
 * REST answers are keyed by route (see REST_ROUTES, e.g. "GET topics/{id}/scorm") and read from
 * tests/fixtures/api_responses.json, each the JSON body SkilLand returns. download_package()
 * returns a fresh copy of the SCORM 1.2 package zipped from tests/fixtures/scorm/. Every call is
 * recorded, and a test can replace any answer, make a route or the download throw, or build the
 * package from another directory.
 *
 * The scorm route answers like SkilLand does since SKL-650: packageHash, contentHash, keyId and an
 * Ed25519 signature over the package it serves, made with a test-only key derived from a fixed
 * seed (FIXTURE_KEY_ID). The plugin trusts that key only once trust_fixture_key() lists it in
 * mod_skilland/signingkeys; a test can sign with another key, serve unsigned answers or tamper
 * with the downloaded bytes.
 *
 * Never used in production: the Behat binding in {@see \mod_skilland\hooks::di_configuration()}
 * only picks it while BEHAT_SITE_RUNNING, and PHPUnit tests install it with \core\di::set().
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fixture_api_client implements api_client {
    /** @var array Route key => response body, \Throwable to throw, or \Closure(array $variables): array. */
    protected array $responses;

    /** @var array[] REST calls made, each ['operation' => route key, 'variables' => array, 'path' => string]. */
    public array $calls = [];

    /** @var array[] Downloads made, each ['url' => string, 'expectedsize' => int]. */
    public array $downloads = [];

    /** @var \Throwable|null Thrown by download_package() when set. */
    protected ?\Throwable $downloadfailure = null;

    /** @var string Directory the SCORM package is zipped from. */
    protected string $packagedir;

    /** Key id of the test-only signing key. */
    const FIXTURE_KEY_ID = 'fixture';

    /** @var string|null Key id the scorm route signs with; null serves unsigned answers. */
    protected ?string $signingkeyid = self::FIXTURE_KEY_ID;

    /** @var string|null Ed25519 secret key (64 bytes) the scorm route signs with. */
    protected ?string $signingsecret = null;

    /** @var \Closure|null Rewrites the downloaded bytes: fn(string $bytes): string. */
    protected ?\Closure $downloadtamper = null;

    /**
     * Create the client with the default fixtures.
     */
    public function __construct() {
        $this->responses = self::default_responses();
        $this->packagedir = self::fixtures_dir() . '/scorm';
        $this->signingsecret = sodium_crypto_sign_secretkey(self::fixture_keypair());
    }

    /**
     * The test-only Ed25519 keypair, derived from a fixed public seed: never trust it on a real site.
     *
     * @return string sodium keypair.
     */
    public static function fixture_keypair(): string {
        return sodium_crypto_sign_seed_keypair(hash('sha256', 'mod_skilland fixture package signing key', true));
    }

    /**
     * The mod_skilland/signingkeys line of the fixture key.
     *
     * @return string keyid:base64publickey
     */
    public static function fixture_key_line(): string {
        return self::FIXTURE_KEY_ID . ':' . base64_encode(sodium_crypto_sign_publickey(self::fixture_keypair()));
    }

    /**
     * Add a keyid:base64publickey line to mod_skilland/signingkeys unless it is there already.
     *
     * @param string|null $line The line; the fixture key's when null.
     */
    public static function trust_fixture_key(?string $line = null): void {
        $line = $line ?? self::fixture_key_line();
        $current = trim((string) get_config('mod_skilland', 'signingkeys'));
        $lines = $current === '' ? [] : preg_split('/\r\n|\r|\n/', $current);
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
            set_config('signingkeys', implode("\n", $lines), 'mod_skilland');
        }
    }

    /**
     * Sign the scorm route's answers with another key, or serve them unsigned with null.
     *
     * @param string|null $keyid Key id sent as keyId.
     * @param string|null $secretkey Ed25519 secret key (64 bytes).
     * @return self
     */
    public function sign_with(?string $keyid, ?string $secretkey = null): self {
        $this->signingkeyid = $keyid;
        $this->signingsecret = $secretkey;
        return $this;
    }

    /**
     * Rewrite the bytes download_package() serves, after they were signed; null serves them as built.
     *
     * @param \Closure|null $tamper fn(string $bytes): string
     * @return self
     */
    public function tamper_download(?\Closure $tamper): self {
        $this->downloadtamper = $tamper;
        return $this;
    }

    /**
     * Add SkilLand's signature fields to a topic SCORM answer, as the backend does.
     *
     * The signature covers the package bytes' real sha256, whatever packageHash the answer
     * announces; an answer without packageHash or contentHash gets the real hash and $contenthash.
     *
     * @param string $topicid The topic id the plugin requested.
     * @param array $body The scorm answer.
     * @param string $packagehash Lower-case hex sha256 of the package bytes.
     * @param string $keyid Key id sent as keyId.
     * @param string $secretkey Ed25519 secret key (64 bytes).
     * @param string $contenthash contentHash used when the answer has none.
     * @return array The answer with packageHash, contentHash, keyId and signature.
     */
    public static function sign_scorm(
        string $topicid,
        array $body,
        string $packagehash,
        string $keyid,
        string $secretkey,
        string $contenthash = 'content-hash-v1'
    ): array {
        if (empty($body['packageHash'])) {
            $body['packageHash'] = $packagehash;
        }
        if (!isset($body['contentHash'])) {
            $body['contentHash'] = $contenthash;
        }
        $message = \mod_skilland\local\package_signature::message(
            $topicid,
            (string) $body['contentHash'],
            $packagehash,
            (string) ($body['generatedAt'] ?? '')
        );
        $body['keyId'] = $keyid;
        $body['signature'] = base64_encode(sodium_crypto_sign_detached($message, $secretkey));
        return $body;
    }

    /**
     * Lower-case hex sha256 of the package download_package() currently serves (before any tamper).
     *
     * @return string
     */
    public function package_hash(): string {
        $path = self::build_package($this->packagedir);
        try {
            return hash_file('sha256', $path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Directory holding the fixtures.
     *
     * @return string
     */
    public static function fixtures_dir(): string {
        return dirname(__DIR__, 3) . '/tests/fixtures';
    }

    /**
     * The canned REST answers, keyed by route key.
     *
     * @return array
     */
    public static function default_responses(): array {
        $json = file_get_contents(self::fixtures_dir() . '/api_responses.json');
        $responses = $json === false ? null : json_decode($json, true);
        if (!is_array($responses)) {
            throw new \coding_exception('mod_skilland: unreadable tests/fixtures/api_responses.json');
        }
        return $responses;
    }

    /**
     * Replace the answer of a route.
     *
     * @param string $route Route key, e.g. "GET topics/{id}/scorm-hash".
     * @param array|\Throwable|\Closure $response Body to return ([] for an empty body), an exception to
     *     throw, or a closure receiving the call's variables and returning the body.
     * @return self
     */
    public function set_response(string $route, array|\Throwable|\Closure $response): self {
        $this->responses[$route] = $response;
        return $this;
    }

    /**
     * Merge top-level fields into the default answer of a route.
     *
     * @param string $route Route key, e.g. "GET topics/{id}/scorm-hash".
     * @param array $values Fields to override.
     * @return self
     */
    public function merge_response(string $route, array $values): self {
        $current = $this->responses[$route] ?? [];
        if (!is_array($current)) {
            $current = self::default_responses()[$route] ?? [];
        }
        $this->responses[$route] = array_merge($current, $values);
        return $this;
    }

    /**
     * Make download_package() throw, or succeed again with null.
     *
     * @param \Throwable|null $failure
     * @return self
     */
    public function fail_download(?\Throwable $failure): self {
        $this->downloadfailure = $failure;
        return $this;
    }

    /**
     * Zip the package served by download_package() from another directory.
     *
     * @param string $dir Directory with imsmanifest.xml at its root.
     * @return self
     */
    public function set_package_dir(string $dir): self {
        $this->packagedir = $dir;
        return $this;
    }

    /**
     * Route keys called so far, in order.
     *
     * @return string[]
     */
    public function operations(): array {
        return array_column($this->calls, 'operation');
    }

    /**
     * How many times a route was called.
     *
     * @param string $route Route key.
     * @return int
     */
    public function count_calls(string $route): int {
        return count(array_keys($this->operations(), $route, true));
    }

    /**
     * REST routes the plugin calls: pattern on "METHOD path" (query string excluded) => [route key,
     * name of the path parameter, or null].
     */
    public const REST_ROUTES = [
        '#^GET /api/moodle/skills$#' => ['GET skills', null],
        '#^POST /api/moodle/skills$#' => ['POST skills', null],
        '#^GET /api/moodle/users/courses$#' => ['GET users/courses', null],
        '#^GET /api/moodle/skills/([^/]+)/topics$#' => ['GET skills/{id}/topics', 'skillId'],
        '#^GET /api/moodle/topics/([^/]+)/contents$#' => ['GET topics/{id}/contents', 'topicId'],
        '#^GET /api/moodle/topics/([^/]+)/scorm$#' => ['GET topics/{id}/scorm', 'topicId'],
        '#^GET /api/moodle/topics/([^/]+)/scorm-hash$#' => ['GET topics/{id}/scorm-hash', 'topicId'],
    ];

    /**
     * Answer a REST GET from the fixtures.
     *
     * The call is recorded under its route key with the path, the path parameter and the query
     * parameters as variables.
     *
     * @param string $path Route path, e.g. /api/moodle/topics/{id}/scorm-hash.
     * @return array
     * @throws \moodle_exception error_api_unavailable for a route without a fixture.
     */
    public function rest_get(string $path): array {
        return $this->answer('GET', $path, []);
    }

    /**
     * Answer a REST POST from the fixtures; the JSON body is recorded as the variables.
     *
     * @param string $path Route path, e.g. /api/moodle/skills.
     * @param array $body The JSON body.
     * @return array
     * @throws \moodle_exception error_api_unavailable for a route without a fixture.
     */
    public function rest_post(string $path, array $body): array {
        return $this->answer('POST', $path, $body);
    }

    /**
     * Resolve, record and answer a REST call.
     *
     * @param string $method GET or POST.
     * @param string $path Route path, with its query string.
     * @param array $body JSON body of a POST.
     * @return array
     * @throws \moodle_exception error_api_unavailable for a route without a fixture.
     */
    protected function answer(string $method, string $path, array $body): array {
        $route = parse_url($path, PHP_URL_PATH) ?: $path;
        $query = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);

        $key = null;
        $variables = $query;
        foreach (self::REST_ROUTES as $pattern => [$routekey, $param]) {
            if (preg_match($pattern, $method . ' ' . $route, $m)) {
                $key = $routekey;
                if ($param !== null) {
                    $variables = [$param => rawurldecode($m[1])] + $variables;
                }
                break;
            }
        }
        if ($method === 'POST') {
            $variables = $body;
        }
        if ($key === null) {
            $this->calls[] = ['operation' => $method . ' ' . $path, 'variables' => $variables, 'path' => $path];
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        $this->calls[] = ['operation' => $key, 'variables' => $variables, 'path' => $path];

        $response = $this->responses[$key] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            $response = $response($variables);
        }
        if (!is_array($response)) {
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        if (
            $key === 'GET topics/{id}/scorm' && !empty($response['packageUrl']) && !array_key_exists('signature', $response) &&
                $this->signingkeyid !== null && $this->signingsecret !== null
        ) {
            $response = self::sign_scorm(
                (string) $variables['topicId'],
                $response,
                $this->package_hash(),
                $this->signingkeyid,
                $this->signingsecret
            );
        }
        return $response;
    }

    /**
     * Return a fresh copy of the fixture SCORM package; the caller deletes it.
     *
     * @param string $packageurl Package URL, recorded only.
     * @param int $expectedsize Announced size, recorded only.
     * @return string Path of the zip.
     */
    public function download_package(string $packageurl, int $expectedsize): string {
        $this->downloads[] = ['url' => $packageurl, 'expectedsize' => $expectedsize];
        if ($this->downloadfailure !== null) {
            throw $this->downloadfailure;
        }
        $path = self::build_package($this->packagedir);
        if ($this->downloadtamper !== null) {
            file_put_contents($path, ($this->downloadtamper)((string) file_get_contents($path)));
        }
        return $path;
    }

    /**
     * Zip a directory into a new temp file, with fixed timestamps so the bytes are stable.
     *
     * @param string $dir Directory to zip.
     * @return string Path of the zip.
     */
    public static function build_package(string $dir): string {
        $path = make_request_directory() . '/skilland_fixture_' . random_string(8) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \moodle_exception('error_scorm_download_failed', 'mod_skilland');
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $dir,
            \FilesystemIterator::SKIP_DOTS
        ));
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);
        foreach ($files as $file) {
            $name = ltrim(str_replace('\\', '/', substr($file, strlen($dir))), '/');
            $zip->addFile($file, $name);
            $zip->setMtimeName($name, 1767225600);
        }
        $zip->close();
        return $path;
    }
}
