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
 * from tests/fixtures/api_responses.json. download_package() returns a fresh copy of the SCORM 1.2
 * package zipped from tests/fixtures/scorm/. Every call is recorded, and a test can replace any
 * response, make an operation or the download throw, or build the package from another directory.
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

    /** @var array[] GraphQL calls made, each ['operation' => string, 'variables' => array]. */
    public array $calls = [];

    /** @var array[] Downloads made, each ['url' => string, 'expectedsize' => int]. */
    public array $downloads = [];

    /** @var \Throwable|null Thrown by download_package() when set. */
    protected ?\Throwable $downloadfailure = null;

    /** @var string Directory the SCORM package is zipped from. */
    protected string $packagedir;

    /**
     * Create the client with the default fixtures.
     */
    public function __construct() {
        $this->responses = self::default_responses();
        $this->packagedir = self::fixtures_dir() . '/scorm';
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
        return self::build_package($this->packagedir);
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
