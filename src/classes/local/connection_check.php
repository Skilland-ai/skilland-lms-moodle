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

/**
 * Test connection: checks the saved plugin settings against Skilland (SKL-992).
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

use mod_skilland\logger;
use mod_skilland\rest_exception;

/**
 * Asks Skilland whether the saved Skilland URL, Frontend URL, API key, Organization ID and SSO
 * shared secret work together, and turns the answer into one line per setting to fix.
 *
 * The request is POST /api/moodle/connection-check, authenticated with the API key like every
 * other route. The SSO secret never leaves Moodle: the plugin sends an HMAC proof over the
 * Organization ID, a timestamp and a nonce instead. Nothing here logs the API key, the secret,
 * the proof or the nonce.
 */
class connection_check {
    /** Route of the check, below the Skilland URL. */
    const ROUTE = '/api/moodle/connection-check';

    /** Domain separator of the SSO proof message. */
    const PROOF_CONTEXT = 'skilland:moodle-connection-check:v1';

    /** The setting works. */
    const STATUS_OK = 'ok';

    /** The setting must be fixed. */
    const STATUS_FAIL = 'fail';

    /** Nothing is known to be wrong, but the check could not finish. */
    const STATUS_WARN = 'warn';

    /** @var api_client|null Injected transport; null resolves it through \core\di when the check runs. */
    private ?api_client $client;

    /**
     * Constructor.
     *
     * @param api_client|null $client Transport to Skilland; the \core\di binding when null.
     */
    public function __construct(?api_client $client = null) {
        require_once(__DIR__ . '/../../locallib.php');
        $this->client = $client;
    }

    /**
     * The message the SSO proof signs.
     *
     * @param string $orgid The Organization ID setting.
     * @param int $timestamp Unix seconds.
     * @param string $nonce Random [A-Za-z0-9] string, 16 to 64 characters.
     * @return string
     */
    public static function proof_message(string $orgid, int $timestamp, string $nonce): string {
        return self::PROOF_CONTEXT . "\n" . strtolower(trim($orgid)) . "\n" . $timestamp . "\n" . $nonce;
    }

    /**
     * HMAC-SHA256 of the proof message, keyed with the SSO shared secret as a plain string.
     *
     * @param string $ssosecret The SSO shared secret setting.
     * @param string $orgid The Organization ID setting.
     * @param int $timestamp Unix seconds.
     * @param string $nonce Random [A-Za-z0-9] string, 16 to 64 characters.
     * @return string 64 lower-case hex characters.
     */
    public static function signature(string $ssosecret, string $orgid, int $timestamp, string $nonce): string {
        return hash_hmac('sha256', self::proof_message($orgid, $timestamp, $nonce), $ssosecret);
    }

    /**
     * Run the check against the saved settings.
     *
     * @return array<int, array{check: string, status: string, message: string}> One result per
     *     setting, in display order; a 'summary' result comes first when everything works.
     */
    public function run(): array {
        $skillandurl = mod_skilland_get_skilland_url();
        $frontendurl = skilland_url::normalise((string) get_config('mod_skilland', 'frontend_url'));
        if ($skillandurl === '' && $frontendurl === '') {
            return [self::result('url', self::STATUS_FAIL, 'connectioncheck_skillandurl_missing')];
        }
        $url = skilland_get_frontend_url();

        if (trim((string) get_config('mod_skilland', 'apikey')) === '') {
            return [self::result('apikey', self::STATUS_FAIL, 'connectioncheck_apikey_missing')];
        }

        $orgid = trim((string) get_config('mod_skilland', 'orgid'));
        $ssosecret = (string) get_config('mod_skilland', 'sso_secret');
        $proof = null;
        if ($ssosecret !== '') {
            $timestamp = time();
            $nonce = bin2hex(random_bytes(16));
            $proof = [
                'timestamp' => $timestamp,
                'nonce' => $nonce,
                'signature' => self::signature($ssosecret, $orgid, $timestamp, $nonce),
            ];
        }

        try {
            $client = $this->client ?? \core\di::get(api_client::class);
            $answer = $client->rest_post(self::ROUTE, ['organizationId' => $orgid, 'ssoProof' => $proof]);
        } catch (rest_exception $e) {
            return [self::http_failure($e, $url)];
        } catch (\moodle_exception $e) {
            return [self::transport_failure($e, $url)];
        } catch (\Throwable $e) {
            logger::error('ConnectionCheck', 'Unexpected ' . get_class($e) . ' during the connection check');
            return [self::result('url', self::STATUS_FAIL, 'connectioncheck_unexpected')];
        }

        return self::interpret($answer, $url);
    }

    /**
     * Whether every result is ok.
     *
     * @param array $results The results of run().
     * @return bool
     */
    public static function all_ok(array $results): bool {
        foreach ($results as $result) {
            if ($result['status'] !== self::STATUS_OK) {
                return false;
            }
        }
        return $results !== [];
    }

    /**
     * Turn a 200 answer into one result per setting.
     *
     * @param array $answer The decoded answer.
     * @param string $url The address the check reached.
     * @return array
     */
    private static function interpret(array $answer, string $url): array {
        $checks = is_array($answer['checks'] ?? null) ? $answer['checks'] : null;
        $organization = is_array($answer['organization'] ?? null) ? $answer['organization'] : [];
        $orgid = is_string($organization['id'] ?? null) ? $organization['id'] : '';
        $orgname = is_string($organization['name'] ?? null) && $organization['name'] !== '' ? $organization['name'] : $orgid;
        if ($checks === null || $orgid === '') {
            logger::warn('ConnectionCheck', 'Answer without checks or organization from ' . $url);
            return [self::result('url', self::STATUS_FAIL, 'connectioncheck_not_skilland', $url)];
        }

        $results = [self::result('apikey', self::STATUS_OK, 'connectioncheck_apikey_ok', $orgname)];

        $orgcheck = (string) ($checks['organizationId'] ?? '');
        $results[] = match ($orgcheck) {
            'ok' => self::result('orgid', self::STATUS_OK, 'connectioncheck_orgid_ok'),
            'missing' => self::result('orgid', self::STATUS_FAIL, 'connectioncheck_orgid_missing', $orgid),
            'mismatch' => self::result('orgid', self::STATUS_FAIL, 'connectioncheck_orgid_mismatch', $orgid),
            default => self::result('orgid', self::STATUS_WARN, 'connectioncheck_unknown_answer'),
        };

        $ssocheck = (string) ($checks['ssoSecret'] ?? '');
        $results[] = match ($ssocheck) {
            'ok' => self::result('ssosecret', self::STATUS_OK, 'connectioncheck_ssosecret_ok'),
            'missing' => self::result('ssosecret', self::STATUS_FAIL, 'connectioncheck_ssosecret_missing'),
            'mismatch' => self::result('ssosecret', self::STATUS_FAIL, 'connectioncheck_ssosecret_mismatch'),
            'expired' => self::result('ssosecret', self::STATUS_FAIL, 'connectioncheck_ssosecret_expired'),
            'unavailable' => self::result('ssosecret', self::STATUS_FAIL, 'connectioncheck_ssosecret_unavailable'),
            default => self::result('ssosecret', self::STATUS_WARN, 'connectioncheck_unknown_answer'),
        };

        logger::debug('ConnectionCheck', 'Organization ID: ' . ($orgcheck !== '' ? $orgcheck : 'unknown') .
            ', SSO secret: ' . ($ssocheck !== '' ? $ssocheck : 'unknown'));

        if (self::all_ok($results)) {
            array_unshift($results, self::result('summary', self::STATUS_OK, 'connectioncheck_connected', $orgname));
        }
        return $results;
    }

    /**
     * The result of a non-2xx answer or a transport failure.
     *
     * @param rest_exception $e The failure.
     * @param string $url The address the check called.
     * @return array
     */
    private static function http_failure(rest_exception $e, string $url): array {
        if ($e->errorcode === 'error_graphql_invalid_json') {
            return self::result('url', self::STATUS_FAIL, 'connectioncheck_not_skilland', $url);
        }
        return match ((int) $e->httpcode) {
            0 => self::result('url', self::STATUS_FAIL, 'connectioncheck_unreachable', $url),
            401 => self::result('apikey', self::STATUS_FAIL, 'connectioncheck_apikey_rejected'),
            404 => self::result('url', self::STATUS_FAIL, 'connectioncheck_not_skilland', $url),
            429 => self::result('url', self::STATUS_WARN, 'connectioncheck_rate_limited'),
            default => self::result('url', self::STATUS_FAIL, 'connectioncheck_http_error', (string) $e->httpcode),
        };
    }

    /**
     * The result of a request the transport refused to send or follow.
     *
     * @param \moodle_exception $e The failure.
     * @param string $url The address the check called.
     * @return array
     */
    private static function transport_failure(\moodle_exception $e, string $url): array {
        return match ($e->errorcode) {
            'error_insecure_url' => self::result('url', self::STATUS_FAIL, 'connectioncheck_insecure_url', $url),
            'error_http_redirect' => self::result('url', self::STATUS_FAIL, 'connectioncheck_redirect', $url),
            'error_config_missing_apikey' => self::result('apikey', self::STATUS_FAIL, 'connectioncheck_apikey_missing'),
            default => self::result('url', self::STATUS_FAIL, 'connectioncheck_unexpected'),
        };
    }

    /**
     * One result line.
     *
     * @param string $check What was checked: url, apikey, orgid, ssosecret or summary.
     * @param string $status One of the STATUS_* constants.
     * @param string $identifier Language string in mod_skilland.
     * @param string|null $a Value for the language string.
     * @return array{check: string, status: string, message: string}
     */
    private static function result(string $check, string $status, string $identifier, ?string $a = null): array {
        return [
            'check' => $check,
            'status' => $status,
            'message' => get_string($identifier, 'mod_skilland', $a),
        ];
    }
}
