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
 * Ed25519 signature check of a SkilLand topic SCORM package (SKL-650).
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Verifies that a downloaded topic SCORM package is the one SkilLand signed for the requested topic.
 *
 * SkilLand signs, with Ed25519, the UTF-8 lines (joined by "\n", no trailing newline):
 * MESSAGE_PREFIX, the requested topic id (lower case), the response's contentHash, the lower-case
 * hex sha256 of the package bytes and the response's generatedAt string. The plugin trusts the
 * keys pinned in PINNED_KEYS plus those an admin adds in mod_skilland/signingkeys; a package that
 * is unsigned, signed by an unknown key or whose signature does not verify is never imported.
 */
class package_signature {
    /** First line of every signed message; bumping it invalidates every earlier signature. */
    const MESSAGE_PREFIX = 'skilland-scorm-package-v1';

    /** Allowed shape of a key id. */
    const KEY_ID_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * Keys shipped with the plugin: key id => base64 of the raw 32-byte Ed25519 public key.
     *
     * Several entries at once is how a key is rotated: pin the new key, let SkilLand sign with it,
     * then drop the old one in a later release.
     */
    const PINNED_KEYS = [
        // Production key 2026-09; rotate per infra/runbooks/scorm-signing-key.md.
        '2026-09' => 'KkupKiNBdlqIAAAJOnPb+qNtLQCopb0o8xE437GiC9Q=',
    ];

    /**
     * The exact bytes SkilLand signs for a package.
     *
     * @param string $topicid The topic id the plugin requested (lower-cased here).
     * @param string $contenthash The response's contentHash.
     * @param string $packagehash Lower-case hex sha256 of the package bytes, computed locally.
     * @param string $generatedat The response's generatedAt, as sent.
     * @return string
     */
    public static function message(string $topicid, string $contenthash, string $packagehash, string $generatedat): string {
        return implode("\n", [self::MESSAGE_PREFIX, strtolower($topicid), $contenthash, $packagehash, $generatedat]);
    }

    /**
     * Refuse a package response that carries no signature, before anything is downloaded.
     *
     * The legacy GraphQL topicScorm answer never carries one, so a package reached through it
     * always stops here.
     *
     * @param array $response The normalised topic SCORM response.
     * @throws \moodle_exception error_scorm_signature_missing
     */
    public static function require_signed(array $response): void {
        $signature = $response['signature'] ?? null;
        $keyid = $response['keyId'] ?? null;
        if (!is_string($signature) || $signature === '' || !is_string($keyid) || $keyid === '') {
            throw new \moodle_exception('error_scorm_signature_missing', 'mod_skilland');
        }
    }

    /**
     * Check a downloaded package against its signed response. Throws on any failure.
     *
     * Run after the download and before the package reaches the file API or the SCORM parser.
     *
     * @param string $topicid The topic id the plugin requested, never the one in the response.
     * @param array $response The normalised topic SCORM response (packageHash, contentHash,
     *     generatedAt, signature, keyId).
     * @param string $zippath Local path of the downloaded package.
     * @throws \moodle_exception error_scorm_signature_missing, error_scorm_signature_unknown_key,
     *     error_scorm_signature_malformed, error_scorm_hash_mismatch or error_scorm_signature_invalid.
     */
    public static function verify(string $topicid, array $response, string $zippath): void {
        self::require_signed($response);

        $keyid = $response['keyId'];
        $keys = static::trusted_keys();
        if (!preg_match(self::KEY_ID_PATTERN, $keyid) || !isset($keys[$keyid])) {
            throw new \moodle_exception('error_scorm_signature_unknown_key', 'mod_skilland');
        }

        $signature = base64_decode($response['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \moodle_exception('error_scorm_signature_malformed', 'mod_skilland');
        }

        $packagehash = hash_file('sha256', $zippath);
        $announced = $response['packageHash'] ?? null;
        if ($packagehash === false || !is_string($announced) || !hash_equals($packagehash, strtolower($announced))) {
            throw new \moodle_exception('error_scorm_hash_mismatch', 'mod_skilland');
        }

        $contenthash = $response['contentHash'] ?? null;
        $generatedat = $response['generatedAt'] ?? null;
        if (!self::is_line($contenthash) || !self::is_line($generatedat)) {
            throw new \moodle_exception('error_scorm_signature_invalid', 'mod_skilland');
        }

        $message = self::message($topicid, $contenthash, $packagehash, $generatedat);
        try {
            $valid = sodium_crypto_sign_verify_detached($signature, $message, $keys[$keyid]);
        } catch (\SodiumException $e) {
            $valid = false;
        }
        if (!$valid) {
            throw new \moodle_exception('error_scorm_signature_invalid', 'mod_skilland');
        }
    }

    /**
     * The keys packages may be signed with: the pinned ones plus the admin setting's.
     *
     * A pinned key id always wins over an admin line with the same id.
     *
     * @return array<string, string> Key id => raw 32-byte public key.
     */
    public static function trusted_keys(): array {
        $keys = self::parse_keys((string) (get_config('mod_skilland', 'signingkeys') ?? ''))['keys'];
        foreach (static::PINNED_KEYS as $keyid => $publickey) {
            $raw = self::decode_public_key((string) $publickey);
            if ($raw !== null && preg_match(self::KEY_ID_PATTERN, (string) $keyid)) {
                $keys[(string) $keyid] = $raw;
            }
        }
        return $keys;
    }

    /**
     * Parse the admin setting: one keyid:base64publickey per line; blank lines and # comments ignored.
     *
     * @param string $text The setting value.
     * @return array{keys: array<string, string>, errors: int[]} Valid keys (id => raw 32-byte key)
     *     and the 1-based numbers of the invalid lines.
     */
    public static function parse_keys(string $text): array {
        $keys = [];
        $errors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $index => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode(':', $line, 2);
            $keyid = trim($parts[0]);
            $raw = count($parts) === 2 ? self::decode_public_key(trim($parts[1])) : null;
            if ($raw === null || !preg_match(self::KEY_ID_PATTERN, $keyid) || isset($keys[$keyid])) {
                $errors[] = $index + 1;
                continue;
            }
            $keys[$keyid] = $raw;
        }
        return ['keys' => $keys, 'errors' => $errors];
    }

    /**
     * Decode a base64 Ed25519 public key.
     *
     * @param string $base64
     * @return string|null The raw key, or null unless it decodes strictly to exactly 32 bytes.
     */
    public static function decode_public_key(string $base64): ?string {
        $raw = base64_decode($base64, true);
        return ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) ? $raw : null;
    }

    /**
     * Whether a response field is a non-empty string that cannot shift the signed lines.
     *
     * @param mixed $value
     * @return bool
     */
    private static function is_line($value): bool {
        return is_string($value) && $value !== '' && strpbrk($value, "\r\n") === false;
    }
}
