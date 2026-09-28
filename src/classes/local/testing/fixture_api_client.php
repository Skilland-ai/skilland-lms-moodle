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
 * GraphQL responses are keyed by operation name (the name after `query` / `mutation`) and read
 * from tests/fixtures/api_responses.json; the REST routes that replaced an operation answer from
 * its fixture (see REST_ROUTES). download_package() returns a fresh copy of the SCORM 1.2
 * package zipped from tests/fixtures/scorm/. Every call is recorded, and a test can replace any
 * response, make an operation or the download throw, or build the package from another directory.
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
    /** @var array Operation name => response data, \Throwable to throw, or \Closure(array $variables): array. */
    protected array $responses;

    /** @var array[] GraphQL and REST calls made, each ['operation' => string, 'variables' => array], plus 'path' for REST. */
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
    public static function sign_scorm(string $topicid, array $body, string $packagehash, string $keyid,
            string $secretkey, string $contenthash = 'content-hash-v1'): array {
        if (empty($body['packageHash'])) {
            $body['packageHash'] = $packagehash;
        }
        if (!isset($body['contentHash'])) {
            $body['contentHash'] = $contenthash;
        }
        $message = \mod_skilland\local\package_signature::message($topicid, (string) $body['contentHash'],
            $packagehash, (string) ($body['generatedAt'] ?? ''));
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
     * The canned GraphQL responses, keyed by operation name.
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
     * Replace the response to a GraphQL operation.
     *
     * @param string $operation Operation name, e.g. TopicScormHash.
     * @param array|\Throwable|\Closure $response Data to return, an exception to throw, or a closure
     *     receiving the variables and returning the data.
     * @return self
     */
    public function set_response(string $operation, array|\Throwable|\Closure $response): self {
        $this->responses[$operation] = $response;
        return $this;
    }

    /**
     * Merge fields into the default response of an operation's root field.
     *
     * @param string $operation Operation name, e.g. TopicScormHash.
     * @param string $field Root field, e.g. topicScormHash.
     * @param array $values Fields to override.
     * @return self
     */
    public function merge_response(string $operation, string $field, array $values): self {
        $current = $this->responses[$operation] ?? [];
        if (!is_array($current)) {
            $current = self::default_responses()[$operation] ?? [];
        }
        $current[$field] = array_merge($current[$field] ?? [], $values);
        $this->responses[$operation] = $current;
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
     * Names of the GraphQL operations called so far, in order.
     *
     * @return string[]
     */
    public function operations(): array {
        return array_column($this->calls, 'operation');
    }

    /**
     * How many times an operation was called.
     *
     * @param string $operation
     * @return int
     */
    public function count_calls(string $operation): int {
        return count(array_keys($this->operations(), $operation, true));
    }

    /**
     * Answer a GraphQL operation from the fixtures.
     *
     * @param string $query The GraphQL document.
     * @param array $variables Query variables.
     * @return array
     * @throws \moodle_exception error_api_unavailable for an operation without a fixture.
     */
    public function graphql(string $query, array $variables = []): array {
        $operation = self::operation_name($query);
        $this->calls[] = ['operation' => $operation, 'variables' => $variables];

        $response = $this->responses[$operation] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            return $response($variables);
        }
        if ($response === null) {
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        return $response;
    }

    /**
     * REST routes answered from the fixture of the GraphQL operation they replace: last path
     * segment => [operation name, root field].
     */
    public const REST_ROUTES = [
        'scorm-hash' => ['TopicScormHash', 'topicScormHash'],
        'scorm' => ['GetTopicScorm', 'topicScorm'],
    ];

    /**
     * Answer a REST route from the fixture of the GraphQL operation it replaces.
     *
     * The call is recorded under that operation name (with the path), so count_calls() and
     * set_response() keep working for either transport. A null root field answers an empty body.
     *
     * @param string $path Route path, e.g. /api/moodle/topics/{id}/scorm-hash.
     * @return array
     * @throws \moodle_exception error_api_unavailable for a route without a fixture.
     */
    public function rest_get(string $path): array {
        $segments = explode('/', trim($path, '/'));
        $route = self::REST_ROUTES[end($segments)] ?? null;
        $topicid = count($segments) >= 2 ? rawurldecode($segments[count($segments) - 2]) : '';
        if ($route === null) {
            $this->calls[] = ['operation' => $path, 'variables' => [], 'path' => $path];
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        [$operation, $field] = $route;
        $variables = ['topicId' => $topicid];
        $this->calls[] = ['operation' => $operation, 'variables' => $variables, 'path' => $path];

        $response = $this->responses[$operation] ?? null;
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if ($response instanceof \Closure) {
            $response = $response($variables);
        }
        if ($response === null) {
            throw new \moodle_exception('error_api_unavailable', 'mod_skilland');
        }
        $body = $response[$field] ?? null;
        if (!is_array($body)) {
            return [];
        }
        if ($field === 'topicScorm' && !empty($body['packageUrl']) && !array_key_exists('signature', $body) &&
                $this->signingkeyid !== null && $this->signingsecret !== null) {
            $body = self::sign_scorm($topicid, $body, $this->package_hash(), $this->signingkeyid, $this->signingsecret);
        }
        return $body;
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
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,
            \FilesystemIterator::SKIP_DOTS));
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

    /**
     * Operation name of a GraphQL document, or '' when it is anonymous.
     *
     * @param string $query
     * @return string
     */
    public static function operation_name(string $query): string {
        return preg_match('/^\s*(?:query|mutation)\s+([A-Za-z_][A-Za-z0-9_]*)/', $query, $matches) ? $matches[1] : '';
    }
}
