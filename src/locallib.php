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

use mod_skilland\local\package_signature;
use mod_skilland\logger;
use mod_skilland\rest_exception;

/**
 * Local library functions for mod_skilland
 *
 * This file contains helper functions for managing Moodle course-to-Skilland course mappings
 * and other internal operations.
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Check if the Skilland activity module is enabled in Moodle.
 *
 * @return bool True if the module is enabled, false otherwise.
 */
function skilland_is_enabled(): bool {
    global $DB;
    try {
        $module = $DB->get_record('modules', ['name' => 'skilland']);
        return $module && (bool)$module->visible;
    } catch (\Exception $e) {
        return false;
    }
}

/**
 * Get the Skilland course mapping for a given Moodle course.
 *
 * @param int $courseid Moodle course ID
 * @return stdClass|false Skilland course mapping record or false if not found
 */
function skilland_get_course_mapping($courseid) {
    global $DB;
    return $DB->get_record('skilland_course', ['course' => $courseid]);
}

/**
 * Check if a Moodle course has a Skilland course mapping.
 *
 * @param int $courseid Moodle course ID
 * @return bool True if mapping exists, false otherwise
 */
function skilland_course_has_mapping($courseid) {
    global $DB;
    return $DB->record_exists('skilland_course', ['course' => $courseid]);
}

/**
 * Create or update Skilland course mapping.
 *
 * @param int $courseid Moodle course ID
 * @param string $skillandcourseid Skilland course ID
 * @param string $orgid Skilland organization ID (optional, uses global setting if not provided)
 * @return int|false The mapping record ID on success, false on failure
 */
function skilland_set_course_mapping($courseid, $skillandcourseid, $orgid = null) {
    global $DB, $CFG;

    // Use global org ID if not provided.
    if (empty($orgid)) {
        $orgid = get_config('mod_skilland', 'orgid');
    }

    // Check if mapping already exists.
    $existing = $DB->get_record('skilland_course', ['course' => $courseid]);

    $now = time();

    if ($existing) {
        // Update existing mapping.
        $existing->skilland_courseid = $skillandcourseid;
        $existing->skilland_orgid = $orgid;
        $existing->timemodified = $now;

        if ($DB->update_record('skilland_course', $existing)) {
            return $existing->id;
        }
        return false;
    } else {
        // Create new mapping.
        $mapping = new stdClass();
        $mapping->course = $courseid;
        $mapping->skilland_courseid = $skillandcourseid;
        $mapping->skilland_orgid = $orgid;
        $mapping->timesynced = null;
        $mapping->timecreated = $now;
        $mapping->timemodified = $now;

        return $DB->insert_record('skilland_course', $mapping);
    }
}

/**
 * Update the last sync time for a course mapping.
 *
 * @param int $courseid Moodle course ID
 * @return bool True on success, false on failure
 */
function skilland_update_course_sync($courseid) {
    global $DB;

    $mapping = $DB->get_record('skilland_course', ['course' => $courseid]);
    if (!$mapping) {
        return false;
    }

    $mapping->timesynced = time();
    $mapping->timemodified = time();

    return $DB->update_record('skilland_course', $mapping);
}

/**
 * Get the Skilland course ID for a given Moodle course.
 *
 * @param int $courseid Moodle course ID
 * @return string|false Skilland Course ID or false if not mapped
 */
function skilland_get_skilland_courseid($courseid) {
    $mapping = skilland_get_course_mapping($courseid);
    return $mapping ? $mapping->skilland_courseid : false;
}

/**
 * Studio deep-link path for the SSO handoff.
 *
 * Goes through /skills-studio/, which resolves SkilLand UUIDs and Edukami ObjectIds in the
 * session's organization; /skills/<id> only knows UUIDs and shows "Skill not found" on a migrated course.
 *
 * @param string|int|false|null $skillid Skilland skill ID stored for the course (empty = skills list)
 * @param string|null $topicid Optional topic ID to open
 * @return string Relative path
 */
function skilland_studio_redirect_path($skillid, $topicid = '') {
    if (empty($skillid)) {
        return '/skills';
    }
    $path = '/skills-studio/' . rawurlencode((string)$skillid);
    if (!empty($topicid)) {
        $path .= '/topics/' . rawurlencode((string)$topicid);
    }
    return $path;
}

/**
 * Delete Skilland course mapping.
 * Note: This should be used carefully as it will affect all activities in the course.
 *
 * @param int $courseid Moodle course ID
 * @return bool True on success, false on failure
 */
function skilland_delete_course_mapping($courseid) {
    global $DB;
    return $DB->delete_records('skilland_course', ['course' => $courseid]);
}

/**
 * Get the Skilland Course ID course custom field.
 *
 * @return \core_customfield\field_controller|null The custom field controller or null if not found
 */
function skilland_get_course_customfield() {
    $fieldshortname = 'skilland_course_id';

    try {
        // Get the course custom fields handler.
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $categories = $handler->get_categories_with_fields();

        // Search for the field by shortname.
        foreach ($categories as $category) {
            foreach ($category->get_fields() as $field) {
                if ($field->get('shortname') === $fieldshortname) {
                    return $field;
                }
            }
        }
    } catch (Exception $e) {
        // Handler or field not available.
        return null;
    }

    return null;
}

/**
 * Ensure the Skilland Course ID course custom field exists.
 * Creates it if it doesn't exist (requires proper permissions).
 *
 * @return \core_customfield\field_controller|null The custom field controller or null if creation failed
 */
function skilland_ensure_course_customfield() {
    $field = skilland_get_course_customfield();

    // Field configuration.
    $fieldshortname = 'skilland_course_id';
    $categoryname = get_string('pluginname', 'mod_skilland');

    try {
        $handler = \core_customfield\handler::get_handler('core_course', 'course');

        // 1. Ensure the Skilland category exists.
        $category = null;
        $categoryid = null;
        $categories = $handler->get_categories_with_fields();

        // Search for existing category by name.
        foreach ($categories as $cat) {
            if ($cat->get('name') === $categoryname) {
                $category = $cat;
                $categoryid = $cat->get('id');
                break;
            }
        }

        // Create category if it doesn't exist.
        if (!$category) {
            try {
                $categoryid = $handler->create_category($categoryname);
                if ($categoryid) {
                    // Refresh categories to get the object.
                    $categories = $handler->get_categories_with_fields();
                    foreach ($categories as $cat) {
                        if ($cat->get('id') == $categoryid) {
                            $category = $cat;
                            break;
                        }
                    }

                    if ($category) {
                        $category->set('description', '');
                        $category->set('descriptionformat', FORMAT_HTML);
                        $category->save();
                    }
                }
            } catch (Exception $catexception) {
                // Critical failure only if we absolutely cannot get a category AND we need to create one.
                throw new Exception('Cannot create mandatory Skilland category: ' . $catexception->getMessage());
            }
        }

        if (!$category) {
             throw new Exception('Failed to obtain Skilland category object.');
        }

        // 2. Handle the Custom Field.
        if ($field) {
            // Field exists - check if migration is needed.
            if ($field->get('categoryid') != $category->get('id')) {
                // Migration needed: Move field to correct category.
                $field->set('categoryid', $category->get('id'));
                $field->save();
                logger::debug('CustomField', 'Migrated course custom field to correct category ' . $category->get('name'));
            }
            return $field;
        }

        // Field doesn't exist - Create it in the correct category.
        $fielddata = (object)[
            'type' => 'text',
            'categoryid' => $category->get('id'),
            'component' => 'core_course',
            'area' => 'course',
            'itemid' => 0,
        ];

        $field = \core_customfield\field_controller::create(0, $fielddata, $category);
        $field->set('name', get_string('customfield_skilland_course_id', 'mod_skilland'));
        $field->set('shortname', $fieldshortname);

        // Build description.
        $description = get_string('customfield_skilland_course_id_desc', 'mod_skilland');
        $field->set('description', $description);
        $field->set('descriptionformat', FORMAT_HTML);
        $field->set('configdata', '{"required":"0","defaultvalue":"","displaysize":50,"maxlength":255,' .
            '"ispassword":"0","link":"","locked":"1","visibility":"2"}');
        $field->save();

        return $field;
    } catch (Exception $e) {
        // Log the error for debugging with full stack trace.
        $errormsg = 'Failed to ensure Skilland Course ID custom field: ' . $e->getMessage();
        $errormsg .= ' | File: ' . $e->getFile() . ':' . $e->getLine();
        debugging($errormsg, DEBUG_NORMAL);
        logger::error('CustomField', $errormsg);
        return null;
    }
}

/**
 * Get the Skilland Course ID value from course custom fields.
 *
 * @param int $courseid Moodle course ID
 * @return string|null The Skilland Course ID value or null if not set
 */
function skilland_get_course_customfield_value($courseid) {
    try {
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $data = $handler->get_instance_data($courseid, true);

        foreach ($data as $datarecord) {
            $field = $datarecord->get_field();
            if ($field && $field->get('shortname') === 'skilland_course_id') {
                $value = $datarecord->get_value();
                return !empty($value) ? $value : null;
            }
        }
    } catch (Exception $e) {
        // Handler or data not available.
        return null;
    }

    return null;
}

/**
 * Resolve the Skilland course (skill) mapped to a Moodle course.
 *
 * The locked course custom field is authoritative; the legacy `skilland_course`
 * table is the fallback for courses mapped before the custom field existed.
 *
 * @param int $moodlecourseid Moodle course ID
 * @return string|null The mapped Skilland course ID, or null when the course is not mapped
 */
function skilland_get_mapped_courseid(int $moodlecourseid): ?string {
    $value = skilland_get_course_customfield_value($moodlecourseid);
    if (!empty($value)) {
        return (string)$value;
    }

    $legacy = skilland_get_skilland_courseid($moodlecourseid);
    return !empty($legacy) ? (string)$legacy : null;
}

/**
 * Require that a Skilland course ID is the one mapped to a Moodle course.
 *
 * A teacher holds capabilities in their own Moodle course only, so any Skilland
 * course ID a request names must be the one that course is mapped to.
 *
 * @param int $moodlecourseid Moodle course ID
 * @param string $courseid Skilland course ID supplied by the request
 * @return string The mapped Skilland course ID
 * @throws moodle_exception When the course is not mapped or is mapped to another skill
 */
function skilland_require_mapped_course(int $moodlecourseid, string $courseid): string {
    $mapped = skilland_get_mapped_courseid($moodlecourseid);
    if ($mapped === null) {
        throw new moodle_exception('error_course_not_mapped', 'mod_skilland');
    }
    if (!hash_equals($mapped, $courseid)) {
        throw new moodle_exception('error_course_not_mapped_to_skill', 'mod_skilland');
    }
    return $mapped;
}

/**
 * Check whether a Skilland topic belongs to a Skilland course.
 *
 * @param string $topicid Skilland topic ID
 * @param string $skillandcourseid Skilland course ID
 * @return bool True when the course lists the topic
 * @throws moodle_exception If the Skilland API call fails
 */
function skilland_topic_belongs_to_course(string $topicid, string $skillandcourseid): bool {
    if ($topicid === '' || $skillandcourseid === '') {
        return false;
    }

    $course = mod_skilland_fetch_topics($skillandcourseid);
    foreach ($course['topics'] ?? [] as $topic) {
        if (isset($topic['id']) && (string)$topic['id'] === $topicid) {
            return true;
        }
    }
    return false;
}

/**
 * List the submitted lesson IDs that are not lessons of the topic.
 *
 * The activity form posts `selected_lessons` as a JSON object keyed by lesson ID.
 * IDs are compared as strings, so numeric and string IDs match. A non-empty JSON
 * list is not a lesson map: its indexes are returned, as strings, as foreign IDs.
 * Anything else that is not a non-empty JSON object ('', '{}', '[]', '[]',
 * invalid JSON, a scalar) yields no foreign IDs.
 *
 * @param string $json Submitted selected_lessons value
 * @param array $topiclessons Lessons of the topic, as returned by mod_skilland_fetch_lessons()
 * @return string[] Submitted lesson IDs missing from the topic, in submitted order
 */
function skilland_lessons_outside_topic(string $json, array $topiclessons): array {
    $trimmed = ltrim($json);
    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
        return [];
    }
    $selected = json_decode($trimmed, true);
    if (!is_array($selected) || empty($selected)) {
        return [];
    }
    if ($trimmed[0] === '[') {
        // A JSON list is not a lesson map: skilland_process_selected_lessons() would save its
        // indexes as lesson IDs, so every one of them counts as outside the topic.
        return array_map('strval', array_keys($selected));
    }

    $known = [];
    foreach ($topiclessons as $lesson) {
        if (is_array($lesson) && isset($lesson['id'])) {
            $known[(string)$lesson['id']] = true;
        }
    }

    $outside = [];
    foreach (array_keys($selected) as $id) {
        if (!isset($known[(string)$id])) {
            $outside[] = (string)$id;
        }
    }
    return $outside;
}

/** Address used when neither the Skilland URL nor the frontend URL setting is set. */
const MOD_SKILLAND_DEFAULT_URL = 'https://app.skilland.ai';

/**
 * The Skilland URL setting (mod_skilland/graphql_endpoint), normalised to the site's base URL.
 *
 * @return string Base URL without a trailing slash, '' when the setting is empty.
 */
function mod_skilland_get_skilland_url(): string {
    return \mod_skilland\local\skilland_url::normalise((string) get_config('mod_skilland', 'graphql_endpoint'));
}

/**
 * GET a Skilland REST route through the injectable api_client (\core\di).
 *
 * @param string $path Route path below the Skilland URL, e.g. /api/moodle/topics/{id}/scorm-hash.
 * @return array The decoded JSON body.
 * @throws moodle_exception If configuration is missing or the call fails.
 * @throws rest_exception When the server answers a non-2xx status or cannot be reached.
 */
function mod_skilland_rest_get(string $path): array {
    return \core\di::get(\mod_skilland\local\api_client::class)->rest_get($path);
}

/**
 * POST a JSON body to a Skilland REST route through the injectable api_client (\core\di).
 *
 * @param string $path Route path below the Skilland URL, e.g. /api/moodle/skills.
 * @param array $body The JSON body.
 * @return array The decoded JSON answer.
 * @throws moodle_exception If configuration is missing or the call fails.
 * @throws rest_exception When the server answers a non-2xx status or cannot be reached.
 */
function mod_skilland_rest_post(string $path, array $body): array {
    return \core\di::get(\mod_skilland\local\api_client::class)->rest_post($path, $body);
}

/**
 * Check the API key and build the absolute URL of a REST route (http_api_client).
 *
 * @param string $path Route path below the Skilland URL.
 * @return array{0: string, 1: string} The URL and the API key.
 * @throws moodle_exception error_config_missing_apikey or error_insecure_url.
 */
function mod_skilland_rest_prepare(string $path): array {
    global $CFG;

    require_once($CFG->libdir . '/filelib.php');

    $apikey = (string) (get_config('mod_skilland', 'apikey') ?? '');
    $url = skilland_get_frontend_url() . '/' . ltrim($path, '/');

    logger::debug('REST', 'API Key: ' . ($apikey !== '' ? 'SET' : 'MISSING'));
    if ($apikey === '') {
        logger::error('REST', 'Missing apikey');
        throw new moodle_exception('error_config_missing_apikey', 'mod_skilland');
    }

    mod_skilland_require_https($url, 'frontend');

    return [$url, $apikey];
}

/**
 * GET a Skilland REST route over HTTPS (http_api_client).
 *
 * The base URL is the frontend URL (skilland_get_frontend_url()) and the API key travels as a
 * Bearer token. Transient failures are retried; redirects are refused. Neither the API key nor a
 * query string (which may carry an email) ever reaches the log.
 *
 * @param string $path Route path below the Skilland URL, e.g. /api/moodle/topics/{id}/scorm-hash.
 * @return array The decoded JSON body.
 * @throws moodle_exception error_config_missing_apikey, error_insecure_url or error_http_redirect.
 * @throws rest_exception error_graphql_http on a non-2xx status or a transport failure (httpcode 0),
 *     error_graphql_invalid_json when a 2xx body is not a JSON object or array.
 */
function mod_skilland_rest_get_http(string $path): array {
    [$url, $apikey] = mod_skilland_rest_prepare($path);
    $safeurl = mod_skilland_redact_url($url);
    logger::debug('REST', 'Starting GET ' . $safeurl);

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apikey,
    ];
    $started = microtime(true);

    for ($attempt = 1;; $attempt++) {
        $curl = mod_skilland_make_curl($url);
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT' => 30,
        ]);
        $curl->setHeader($headers);
        logger::debug('REST', 'Making GET request to ' . $safeurl . ' (attempt ' . $attempt . '/' .
            MOD_SKILLAND_REST_MAX_ATTEMPTS . ')');

        try {
            $response = (string) $curl->get($url);
        } catch (Exception $e) {
            logger::error('REST', 'Exception during GET ' . $safeurl . ': ' . get_class($e));
            throw $e;
        }

        $info = $curl->get_info();
        $httpcode = isset($info['http_code']) ? (int) $info['http_code'] : 0;
        $errno = (int) $curl->get_errno();
        $curlerror = method_exists($curl, 'error') ? (string) $curl->error() : '';

        logger::debug('REST', 'HTTP code: ' . $httpcode . ', CURL errno: ' . $errno);

        if ($httpcode >= 300 && $httpcode < 400) {
            logger::error('REST', 'Refusing redirect (HTTP ' . $httpcode . ') from ' . $safeurl);
            throw new moodle_exception('error_http_redirect', 'mod_skilland', '', $httpcode);
        }

        if ($httpcode >= 200 && $httpcode < 300 && !$errno) {
            break;
        }

        if ($attempt < MOD_SKILLAND_REST_MAX_ATTEMPTS && mod_skilland_is_transient($httpcode, $errno)) {
            $retryafter = null;
            if ($httpcode === 429 || $httpcode === 503) {
                $retryafter = mod_skilland_response_header($curl, 'Retry-After');
            }
            $delay = mod_skilland_retry_delay_ms($attempt, $retryafter);
            $elapsedms = (int) round((microtime(true) - $started) * 1000);
            if ($elapsedms + $delay < MOD_SKILLAND_REST_RETRY_BUDGET_MS) {
                $reason = $httpcode === 0 ? 'connection error ' . $errno : 'HTTP ' . $httpcode;
                logger::warn('REST', 'Retrying GET ' . $safeurl . ' after ' . $reason . ' (attempt ' .
                    ($attempt + 1) . '/' . MOD_SKILLAND_REST_MAX_ATTEMPTS . ')');
                mod_skilland_retry_sleep($delay);
                continue;
            }
        }

        mod_skilland_rest_failure('GET', $url, $httpcode, $errno, $curlerror, $response);
    }

    return mod_skilland_rest_decode($response, $httpcode, $safeurl);
}

/**
 * POST a JSON body to a Skilland REST route over HTTPS (http_api_client).
 *
 * Same base URL, Bearer key and redirect refusal as mod_skilland_rest_get_http(), but a POST is
 * sent exactly once: a write that may have reached the server is never repeated.
 *
 * @param string $path Route path below the Skilland URL, e.g. /api/moodle/skills.
 * @param array $body The JSON body; an empty array is sent as an empty object.
 * @return array The decoded JSON answer.
 * @throws moodle_exception error_config_missing_apikey, error_insecure_url or error_http_redirect.
 * @throws rest_exception error_graphql_http on a non-2xx status or a transport failure (httpcode 0),
 *     carrying the answer's `error` code in $apierror; error_graphql_invalid_json when a 2xx body
 *     is not a JSON object or array.
 */
function mod_skilland_rest_post_http(string $path, array $body): array {
    [$url, $apikey] = mod_skilland_rest_prepare($path);
    $safeurl = mod_skilland_redact_url($url);
    logger::debug('REST', 'Starting POST ' . $safeurl . ' with fields ' . implode(', ', array_keys($body)));

    $curl = mod_skilland_make_curl($url);
    $curl->setopt([
        'CURLOPT_CONNECTTIMEOUT' => 10,
        'CURLOPT_TIMEOUT' => 30,
    ]);
    $curl->setHeader([
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apikey,
    ]);

    try {
        $response = (string) $curl->post($url, json_encode($body === [] ? new \stdClass() : $body));
    } catch (Exception $e) {
        logger::error('REST', 'Exception during POST ' . $safeurl . ': ' . get_class($e));
        throw $e;
    }

    $info = $curl->get_info();
    $httpcode = isset($info['http_code']) ? (int) $info['http_code'] : 0;
    $errno = (int) $curl->get_errno();
    $curlerror = method_exists($curl, 'error') ? (string) $curl->error() : '';

    logger::debug('REST', 'HTTP code: ' . $httpcode . ', CURL errno: ' . $errno);

    if ($httpcode >= 300 && $httpcode < 400) {
        logger::error('REST', 'Refusing redirect (HTTP ' . $httpcode . ') from ' . $safeurl);
        throw new moodle_exception('error_http_redirect', 'mod_skilland', '', $httpcode);
    }

    if ($httpcode < 200 || $httpcode >= 300 || $errno) {
        mod_skilland_rest_failure('POST', $url, $httpcode, $errno, $curlerror, $response);
    }

    return mod_skilland_rest_decode($response, $httpcode, $safeurl);
}

/**
 * Log a failed REST call and throw its rest_exception.
 *
 * The URL (without its query string), curl details and a body snippet go to the log, never into
 * the exception, which carries only the status in $a and the answer's `error` code, if any.
 *
 * @param string $method HTTP method, for the log.
 * @param string $url The requested URL.
 * @param int $httpcode HTTP status, 0 when no response arrived.
 * @param int $errno curl errno.
 * @param string $curlerror curl error message.
 * @param string $response Raw response body.
 * @throws rest_exception Always.
 */
function mod_skilland_rest_failure(
    string $method,
    string $url,
    int $httpcode,
    int $errno,
    string $curlerror,
    string $response
): never {
    $safeurl = mod_skilland_redact_url($url);
    if ($httpcode === 0 || $errno) {
        $details = $errno ? ' (errno: ' . $errno . ')' : '';
        if ($curlerror !== '' && $curlerror !== 'Unknown error') {
            $details .= ' ' . $curlerror;
        }
        logger::error('REST', 'Connection to Skilland at ' . $safeurl . ' failed: HTTP ' . $httpcode . $details);

        $refused = $errno === 7 || strpos($curlerror, 'Connection refused') !== false ||
            strpos($curlerror, 'Could not connect') !== false;
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($refused && ($host === 'localhost' || $host === '127.0.0.1')) {
            logger::debug('REST', 'If Moodle is running in Docker, use "host.docker.internal" instead of "' .
                $host . '" in the Skilland URL setting.');
        }
        throw new rest_exception('error_graphql_http', 0, 'HTTP 0');
    }

    logger::error('REST', 'HTTP error ' . $httpcode . ' from Skilland at ' . $method . ' ' . $safeurl);
    $apierror = '';
    if (trim($response) !== '') {
        logger::debug('REST', 'Response body: ' . substr($response, 0, 200));
        $decoded = json_decode($response, true);
        if (is_array($decoded) && is_string($decoded['error'] ?? null)) {
            $apierror = $decoded['error'];
        }
    }
    throw new rest_exception('error_graphql_http', $httpcode, 'HTTP ' . $httpcode, $apierror);
}

/**
 * Decode a 2xx REST body.
 *
 * @param string $response Raw response body.
 * @param int $httpcode HTTP status.
 * @param string $safeurl The URL without its query string, for the log.
 * @return array
 * @throws rest_exception error_graphql_invalid_json when the body is not a JSON object or array.
 */
function mod_skilland_rest_decode(string $response, int $httpcode, string $safeurl): array {
    $data = json_decode($response, true);
    if (!is_array($data)) {
        logger::error('REST', 'Invalid JSON from ' . $safeurl . ' (HTTP ' . $httpcode . ')');
        throw new rest_exception('error_graphql_invalid_json', $httpcode);
    }
    return $data;
}

/**
 * Path of a topic's Skilland REST route.
 *
 * @param string $topicid Skilland topic ID.
 * @param string $route Last path segment: scorm-hash, scorm or contents.
 * @return string
 */
function mod_skilland_topic_route(string $topicid, string $route): string {
    return '/api/moodle/topics/' . rawurlencode($topicid) . '/' . $route;
}

/**
 * Check if a topic's SCORM content has changed by querying the lightweight hash endpoint.
 *
 * Asks the REST route GET /api/moodle/topics/{id}/scorm-hash. Returns null if the lookup fails
 * (non-fatal for polling) or answers an empty body.
 *
 * @param string $topicid The Skilland topic ID.
 * @return array|null Hash info array with contentHash, packageHash, generatedAt, hasPackage, isStale — or null on failure.
 */
function mod_skilland_check_topic_snapshot(string $topicid): ?array {
    try {
        $result = mod_skilland_rest_get(mod_skilland_topic_route($topicid, 'scorm-hash'));
        return $result === [] ? null : $result;
    } catch (\Exception $e) {
        logger::warn('SnapshotCheck', 'Failed to check topic snapshot for ' . $topicid . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Adapt the skill rows of a REST listing ({skills: [...]}) to the course shape callers read.
 *
 * Skilland skills have no code, so `code` is the skill id.
 *
 * @param array $body The decoded REST body.
 * @return array[] Each ['id', 'name', 'code', 'status', 'description', 'language', 'category'].
 */
function mod_skilland_skill_rows(array $body): array {
    $rows = [];
    foreach (is_array($body['skills'] ?? null) ? $body['skills'] : [] as $skill) {
        if (!is_array($skill) || !isset($skill['id']) || !is_scalar($skill['id']) || (string) $skill['id'] === '') {
            continue;
        }
        $id = (string) $skill['id'];
        $rows[] = [
            'id' => $id,
            'name' => is_scalar($skill['name'] ?? null) ? (string) $skill['name'] : '',
            'code' => $id,
            'status' => is_scalar($skill['status'] ?? null) ? (string) $skill['status'] : '',
            'description' => is_scalar($skill['description'] ?? null) ? (string) $skill['description'] : '',
            'language' => is_scalar($skill['language'] ?? null) ? (string) $skill['language'] : '',
            'category' => is_scalar($skill['category'] ?? null) ? (string) $skill['category'] : '',
        ];
    }
    return $rows;
}

/**
 * Fetch every course (skill) of the configured organization, whatever its status.
 *
 * GET /api/moodle/skills?status=all.
 *
 * @return array Array of course arrays with id, name, code (the id) and status
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_courses(): array {
    $orgid = (string) (get_config('mod_skilland', 'orgid') ?? '');
    if ($orgid === '') {
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }

    return mod_skilland_skill_rows(mod_skilland_rest_get('/api/moodle/skills?status=all'));
}

/**
 * Name of a Skilland course (skill) of the organization, for display only.
 *
 * One GET /api/moodle/skills?status=all; any failure, or a skill missing from the list, yields ''.
 * Unlike mod_skilland_fetch_courses() it does not require the orgid setting: the REST API
 * identifies the organization by the API key alone.
 *
 * @param string $skillid Skilland course ID
 * @return string
 */
function mod_skilland_skill_name(string $skillid): string {
    try {
        foreach (mod_skilland_skill_rows(mod_skilland_rest_get('/api/moodle/skills?status=all')) as $course) {
            if ($course['id'] === $skillid) {
                return $course['name'];
            }
        }
    } catch (\Throwable $e) {
        logger::warn('locallib', 'Could not look up the name of skill ' . $skillid . ': ' . get_class($e));
    }
    return '';
}

/**
 * Fetch the Skilland courses that a user can edit, by their email.
 *
 * GET /api/moodle/users/courses?email=<lower-cased email>. Any failure yields an empty list.
 *
 * @param string $useremail Email of the user
 * @return array Array of course arrays with id, name, code (the id) and status
 * @throws moodle_exception error_config_missing_orgid
 */
function mod_skilland_fetch_user_courses(string $useremail): array {
    $orgid = (string) (get_config('mod_skilland', 'orgid') ?? '');
    if ($orgid === '') {
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }

    $email = strtolower(trim($useremail));
    if ($email === '') {
        return [];
    }

    try {
        return mod_skilland_skill_rows(mod_skilland_rest_get('/api/moodle/users/courses?email=' . rawurlencode($email)));
    } catch (moodle_exception $e) {
        logger::warn('locallib', 'Failed to fetch user courses: ' . $e->getMessage());
        return [];
    }
}

/**
 * Whether a path is a Skilland Studio path the plugin may send a user to after SSO.
 *
 * A strict allow-list of same-origin relative paths: /skills, /skills/<id>,
 * /skills/<id>?topic=<id> and /skills/new?draft=<id>, where every id only uses unreserved or
 * percent-encoded characters. Anything with a scheme, a host, "//", "..", a fragment or other
 * query parameters is refused.
 *
 * @param string $path
 * @return bool
 */
function mod_skilland_is_studio_path(string $path): bool {
    $id = '(?:[A-Za-z0-9_~-]|%[0-9A-Fa-f]{2})+';
    return preg_match('#^/skills(?:/new\?draft=' . $id . '|/' . $id . '(?:\?topic=' . $id . ')?)?$#D', $path) === 1;
}

/**
 * Create a new course (skill) in Skilland using the Moodle course name.
 *
 * POST /api/moodle/skills with { name, userEmail, moodleUserId, issuer }. moodleUserId and issuer
 * are the `sub` and `iss` the SSO token carries, so a user Skilland creates here is linked for SSO.
 * The POST is sent once and never retried.
 *
 * @param string $name Course name
 * @param string $useremail Email of the user creating the course
 * @param string $moodleuserid Moodle user id of that user, '' to leave it out
 * @return array Array with id, name and path (the Studio path of the new course)
 * @throws moodle_exception If API call fails
 */
function mod_skilland_create_course(string $name, string $useremail, string $moodleuserid = ''): array {
    global $CFG;

    if (trim($name) === '') {
        throw new moodle_exception('error', 'mod_skilland', '', null, 'Course name is required');
    }
    $email = strtolower(trim($useremail));
    if ($email === '') {
        throw new moodle_exception('error', 'mod_skilland', '', null, 'User email is required');
    }

    $body = ['name' => $name, 'userEmail' => $email];
    if ($moodleuserid !== '') {
        $body['moodleUserId'] = $moodleuserid;
        $body['issuer'] = (string) $CFG->wwwroot;
    }

    try {
        $created = mod_skilland_rest_post('/api/moodle/skills', $body);
    } catch (rest_exception $e) {
        mod_skilland_create_course_failure($e);
    }

    $id = $created['id'] ?? null;
    if (!is_scalar($id) || (string) $id === '') {
        logger::error('REST', 'Create course answer carries no id');
        throw new rest_exception('error_graphql_invalid_json', 201);
    }
    $id = (string) $id;

    $path = is_string($created['path'] ?? null) ? $created['path'] : '';
    if (!mod_skilland_is_studio_path($path)) {
        $path = '/skills/new?draft=' . rawurlencode($id);
    }

    return [
        'id' => $id,
        'name' => is_scalar($created['name'] ?? null) ? (string) $created['name'] : $name,
        'path' => $path,
    ];
}

/**
 * Throw the exception a failed course creation surfaces.
 *
 * The answer's `error` code picks a message the teacher can act on: 403 insufficient_role and
 * inactive_member, 409 name_taken and not_a_member, and 429 (rate limited). A 401/403 without one
 * of those codes is error_config_invalid_credentials; anything else keeps the rest_exception.
 *
 * @param rest_exception $e The REST failure.
 * @throws moodle_exception Always.
 */
function mod_skilland_create_course_failure(rest_exception $e): never {
    $byerror = [
        'insufficient_role' => 'error_create_insufficient_role',
        'inactive_member' => 'error_create_inactive_member',
        'name_taken' => 'error_create_name_taken',
        'not_a_member' => 'error_create_not_a_member',
    ];
    logger::warn('REST', 'Create course failed: HTTP ' . $e->httpcode .
        ($e->apierror !== '' ? ' (' . $e->apierror . ')' : ''));
    if (isset($byerror[$e->apierror]) && in_array($e->httpcode, [403, 409], true)) {
        throw new moodle_exception($byerror[$e->apierror], 'mod_skilland');
    }
    if ($e->httpcode === 429) {
        throw new moodle_exception('error_create_rate_limited', 'mod_skilland');
    }
    if ($e->httpcode === 401 || $e->httpcode === 403) {
        throw new moodle_exception('error_config_invalid_credentials', 'mod_skilland');
    }
    throw $e;
}

/**
 * Fetch the topics of a Skilland course (skill).
 *
 * GET /api/moodle/skills/{id}/topics, adapted to the shape callers read: the course id, an empty
 * name (the route returns no skill) and code (the id), and topics with id, name, code (the id) and
 * description. A skill outside the organization (HTTP 404) has no topics.
 *
 * @param string $courseid Skilland course ID
 * @return array ['id' => string, 'name' => string, 'code' => string, 'topics' => array[]]
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_topics(string $courseid): array {
    if (empty($courseid)) {
        throw new moodle_exception('error_config_missing_courseid', 'mod_skilland');
    }

    $course = ['id' => $courseid, 'name' => '', 'code' => $courseid, 'topics' => []];
    try {
        $body = mod_skilland_rest_get('/api/moodle/skills/' . rawurlencode($courseid) . '/topics');
    } catch (rest_exception $e) {
        if ($e->httpcode === 404) {
            return $course;
        }
        throw $e;
    }

    foreach (is_array($body['topics'] ?? null) ? $body['topics'] : [] as $topic) {
        if (!is_array($topic) || !isset($topic['id']) || !is_scalar($topic['id']) || (string) $topic['id'] === '') {
            continue;
        }
        $id = (string) $topic['id'];
        $course['topics'][] = [
            'id' => $id,
            'name' => is_scalar($topic['name'] ?? null) ? (string) $topic['name'] : '',
            'code' => $id,
            'description' => is_scalar($topic['description'] ?? null) ? (string) $topic['description'] : '',
        ];
    }
    return $course;
}

/**
 * Fetch the lessons of a Skilland topic.
 *
 * GET /api/moodle/topics/{id}/contents, keeping the lessons (type "lesson") with a body, in the
 * route's order: the same lessons the topic's SCORM package turns into SCOs. updatedAt stays the
 * ISO 8601 string the route returns. position is the lesson's 1-based index in the returned list,
 * the number in its lesson code (skilland_lesson_label()). A topic outside the organization
 * (HTTP 404) has no lessons.
 *
 * @param string $topicid Skilland topic ID
 * @return array Array of lesson arrays with id, name, updatedAt and position
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_lessons(string $topicid): array {
    if (empty($topicid)) {
        throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
    }

    try {
        $body = mod_skilland_rest_get(mod_skilland_topic_route($topicid, 'contents'));
    } catch (rest_exception $e) {
        if ($e->httpcode === 404) {
            return [];
        }
        throw $e;
    }

    $lessons = [];
    foreach (is_array($body['contents'] ?? null) ? $body['contents'] : [] as $content) {
        if (!is_array($content) || !isset($content['id']) || !is_scalar($content['id']) || (string) $content['id'] === '') {
            continue;
        }
        if (($content['type'] ?? 'lesson') !== 'lesson') {
            continue;
        }
        if (array_key_exists('content', $content) && ($content['content'] === null || $content['content'] === '')) {
            continue;
        }
        $lessons[] = [
            'id' => (string) $content['id'],
            'name' => is_scalar($content['name'] ?? null) ? (string) $content['name'] : '',
            'updatedAt' => is_scalar($content['updatedAt'] ?? null) ? (string) $content['updatedAt'] : '',
            'position' => count($lessons) + 1,
        ];
    }
    return $lessons;
}

/**
 * Fetch SCORM package information from Skilland for a topic (multi-SCO package).
 *
 * Asks the REST route GET /api/moodle/topics/{id}/scorm.
 *
 * @param string $topicid Skilland topic ID
 * @return array Array with packageUrl, packageSize, packageHash, generatedAt, expiresAt, mappings
 * @throws moodle_exception If API call fails or SCORM is not available
 */
function mod_skilland_fetch_topic_scorm(string $topicid): array {
    if (empty($topicid)) {
        throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
    }

    try {
        $scorm = mod_skilland_rest_get(mod_skilland_topic_route($topicid, 'scorm'));
    } catch (\Throwable $e) {
        mod_skilland_topic_scorm_rest_failure($topicid, $e);
    }

    if ($scorm === []) {
        throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
    }

    return mod_skilland_normalise_topic_scorm($scorm);
}

/**
 * Throw the exception a failed REST topic SCORM fetch surfaces.
 *
 * 409 (topic has no lessons) is error_scorm_not_available, 404 error_config_missing_topicid,
 * 401/403 error_config_invalid_credentials; configuration and other client errors keep their own
 * code; anything else is logged and becomes error_scorm_fetch_failed.
 *
 * @param string $topicid Skilland topic ID.
 * @param \Throwable $e The REST failure.
 * @throws moodle_exception Always.
 */
function mod_skilland_topic_scorm_rest_failure(string $topicid, \Throwable $e): never {
    if ($e instanceof rest_exception) {
        if ($e->httpcode === 409) {
            throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
        }
        if ($e->httpcode === 404) {
            throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
        }
        if ($e->httpcode === 401 || $e->httpcode === 403) {
            throw new moodle_exception('error_config_invalid_credentials', 'mod_skilland');
        }
    } else if (
        mod_skilland_is_client_error($e) ||
            ($e instanceof moodle_exception && str_starts_with((string) $e->errorcode, 'error_config_'))
    ) {
        throw $e;
    }
    logger::error('SCORM', 'Fetching the SCORM package of topic ' . $topicid . ' failed: ' . get_class($e) .
        ': ' . $e->getMessage());
    throw new moodle_exception('error_scorm_fetch_failed', 'mod_skilland');
}

/**
 * Normalise a topic SCORM REST answer to the shape callers use.
 *
 * @param array $scorm The raw package info.
 * @return array Array with packageUrl, packageSize, packageHash, generatedAt, expiresAt,
 *     mappings (lessonId => scoId) and the signature fields contentHash, signature and keyId
 *     (null when absent; see \mod_skilland\local\package_signature).
 */
function mod_skilland_normalise_topic_scorm(array $scorm): array {
    // Build mappings array: lessonId => scoId.
    // The mappings field comes as an array of objects.
    $mappings = [];
    if (!empty($scorm['mappings']) && is_array($scorm['mappings'])) {
        foreach ($scorm['mappings'] as $mapping) {
            // Handle both object and array formats.
            if (is_array($mapping)) {
                $lessonid = $mapping['lessonId'] ?? null;
                $scoid = $mapping['scoId'] ?? null;
            } else if (is_object($mapping)) {
                $lessonid = $mapping->lessonId ?? null;
                $scoid = $mapping->scoId ?? null;
            } else {
                continue;
            }
            if ($lessonid && $scoid && (is_string($lessonid) || is_int($lessonid))) {
                $mappings[$lessonid] = $scoid;
            }
        }
    }
    logger::debug('SCORM', 'Parsed ' . count($mappings) . ' lesson->SCO mappings from API');

    return [
        'packageUrl' => $scorm['packageUrl'] ?? '',
        'packageSize' => $scorm['packageSize'] ?? 0,
        'packageHash' => $scorm['packageHash'] ?? '',
        'generatedAt' => $scorm['generatedAt'] ?? '',
        'expiresAt' => $scorm['expiresAt'] ?? '',
        'mappings' => $mappings,
        'contentHash' => $scorm['contentHash'] ?? null,
        'signature' => $scorm['signature'] ?? null,
        'keyId' => $scorm['keyId'] ?? null,
    ];
}

/**
 * Load the core course and SCORM APIs used by provisioning.
 *
 * Each require is skipped when the API is already defined, so standalone tests can stub it.
 */
function skilland_require_scorm_apis(): void {
    global $CFG;

    if (!function_exists('course_delete_module')) {
        require_once($CFG->dirroot . '/course/lib.php');
    }
    if (!function_exists('create_module')) {
        require_once($CFG->dirroot . '/course/modlib.php');
    }
    if (!defined('SCORM_TYPE_LOCAL')) {
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
    }
    if (!defined('GRADESCOES')) {
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');
    }
    if (!function_exists('file_get_unused_draft_itemid')) {
        require_once($CFG->libdir . '/filelib.php');
    }
}

/**
 * Name of the hidden SCORM activity for a topic, capped at the 255 characters Moodle stores.
 *
 * @param string $name The skilland activity name.
 * @return string
 */
function skilland_scorm_module_name(string $name): string {
    $suffix = ' (SCORM)';
    return core_text::substr($name, 0, 255 - core_text::strlen($suffix)) . $suffix;
}

/**
 * Fetch the topic SCORM package info from Skilland, download the zip and verify its signature.
 *
 * Every path that imports a package (provisioning, the manual update, a topic change) goes
 * through here, so nothing reaches create_file_from_pathname() or the SCORM parser unless
 * \mod_skilland\local\package_signature::verify() accepted it for the requested topic (SKL-650).
 *
 * @param string $topicid Skilland topic id.
 * @return array ['path' => temp zip path (the caller deletes it), 'info' => package info with mappings]
 * @throws moodle_exception On any failure; the temp file is removed first.
 */
function skilland_download_topic_scorm_package(string $topicid): array {
    logger::debug('SCORM', 'Provisioning topic SCORM for topic ' . $topicid);

    try {
        $scorminfo = mod_skilland_fetch_topic_scorm($topicid);
    } catch (moodle_exception $e) {
        logger::error('SCORM', 'Failed to fetch topic SCORM info - ' . $e->getMessage());
        throw $e;
    }

    if (empty($scorminfo['packageUrl'])) {
        throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
    }

    // An unsigned answer is refused before anything is downloaded.
    try {
        package_signature::require_signed($scorminfo);
    } catch (moodle_exception $e) {
        logger::error('SCORM', 'Refusing the unsigned package of topic ' . $topicid);
        throw $e;
    }

    $packageurl = $scorminfo['packageUrl'];

    logger::debug('SCORM', 'Downloading topic SCORM package from ' . mod_skilland_redact_url($packageurl));

    $tempfile = mod_skilland_download_package($packageurl, (int) ($scorminfo['packageSize'] ?? 0));

    try {
        logger::debug('SCORM', 'Downloaded topic SCORM package (' . filesize($tempfile) . ' bytes)');
        package_signature::verify($topicid, $scorminfo, $tempfile);
        logger::debug('SCORM', 'Package signature verified (key ' . $scorminfo['keyId'] . ')');
    } catch (\Throwable $e) {
        @unlink($tempfile);
        logger::error('SCORM', 'Package of topic ' . $topicid . ' rejected: ' . $e->getMessage());
        throw $e;
    }

    return ['path' => $tempfile, 'info' => $scorminfo];
}

/**
 * Create the hidden (stealth) SCORM activity for a topic through core create_module().
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $course The Moodle course record.
 * @param int $sectionnum Section number the activity goes into.
 * @param string $packagepath Local path of the SCORM zip.
 * @return int The new SCORM course module id.
 * @throws moodle_exception When the package cannot be staged or the module cannot be created.
 */
function skilland_create_topic_scorm_module(
    stdClass $skilland,
    stdClass $course,
    int $sectionnum,
    string $packagepath
): int {
    global $DB, $USER;

    skilland_require_scorm_apis();

    $idnumber = 'skilland_topic_' . $skilland->id;
    $usercontext = context_user::instance($USER->id);
    $draftitemid = file_get_unused_draft_itemid();

    $storedfile = get_file_storage()->create_file_from_pathname([
        'contextid' => $usercontext->id,
        'component' => 'user',
        'filearea' => 'draft',
        'itemid' => $draftitemid,
        'filepath' => '/',
        'filename' => 'scorm_package.zip',
        'userid' => $USER->id,
    ], $packagepath);
    if (!$storedfile) {
        throw new moodle_exception('error_scorm_upload_failed', 'mod_skilland');
    }

    $moduleinfo = new stdClass();
    $moduleinfo->modulename = 'scorm';
    $moduleinfo->course = $course->id;
    $moduleinfo->section = $sectionnum;
    $moduleinfo->visible = 1;  // Available to students but not shown on course page (stealth mode).
    $moduleinfo->visibleoncoursepage = 0;
    $moduleinfo->idnumber = $idnumber;
    $moduleinfo->cmidnumber = $idnumber;
    $moduleinfo->name = skilland_scorm_module_name((string) $skilland->name);
    $moduleinfo->introeditor = ['text' => '', 'format' => FORMAT_HTML, 'itemid' => file_get_unused_draft_itemid()];
    $moduleinfo->groupmode = 0;
    $moduleinfo->groupingid = 0;
    $moduleinfo->completion = 0;
    $moduleinfo->scormtype = SCORM_TYPE_LOCAL;
    $moduleinfo->packagefile = $draftitemid;
    $moduleinfo->version = 'SCORM_1.2';
    // The Skilland activity owns the grade (SKL-668): the hidden SCORM keeps no grade item.
    $moduleinfo->maxgrade = 0;
    $moduleinfo->grademethod = GRADEHIGHEST;
    $moduleinfo->whatgrade = HIGHESTATTEMPT;
    $moduleinfo->maxattempt = 0;
    $moduleinfo->forcecompleted = 0;
    $moduleinfo->forcenewattempt = 0;
    $moduleinfo->lastattemptlock = 0;
    $moduleinfo->displayattemptstatus = 0;  // Don't show attempt status - managed by Skilland.
    $moduleinfo->displaycoursestructure = 0;
    $moduleinfo->updatefreq = SCORM_UPDATE_NEVER;
    $moduleinfo->skipview = 2;  // Skip entry page.
    $moduleinfo->hidebrowse = 1;
    $moduleinfo->hidetoc = SCORM_TOC_DISABLED;  // Hide TOC - Skilland manages navigation.
    $moduleinfo->nav = SCORM_NAV_DISABLED;  // No nav - single SCO per view.
    $moduleinfo->navpositionleft = -100;
    $moduleinfo->navpositiontop = -100;
    $moduleinfo->auto = 0;
    $moduleinfo->popup = 0;
    $moduleinfo->width = 100;
    $moduleinfo->height = 500;
    $moduleinfo->timeopen = 0;
    $moduleinfo->timeclose = 0;
    $moduleinfo->displayactivityname = 0;  // Don't show name - Skilland shows it.
    $moduleinfo->autocommit = 1;  // Auto-commit for better tracking.
    $moduleinfo->masteryoverride = 1;

    // A create_module() that fails half way can leave a course module behind; remember what
    // already carries our idnumber so only a module this call created is cleaned up.
    $cmconditions = ['course' => $course->id, 'idnumber' => $idnumber];
    $existing = array_keys($DB->get_records('course_modules', $cmconditions, '', 'id'));

    try {
        $created = create_module($moduleinfo);
    } catch (\Throwable $e) {
        $leftover = array_diff(array_keys($DB->get_records('course_modules', $cmconditions, '', 'id')), $existing);
        foreach ($leftover as $leftovercmid) {
            skilland_delete_scorm_module((int) $leftovercmid);
        }
        logger::error('SCORM', 'create_module failed - ' . $e->getMessage());
        throw $e;
    }

    $cmid = (int) $created->coursemodule;

    $visibleoncoursepage = $DB->get_field('course_modules', 'visibleoncoursepage', ['id' => $cmid]);
    if ($visibleoncoursepage === false || (int) $visibleoncoursepage !== 0) {
        set_coursemodule_visible($cmid, 1, 0);
    }

    logger::debug('SCORM', 'Created SCORM activity with cmid ' . $cmid . ' (stealth mode)');

    return $cmid;
}

/**
 * Delete a SCORM course module if it still exists, never throwing.
 *
 * Used as compensating cleanup, so a failure here is logged and swallowed rather than
 * masking the exception that triggered it.
 *
 * @param int $cmid The SCORM course module id.
 */
function skilland_delete_scorm_module(int $cmid): void {
    global $DB;

    if ($cmid <= 0) {
        return;
    }

    try {
        skilland_require_scorm_apis();
        if (!$DB->record_exists('course_modules', ['id' => $cmid])) {
            return;
        }
        course_delete_module($cmid);
        logger::debug('SCORM', 'Deleted SCORM activity cmid ' . $cmid);
    } catch (\Throwable $e) {
        debugging(
            'mod_skilland: could not delete SCORM course module ' . $cmid . ': ' . $e->getMessage(),
            DEBUG_DEVELOPER
        );
    }
}

/**
 * Map the activity's visible lessons to the SCOs Moodle parsed from the package.
 *
 * Every check runs before anything is written, so a failed check leaves the lessons untouched.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $scormid The scorm instance id.
 * @param array $mappings Skilland lesson id => SCO identifier, as returned by the API.
 * @throws moodle_exception error_scorm_parse_failed when no SCO is launchable or a mapped
 *         lesson's SCO identifier is missing from the parsed package.
 */
function skilland_map_topic_scos(stdClass $skilland, int $scormid, array $mappings): void {
    skilland_apply_topic_scos(skilland_resolve_topic_scos($skilland, $scormid, $mappings));
}

/**
 * Resolve the activity's visible lessons to the SCOs Moodle parsed from the package, writing nothing.
 *
 * Lets a replacement build validate its package while the lessons still point at the old SCORM.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $scormid The scorm instance id.
 * @param array $mappings Skilland lesson id => SCO identifier, as returned by the API.
 * @return array Lesson row id => ['scoid' => int, 'identifier' => string].
 * @throws moodle_exception error_scorm_parse_failed when no SCO is launchable or a mapped
 *         lesson's SCO identifier is missing from the parsed package.
 */
function skilland_resolve_topic_scos(stdClass $skilland, int $scormid, array $mappings): array {
    global $DB;

    $scos = $DB->get_records('scorm_scoes', ['scorm' => $scormid], 'id ASC');
    logger::debug('SCORM', 'Found ' . count($scos) . ' SCOs in parsed package');

    $launchable = false;
    $scobyidentifier = [];
    foreach ($scos as $sco) {
        if (!empty($sco->launch)) {
            $launchable = true;
        }
        if (!empty($sco->identifier)) {
            $scobyidentifier[$sco->identifier] = $sco->id;
        }
    }
    if (!$launchable) {
        logger::error('SCORM', 'Parsed package has no launchable SCO');
        throw new moodle_exception('error_scorm_parse_failed', 'mod_skilland', '', '');
    }

    logger::debug('SCORM', 'API provided ' . count($mappings) . ' lesson->SCO mappings');

    $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $skilland->id, 'visible' => 1]);
    logger::debug('SCORM', 'Found ' . count($lessons) . ' lessons to map');

    $resolved = [];
    $missing = [];
    foreach ($lessons as $lesson) {
        $lessonid = $lesson->skilland_lessonid;
        if (!isset($mappings[$lessonid])) {
            logger::warn('SCORM', 'No mapping found for lesson ' . $lessonid);
            continue;
        }
        $identifier = $mappings[$lessonid];
        if (!isset($scobyidentifier[$identifier])) {
            $missing[] = $identifier;
            continue;
        }
        $resolved[$lesson->id] = ['scoid' => $scobyidentifier[$identifier], 'identifier' => $identifier];
    }

    if ($missing) {
        logger::error('SCORM', 'SCO identifiers not found in parsed package: ' . implode(', ', $missing));
        throw new moodle_exception('error_scorm_parse_failed', 'mod_skilland', '', implode(', ', $missing));
    }

    return $resolved;
}

/**
 * Write a resolved lesson->SCO map (from skilland_resolve_topic_scos()) onto the lesson rows.
 *
 * @param array $resolved Lesson row id => ['scoid' => int, 'identifier' => string].
 */
function skilland_apply_topic_scos(array $resolved): void {
    global $DB;

    foreach ($resolved as $lessonrowid => $sco) {
        $DB->set_field('skilland_lesson', 'scoid', $sco['scoid'], ['id' => $lessonrowid]);
        $DB->set_field('skilland_lesson', 'sco_identifier', $sco['identifier'], ['id' => $lessonrowid]);
        logger::debug('SCORM', 'Mapped lesson row ' . $lessonrowid . ' to SCO ' . $sco['scoid'] .
            ' (identifier: ' . $sco['identifier'] . ')');
    }
}

/**
 * Clear the SCO mapping of every lesson of an activity.
 *
 * @param int $skillandid The skilland activity id.
 */
function skilland_clear_lesson_scos(int $skillandid): void {
    global $DB;

    $DB->set_field('skilland_lesson', 'scoid', null, ['skillandid' => $skillandid]);
    $DB->set_field('skilland_lesson', 'sco_identifier', null, ['skillandid' => $skillandid]);
}

/**
 * Take the per-activity provisioning lock.
 *
 * @param int $skillandid The skilland activity id.
 * @param int $timeout Seconds to wait for the lock.
 * @return \core\lock\lock
 * @throws moodle_exception error_provision_in_progress when another request holds it.
 */
function skilland_get_provision_lock(int $skillandid, int $timeout = 10) {
    $factory = \core\lock\lock_config::get_lock_factory('mod_skilland');
    $lock = $factory->get_lock('provision_' . $skillandid, $timeout);
    if (!$lock) {
        logger::warn('SCORM', 'Provisioning already in progress for skilland id ' . $skillandid);
        throw new moodle_exception('error_provision_in_progress', 'mod_skilland');
    }
    return $lock;
}

/**
 * Copy the SCORM provisioning fields of a record onto another.
 *
 * @param stdClass $from Source record.
 * @param stdClass $to Destination record.
 */
function skilland_copy_provisioning_fields(stdClass $from, stdClass $to): void {
    foreach (['scormcmid', 'scorm_provisioned', 'scomappings', 'snapshotcreatedat', 'snapshotid'] as $field) {
        $to->$field = $from->$field ?? null;
    }
}

/**
 * Provision a topic-level SCORM package containing all lessons as SCOs.
 *
 * Serialized per activity with a Moodle lock and idempotent: when the activity already has a
 * SCORM module that still exists, its cmid is returned and nothing is created.
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are updated.
 * @param stdClass $course The Moodle course record
 * @param int $sectionnum The section number to add the SCORM to
 * @param string|null $contenthash Pre-fetched content hash to store as snapshotid; fetched via
 *   mod_skilland_check_topic_snapshot() when null.
 * @return int The SCORM course module ID
 * @throws moodle_exception If provisioning fails or is already in progress
 */
function skilland_provision_topic_scorm($skilland, $course, $sectionnum = 0, ?string $contenthash = null) {
    global $DB;

    skilland_require_scorm_apis();

    $lock = skilland_get_provision_lock((int) $skilland->id);
    try {
        $current = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);

        if (!empty($current->scormcmid)) {
            $existing = get_coursemodule_from_id('scorm', $current->scormcmid, 0, false, IGNORE_MISSING);
            if ($existing) {
                logger::debug('SCORM', 'Activity ' . $skilland->id . ' already provisioned (cmid ' .
                    $current->scormcmid . ')');
                skilland_copy_provisioning_fields($current, $skilland);
                return (int) $current->scormcmid;
            }

            logger::warn('SCORM', 'scormcmid ' . $current->scormcmid . ' points at a missing module - re-provisioning');
            $DB->update_record('skilland', (object) [
                'id' => $current->id,
                'scormcmid' => null,
                'scorm_provisioned' => null,
                'scomappings' => null,
            ]);
            skilland_clear_lesson_scos((int) $current->id);
            $current->scormcmid = null;
            $current->scorm_provisioned = null;
            $current->scomappings = null;
        }

        $cmid = skilland_provision_topic_scorm_locked($current, $course, (int) $sectionnum, $contenthash);
        skilland_copy_provisioning_fields($current, $skilland);
        return $cmid;
    } finally {
        $lock->release();
    }
}

/**
 * Resolve the content hash to store as snapshotid, reusing a pre-fetched one when given.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param string|null $contenthash Pre-fetched content hash, or null to query it.
 * @return string The hash; an empty string when the lookup fails (never blocks provisioning).
 */
function skilland_resolve_snapshot_hash(stdClass $skilland, ?string $contenthash): string {
    if ($contenthash !== null) {
        return $contenthash;
    }
    $hashinfo = mod_skilland_check_topic_snapshot((string) $skilland->skilland_topicid);
    return is_array($hashinfo) ? (string) ($hashinfo['contentHash'] ?? '') : '';
}

/**
 * Build a topic SCORM module without linking it to the activity. The caller holds the lock.
 *
 * Download, create the hidden module through create_module() and resolve the lesson->SCO map
 * against it. Nothing is written to the skilland record or its lessons, so this is safe to run
 * while the activity still points at an existing SCORM (a replacement build). Any failure after
 * the module exists deletes it again; the downloaded package is always removed.
 *
 * @param stdClass $skilland The skilland activity record (read only).
 * @param stdClass $course The Moodle course record.
 * @param int $sectionnum Section number the SCORM goes into.
 * @return array ['cmid' => int, 'scos' => resolved lesson->SCO map, 'mappings' => API mappings,
 *   'generatedat' => int package generation time]
 * @throws moodle_exception If the download, module creation or SCO resolution fails.
 */
function skilland_build_topic_scorm(stdClass $skilland, stdClass $course, int $sectionnum): array {
    global $DB;

    $package = skilland_download_topic_scorm_package((string) $skilland->skilland_topicid);
    $scorminfo = $package['info'];

    try {
        $cmid = skilland_create_topic_scorm_module($skilland, $course, $sectionnum, $package['path']);

        try {
            $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
            $scos = skilland_resolve_topic_scos($skilland, $scormid, $scorminfo['mappings'] ?? []);
        } catch (\Throwable $e) {
            logger::error('SCORM', 'Build failed after creating cmid ' . $cmid . ' - rolling back: ' .
                $e->getMessage());
            skilland_delete_scorm_module($cmid);
            throw $e;
        }
    } finally {
        @unlink($package['path']);
    }

    return [
        'cmid' => $cmid,
        'scos' => $scos,
        'mappings' => $scorminfo['mappings'] ?? [],
        'generatedat' => !empty($scorminfo['generatedAt']) ? skilland_parse_timestamp($scorminfo['generatedAt']) : time(),
    ];
}

/**
 * Link a module built by skilland_build_topic_scorm() to the activity: replace every lesson's
 * SCO mapping and write the provisioning fields, snapshot included, in one place.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param array $build The skilland_build_topic_scorm() result.
 * @param string $contenthash Content hash stored as snapshotid.
 * @return stdClass The provisioning fields written.
 */
function skilland_link_topic_scorm(stdClass $skilland, array $build, string $contenthash): stdClass {
    global $DB;

    skilland_clear_lesson_scos((int) $skilland->id);
    skilland_apply_topic_scos($build['scos']);

    $fields = (object) [
        'id' => $skilland->id,
        'scormcmid' => $build['cmid'],
        'scorm_provisioned' => time(),
        'scomappings' => json_encode($build['mappings']),
        'snapshotcreatedat' => $build['generatedat'],
        'snapshotid' => $contenthash,
    ];
    // The update the teachers were told about is applied (or superseded) now (SKL-650).
    $DB->update_record('skilland', (object) ((array) $fields + ['updateavailable' => null]));

    return $fields;
}

/**
 * Provision the topic SCORM package on an activity with no SCORM. The caller holds the lock.
 *
 * Build the module (skilland_build_topic_scorm()), then link it. A failure while linking deletes
 * the new module and clears the lesson SCO mapping, so a failed run leaves nothing behind.
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are updated.
 * @param stdClass $course The Moodle course record.
 * @param int $sectionnum Section number the SCORM goes into.
 * @param string|null $contenthash Pre-fetched content hash to store as snapshotid; fetched via
 *   mod_skilland_check_topic_snapshot() when null (a failed fetch stores an empty string, never
 *   blocking provisioning).
 * @return int The new SCORM course module id.
 * @throws moodle_exception If provisioning fails.
 */
function skilland_provision_topic_scorm_locked(
    stdClass $skilland,
    stdClass $course,
    int $sectionnum,
    ?string $contenthash = null
): int {
    $contenthash = skilland_resolve_snapshot_hash($skilland, $contenthash);
    $build = skilland_build_topic_scorm($skilland, $course, $sectionnum);
    $cmid = $build['cmid'];

    try {
        $fields = skilland_link_topic_scorm($skilland, $build, $contenthash);
    } catch (\Throwable $e) {
        logger::error('SCORM', 'Provisioning failed after creating cmid ' . $cmid . ' - rolling back: ' .
            $e->getMessage());
        skilland_delete_scorm_module($cmid);
        skilland_clear_lesson_scos((int) $skilland->id);
        throw $e;
    }

    skilland_copy_provisioning_fields($fields, $skilland);
    logger::debug('SCORM', 'Updated skilland activity with scormcmid ' . $cmid);

    return $cmid;
}

/**
 * Set the Skilland Course ID value in the course custom field.
 *
 * @param int $courseid Moodle course ID
 * @param string $skillandcourseid Skilland course ID to set
 * @return bool True on success, false on failure
 */
function skilland_set_course_customfield_value(int $courseid, string $skillandcourseid): bool {
    try {
        $handler = \core_customfield\handler::get_handler('core_course', 'course');
        $data = $handler->get_instance_data($courseid, true);

        foreach ($data as $datarecord) {
            $field = $datarecord->get_field();
            if ($field && $field->get('shortname') === 'skilland_course_id') {
                skilland_save_course_customfield_data($datarecord, $courseid, $skillandcourseid);
                return true;
            }
        }

        // Field not found, try to ensure it exists first.
        $field = skilland_ensure_course_customfield();
        if ($field) {
            // Try again after ensuring field exists.
            $data = $handler->get_instance_data($courseid, true);
            foreach ($data as $datarecord) {
                $field = $datarecord->get_field();
                if ($field && $field->get('shortname') === 'skilland_course_id') {
                    skilland_save_course_customfield_data($datarecord, $courseid, $skillandcourseid);
                    return true;
                }
            }
        }

        return false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Write a course custom field value the way core_customfield's form save does.
 *
 * The value goes to the field type's own column (datafield(): charvalue for text, intvalue for
 * select, ...) as well as to value, and a record that does not exist yet gets the course context,
 * without which it cannot be saved.
 *
 * @param \core_customfield\data_controller $data Data controller for the course and field.
 * @param int $courseid Moodle course id the data belongs to.
 * @param string $value Value to store.
 */
function skilland_save_course_customfield_data(\core_customfield\data_controller $data, int $courseid, string $value): void {
    if (!$data->get('id')) {
        $data->set('contextid', \context_course::instance($courseid)->id);
    }
    $data->set($data->datafield(), $value);
    $data->set('value', $value);
    $data->save();
}

/**
 * Update an existing topic SCORM package with the latest content from Skilland.
 *
 * Under the same per-activity lock as provisioning, this function:
 * 1. Fetches the current lessons from Skilland (before the build, so a stamp is never newer
 *    than the packaged content)
 * 2. Builds the new SCORM module unlinked (skilland_build_topic_scorm()); a failure here deletes
 *    only the half-built module and leaves the old SCORM, its attempts and the lesson->SCO
 *    mapping untouched
 * 3. Links the new module (lesson SCOs, provisioning fields, snapshot); a failure here deletes
 *    the new module and restores the old links
 * 4. Only then deletes the old SCORM activity and all its tracking data
 * 5. Stamps each lesson's updatedat with the fetched value
 *
 * WARNING: A successful update deletes all student progress/grades of the old SCORM!
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are updated.
 * @param stdClass $course The Moodle course record
 * @param int $sectionnum The section number for the SCORM
 * @param string|null $contenthash Pre-fetched content hash (e.g. already read by the sync task)
 *   to store as snapshotid; fetched via mod_skilland_check_topic_snapshot() when null.
 * @return int The new SCORM course module ID
 * @throws moodle_exception If update fails or provisioning is already in progress
 */
function skilland_update_topic_scorm($skilland, $course, $sectionnum = 0, ?string $contenthash = null) {
    global $DB;

    skilland_require_scorm_apis();

    logger::debug('SCORM', 'Updating topic SCORM for skilland id ' . $skilland->id);

    $lock = skilland_get_provision_lock((int) $skilland->id);
    try {
        $current = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $oldcmid = (int) ($current->scormcmid ?? 0);

        // Step 1: Fetch fresh lesson data from Skilland to get updated timestamps.
        $lessons = mod_skilland_fetch_lessons($current->skilland_topicid);
        $contenthash = skilland_resolve_snapshot_hash($current, $contenthash);

        // Step 2: Build the new SCORM unlinked; the old one is not touched if this fails.
        $build = skilland_build_topic_scorm($current, $course, (int) $sectionnum);
        $newcmid = $build['cmid'];

        // Step 3: Link the new module in, restoring the old links if that fails.
        $oldlessons = $DB->get_records('skilland_lesson', ['skillandid' => $current->id], '', 'id, scoid, sco_identifier');
        try {
            $fields = skilland_link_topic_scorm($current, $build, $contenthash);
        } catch (\Throwable $e) {
            logger::error('SCORM', 'Linking new cmid ' . $newcmid . ' failed - keeping cmid ' . $oldcmid . ': ' .
                $e->getMessage());
            skilland_delete_scorm_module($newcmid);
            $restore = (object) ['id' => $current->id];
            skilland_copy_provisioning_fields($current, $restore);
            $DB->update_record('skilland', $restore);
            foreach ($oldlessons as $oldlesson) {
                $DB->update_record('skilland_lesson', $oldlesson);
            }
            throw $e;
        }
        skilland_copy_provisioning_fields($fields, $current);
        skilland_copy_provisioning_fields($current, $skilland);

        // Step 4: The new SCORM is live; only now delete the old one and its tracking data.
        if ($oldcmid > 0 && $oldcmid !== $newcmid) {
            logger::debug('SCORM', 'Deleting old SCORM activity cmid ' . $oldcmid);
            skilland_delete_scorm_module($oldcmid);
        }

        // Step 5: updatedat = version of the lesson in the installed package; only a successful
        // build advances it.
        // The Skilland position (the lesson code) is refreshed too, so a reorder lands here.
        foreach ($lessons as $lesson) {
            $updatedat = !empty($lesson['updatedAt']) ? skilland_parse_timestamp($lesson['updatedAt']) : time();
            $where = [
                'skillandid' => $current->id,
                'skilland_lessonid' => $lesson['id'],
            ];
            $DB->set_field('skilland_lesson', 'updatedat', $updatedat, $where);
            $position = (int) ($lesson['position'] ?? 0);
            if ($position > 0) {
                $DB->set_field('skilland_lesson', 'skillandposition', $position, $where);
            }
        }
        logger::debug('SCORM', 'Updated lesson timestamps and positions from Skilland API');
    } finally {
        $lock->release();
    }

    logger::debug('SCORM', 'Topic SCORM updated successfully, new cmid = ' . $newcmid);

    return $newcmid;
}

/**
 * Return an activity to the unprovisioned ("Provision") state: delete its SCORM module and clear
 * the provisioning fields, the snapshot and every lesson's SCO mapping. Idempotent.
 *
 * Used when a topic change could not re-provision, so no lesson keeps pointing at a SCO of the
 * old topic's package.
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are cleared.
 * @throws moodle_exception error_provision_in_progress when another request holds the lock.
 */
function skilland_reset_topic_scorm(stdClass $skilland): void {
    global $DB;

    require_once(__DIR__ . '/lib.php');

    $lock = skilland_get_provision_lock((int) $skilland->id);
    try {
        $current = $DB->get_record('skilland', ['id' => $skilland->id], 'id, scormcmid', IGNORE_MISSING);
        if (!$current) {
            return;
        }
        if (!empty($current->scormcmid)) {
            logger::debug('SCORM', 'Resetting skilland id ' . $current->id . ': deleting SCORM cmid ' .
                $current->scormcmid);
            skilland_delete_scorm_module((int) $current->scormcmid);
        }
        skilland_unlink_scorm((int) $current->id);
    } finally {
        $lock->release();
    }

    skilland_copy_provisioning_fields(new stdClass(), $skilland);
    $skilland->snapshotid = null;
}

/**
 * Give visible lessons that have no SCO yet the SCO of the installed package.
 *
 * A lesson ticked after provisioning is inserted without a scoid; its SCO identifier comes from
 * the lesson's own sco_identifier or the package's stored lesson -> SCO map (scomappings), and is
 * looked up in the SCOs Moodle parsed from that package. Nothing is downloaded or created.
 * Skips (returns []) when the provisioning lock stays busy, since the in-flight provision maps
 * the visible lessons itself, and when the linked SCORM module is gone.
 *
 * @param int $skillandid The skilland activity id.
 * @return string[] Skilland lesson ids that could not be resolved.
 */
function skilland_resolve_lesson_scos(int $skillandid): array {
    global $DB;

    require_once(__DIR__ . '/lib.php');

    try {
        $lock = skilland_get_provision_lock($skillandid, 2);
    } catch (\moodle_exception $e) {
        logger::warn('SCORM', 'Provisioning lock busy - not resolving lesson SCOs for skilland id ' . $skillandid);
        return [];
    }

    try {
        $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', IGNORE_MISSING);
        if (!$skilland) {
            return [];
        }
        $scormcm = skilland_get_linked_scorm_cm($skilland);
        if (!$scormcm) {
            return [];
        }

        $pending = [];
        foreach ($DB->get_records('skilland_lesson', ['skillandid' => $skillandid, 'visible' => 1]) as $lesson) {
            if (empty($lesson->scoid)) {
                $pending[] = $lesson;
            }
        }
        if (!$pending) {
            return [];
        }

        $mappings = json_decode((string) ($skilland->scomappings ?? ''), true);
        if (!is_array($mappings)) {
            $mappings = [];
        }

        $scobyidentifier = [];
        foreach ($DB->get_records('scorm_scoes', ['scorm' => $scormcm->instance], '', 'id, identifier') as $sco) {
            if (!empty($sco->identifier)) {
                $scobyidentifier[(string) $sco->identifier] = (int) $sco->id;
            }
        }

        $unresolved = [];
        foreach ($pending as $lesson) {
            $lessonid = (string) $lesson->skilland_lessonid;
            $identifier = !empty($lesson->sco_identifier) ? (string) $lesson->sco_identifier
                : (string) ($mappings[$lessonid] ?? '');
            if ($identifier === '' || !isset($scobyidentifier[$identifier])) {
                $unresolved[] = $lessonid;
                continue;
            }
            $DB->set_field('skilland_lesson', 'scoid', $scobyidentifier[$identifier], ['id' => $lesson->id]);
            $DB->set_field('skilland_lesson', 'sco_identifier', $identifier, ['id' => $lesson->id]);
            logger::debug('SCORM', 'Resolved lesson ' . $lessonid . ' to SCO ' . $scobyidentifier[$identifier]);
        }

        if ($unresolved) {
            logger::warn('SCORM', 'Lessons not in the installed package of skilland id ' . $skillandid . ': ' .
                implode(', ', $unresolved));
        }
        return $unresolved;
    } finally {
        $lock->release();
    }
}

/**
 * SCORM lesson statuses, ranked: the progress store only ever moves a lesson up this ladder.
 *
 * @param string|null $status A normalised status (see skilland_normalise_scorm_status()).
 * @return int 0 not started, 1 in progress, 2 failed, 3 completed or passed.
 */
function skilland_progress_status_rank(?string $status): int {
    switch ((string) $status) {
        case 'completed':
        case 'passed':
            return 3;
        case 'failed':
            return 2;
        case 'incomplete':
        case 'browsed':
            return 1;
        default:
            return 0;
    }
}

/**
 * Normalise a SCORM status track value: lower case, and "not attempted" / "unknown" / empty as
 * not_started.
 *
 * @param string $value The raw cmi.core.lesson_status / cmi.completion_status value.
 * @return string
 */
function skilland_normalise_scorm_status(string $value): string {
    $status = strtolower(trim($value));
    if ($status === '' || $status === 'not attempted' || $status === 'unknown') {
        return 'not_started';
    }
    return core_text::substr($status, 0, 20);
}

/**
 * Normalise the score elements of one lesson to a 0-100 percentage where the scale is known.
 *
 * Scaled (SCORM 2004, -1..1) wins; else (raw - min) / (max - min) * 100 when max is numeric and
 * above min (min defaults to 0), clamped to 0-100. With no usable scale the raw value is kept
 * as is (a 0-100 raw is what SCORM 1.2 assumes; the lesson list shows a value outside that
 * range without a percent sign).
 *
 * @param array $values Latest value per element: cmi.[core.]score.raw|min|max, cmi.score.scaled.
 * @return array ['score' => ?float]
 */
function skilland_normalise_scorm_score(array $values): array {
    $num = static function (array $keys) use ($values): ?float {
        foreach ($keys as $key) {
            if (isset($values[$key]) && is_numeric($values[$key])) {
                return (float) $values[$key];
            }
        }
        return null;
    };
    $scaled = $num(['cmi.score.scaled']);
    if ($scaled !== null) {
        return ['score' => max(0.0, min(100.0, $scaled * 100))];
    }
    $raw = $num(['cmi.core.score.raw', 'cmi.score.raw']);
    if ($raw === null) {
        return ['score' => null];
    }
    $max = $num(['cmi.core.score.max', 'cmi.score.max']);
    $min = $num(['cmi.core.score.min', 'cmi.score.min']) ?? 0.0;
    if ($max !== null && $max > $min) {
        return ['score' => max(0.0, min(100.0, ($raw - $min) / ($max - $min) * 100))];
    }
    return ['score' => $raw];
}

/**
 * Pick the status of one lesson: a SCORM 2004 passed/failed success status wins over completion.
 *
 * @param ?string $success The cmi.success_status value, if any.
 * @param ?string $completion The normalised lesson/completion status, if any.
 * @return ?string
 */
function skilland_resolve_scorm_status(?string $success, ?string $completion): ?string {
    $success = strtolower(trim((string) $success));
    if ($success === 'passed' || $success === 'failed') {
        return $success;
    }
    return $completion;
}

/**
 * Read lesson status and raw score from the tracks of the activity's current topic SCORM.
 *
 * One query per SCORM for every requested user. Each user's latest attempt that reported a value
 * wins, per SCO and per kind (status, score). SCOs map to lessons through skilland_lesson.scoid.
 * Needs the Moodle 4.3+ scorm_attempt / scorm_scoes_value tables; returns [] without them, when
 * the SCORM is gone, or when no lesson has a SCO.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int[]|null $userids Users to read, or null for every user with tracks.
 * @return array [userid => [lesson row id => ['status' => ?string, 'score' => ?float]]]
 */
function skilland_read_scorm_progress(stdClass $skilland, ?array $userids = null): array {
    global $DB;

    require_once(__DIR__ . '/lib.php');

    if ($userids !== null && !$userids) {
        return [];
    }
    $dbman = $DB->get_manager();
    if (!$dbman->table_exists('scorm_scoes_value') || !$dbman->table_exists('scorm_attempt')) {
        return [];
    }
    $scormcm = skilland_get_linked_scorm_cm($skilland);
    if (!$scormcm) {
        return [];
    }

    $scotolesson = [];
    foreach ($DB->get_records('skilland_lesson', ['skillandid' => $skilland->id], '', 'id, scoid') as $lesson) {
        if (!empty($lesson->scoid)) {
            $scotolesson[(int) $lesson->scoid] = (int) $lesson->id;
        }
    }
    if (!$scotolesson) {
        return [];
    }

    $statuselements = ['cmi.core.lesson_status', 'cmi.completion_status'];
    $scoreelements = [
        'cmi.core.score.raw', 'cmi.score.raw', 'cmi.core.score.max', 'cmi.core.score.min',
        'cmi.score.max', 'cmi.score.min', 'cmi.score.scaled',
    ];
    $successelement = 'cmi.success_status';

    [$scosql, $params] = $DB->get_in_or_equal(array_keys($scotolesson), SQL_PARAMS_NAMED, 'sco');
    [$elementsql, $elementparams] = $DB->get_in_or_equal(
        array_merge($statuselements, $scoreelements, [$successelement]),
        SQL_PARAMS_NAMED,
        'el'
    );
    $params += $elementparams;
    $params['scormid'] = (int) $scormcm->instance;
    $usersql = '';
    if ($userids !== null) {
        [$insql, $userparams] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED, 'usr');
        $usersql = "AND a.userid $insql";
        $params += $userparams;
    }

    $sql = "SELECT ssv.id, a.userid, a.attempt, ssv.scoid, e.element, ssv.value
              FROM {scorm_attempt} a
              JOIN {scorm_scoes_value} ssv ON ssv.attemptid = a.id
              JOIN {scorm_element} e ON e.id = ssv.elementid
             WHERE a.scormid = :scormid
                   AND ssv.scoid $scosql
                   AND e.element $elementsql
                   $usersql
          ORDER BY a.userid ASC, a.attempt DESC, ssv.timemodified DESC, ssv.id DESC";
    try {
        $tracks = $DB->get_records_sql($sql, $params);
    } catch (\Throwable $e) {
        logger::error('Progress', 'Reading SCORM tracks of skilland id ' . $skilland->id . ' failed - ' .
            $e->getMessage());
        return [];
    }

    $progress = [];
    $scorevalues = [];
    $success = [];
    foreach ($tracks as $track) {
        $lessonid = $scotolesson[(int) $track->scoid] ?? null;
        if ($lessonid === null) {
            continue;
        }
        $userid = (int) $track->userid;
        if (!isset($progress[$userid][$lessonid])) {
            $progress[$userid][$lessonid] = ['status' => null, 'score' => null];
        }
        if (in_array($track->element, $statuselements, true)) {
            if ($progress[$userid][$lessonid]['status'] === null) {
                $progress[$userid][$lessonid]['status'] = skilland_normalise_scorm_status((string) $track->value);
            }
        } else if ($track->element === $successelement) {
            $success[$userid][$lessonid] ??= (string) $track->value;
        } else {
            $scorevalues[$userid][$lessonid][$track->element] ??= $track->value;
        }
    }
    foreach ($progress as $userid => $lessons) {
        foreach ($lessons as $lessonid => $entry) {
            $progress[$userid][$lessonid]['status'] = skilland_resolve_scorm_status(
                $success[$userid][$lessonid] ?? null,
                $entry['status']
            );
            $progress[$userid][$lessonid]['score'] =
                skilland_normalise_scorm_score($scorevalues[$userid][$lessonid] ?? [])['score'];
        }
    }

    return $progress;
}

/**
 * The users with a SCORM attempt on the activity's linked SCORM who are learners, not staff.
 *
 * Staff are users holding moodle/course:manageactivities in the SCORM's module context (site
 * admins have every capability), so a teacher previewing the content never counts. Reads
 * {scorm_attempt} (Moodle 4.3+) and falls back to {scorm_scoes_track}.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param bool $stopatfirst Return after the first learner found (enough to know there is one).
 * @return int[] Learner user ids; empty when the SCORM is gone, nobody attempted it, or the
 *         query fails.
 */
function skilland_learner_attempt_userids(stdClass $skilland, bool $stopatfirst = false): array {
    global $DB;

    $scormcm = skilland_get_linked_scorm_cm($skilland);
    if (!$scormcm) {
        return [];
    }

    try {
        $table = $DB->get_manager()->table_exists('scorm_attempt') ? 'scorm_attempt' : 'scorm_scoes_track';
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM {{$table}} WHERE scormid = :scormid ORDER BY userid",
            ['scormid' => (int) $scormcm->instance]
        );
        $context = \context_module::instance($scormcm->id);
        $learners = [];
        foreach ($userids as $userid) {
            if (has_capability('moodle/course:manageactivities', $context, (int) $userid)) {
                continue;
            }
            $learners[] = (int) $userid;
            if ($stopatfirst) {
                break;
            }
        }
        return $learners;
    } catch (\Throwable $e) {
        logger::error('Progress', 'Reading SCORM attempts for skilland id ' . $skilland->id . ' failed - ' .
            $e->getMessage());
        return [];
    }
}

/**
 * Count the distinct learners who have at least one SCORM attempt on the activity's currently
 * linked topic. Used to size the destructive-confirmation copy shown before an update or a topic
 * change would delete that progress (SKL-697). Staff attempts are not counted (SKL-677).
 *
 * @param stdClass $skilland The skilland activity record.
 * @return int Number of distinct learners with a SCORM attempt, or 0 when the SCORM is gone or
 *         the query fails.
 */
function skilland_count_topic_student_attempts(stdClass $skilland): int {
    return count(skilland_learner_attempt_userids($skilland));
}

/**
 * Merge a user's current SCORM tracks into the progress store, monotonically.
 *
 * Rows are keyed by the skilland_lesson row, so they survive re-provisioning (new SCORM, new
 * SCO ids, empty tracks). A status only moves up (see skilland_progress_status_rank()) and the
 * score keeps its maximum.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param int $userid The learner.
 * @param array|null $tracks That user's entry of skilland_read_scorm_progress(), or null to read it.
 * @param array|null $existing That user's stored rows keyed by lesson row id (an entry of
 *        skilland_get_progress_rows_by_user()), or null to read them.
 * @return bool Whether any row was inserted or changed.
 */
function skilland_refresh_progress(
    stdClass $skilland,
    int $userid,
    ?array $tracks = null,
    ?array $existing = null
): bool {
    global $DB;

    if ($tracks === null) {
        $tracks = skilland_read_scorm_progress($skilland, [$userid])[$userid] ?? [];
    }
    if (!$tracks) {
        return false;
    }

    if ($existing === null) {
        $existing = $DB->get_records(
            'skilland_progress',
            ['skillandid' => $skilland->id, 'userid' => $userid],
            '',
            'lessonid, id, status, score'
        );
    }
    $changed = false;
    $now = time();

    foreach ($tracks as $lessonid => $track) {
        $status = $track['status'] ?? null;
        $score = $track['score'] ?? null;

        $row = $existing[$lessonid] ?? null;
        if ($row) {
            $update = (object) ['id' => $row->id];
            if ($status !== null && skilland_progress_status_rank($status) > skilland_progress_status_rank($row->status)) {
                $update->status = $status;
            }
            if ($score !== null && ($row->score === null || $row->score === '' || $score > (float) $row->score)) {
                $update->score = $score;
            }
            if (count((array) $update) > 1) {
                $update->timemodified = $now;
                $DB->update_record('skilland_progress', $update);
                $changed = true;
            }
            continue;
        }

        if ($status === null && $score === null) {
            continue;
        }
        try {
            $DB->insert_record('skilland_progress', (object) [
                'skillandid' => (int) $skilland->id,
                'lessonid' => (int) $lessonid,
                'userid' => $userid,
                'status' => $status ?? 'not_started',
                'score' => $score,
                'timemodified' => $now,
            ]);
            $changed = true;
        } catch (\dml_exception $e) {
            // A concurrent refresh inserted the row first; the next refresh merges into it.
            logger::warn('Progress', 'Could not store progress of lesson ' . $lessonid . ' for user ' . $userid .
                ' - ' . $e->getMessage());
        }
    }

    return $changed;
}

/**
 * Every stored progress row of an activity, in one read.
 *
 * @param int $skillandid The skilland activity id.
 * @return array [userid => [lesson row id => row]]
 */
function skilland_get_progress_rows_by_user(int $skillandid): array {
    global $DB;

    $byuser = [];
    foreach (
        $DB->get_records(
            'skilland_progress',
            ['skillandid' => $skillandid],
            '',
            'id, userid, lessonid, status, score'
        ) as $row
    ) {
        $byuser[(int) $row->userid][(int) $row->lessonid] = $row;
    }
    return $byuser;
}

/**
 * A user's stored lesson progress for an activity.
 *
 * @param int $skillandid The skilland activity id.
 * @param int $userid The learner.
 * @return array [lesson row id => ['status' => string, 'score' => string|null]]; lessons without a
 *         row are absent (not started).
 */
function skilland_get_user_progress(int $skillandid, int $userid): array {
    global $DB;

    $progress = [];
    $rows = $DB->get_records(
        'skilland_progress',
        ['skillandid' => $skillandid, 'userid' => $userid],
        '',
        'lessonid, status, score'
    );
    foreach ($rows as $row) {
        $score = null;
        if ($row->score !== null && $row->score !== '') {
            $score = rtrim(rtrim(sprintf('%.5F', (float) $row->score), '0'), '.');
        }
        $progress[(int) $row->lessonid] = ['status' => (string) $row->status, 'score' => $score];
    }
    return $progress;
}

/**
 * Why a Moodle account may not be signed in to Skilland, read fresh from the database.
 *
 * The session copy of the user can be stale: the account may have been suspended, deleted,
 * unconfirmed or switched to the nologin auth method since the user signed in to Moodle.
 *
 * @param int $userid The Moodle user id
 * @return string|null null when the account may sign in, otherwise a short reason for the log
 */
function skilland_sso_user_refusal_reason(int $userid): ?string {
    global $DB;

    if ($userid <= 0) {
        return 'notloggedin';
    }
    $account = $DB->get_record('user', ['id' => $userid], 'id, auth, confirmed, deleted, suspended');
    if (!$account) {
        return 'missing';
    }
    if (isguestuser($account)) {
        return 'guest';
    }
    if (!empty($account->deleted)) {
        return 'deleted';
    }
    if (!empty($account->suspended)) {
        return 'suspended';
    }
    if (empty($account->confirmed)) {
        return 'unconfirmed';
    }
    if ($account->auth === 'nologin') {
        return 'nologin';
    }
    return null;
}

/**
 * The Skilland role an SSO token carries, from whether the user may add Skilland activities.
 *
 * Only a user who can add the activity (a teacher) is signed in as an Expert; everyone else who
 * reaches the handoff is a Learner (SKL-645).
 *
 * @param bool $hasaddinstance Whether the user holds mod/skilland:addinstance in the course
 * @return string 'Expert' or 'Learner'
 */
function skilland_determine_sso_role(bool $hasaddinstance): string {
    return $hasaddinstance ? 'Expert' : 'Learner';
}

/**
 * The Skilland role an SSO token carries for the current user in a course context.
 *
 * @param context $context The course context the handoff was started from
 * @return string 'Expert' or 'Learner'
 */
function skilland_sso_role_for_context(context $context): string {
    return skilland_determine_sso_role(has_capability('mod/skilland:addinstance', $context));
}

/**
 * Generate an SSO token for authenticating a Moodle user to Skilland.
 *
 * This function creates a signed JWT token that allows seamless authentication
 * from Moodle to Skilland without requiring the user to log in again. Skilland binds
 * the login to the token's issuer (this site's wwwroot) and subject (the Moodle user id).
 *
 * @param stdClass $user The Moodle user object
 * @param string $orgid The Skilland organization ID
 * @param string $role The Skilland role the token carries, 'Learner' or 'Expert' (see skilland_sso_role_for_context())
 * @return string The signed JWT token
 * @throws coding_exception If the role is not 'Learner' or 'Expert'
 * @throws moodle_exception If the account may not sign in (guest, deleted, suspended, unconfirmed
 *         or nologin), the SSO secret is not configured or the JWT library is not available
 */
function skilland_generate_sso_token($user, $orgid, string $role) {
    global $CFG;

    // Never fail open: a token only ever carries one of the two roles a Moodle user may hold.
    if ($role !== 'Learner' && $role !== 'Expert') {
        throw new coding_exception('Invalid Skilland SSO role: ' . $role);
    }

    $refusal = skilland_sso_user_refusal_reason((int) $user->id);
    if ($refusal !== null) {
        logger::warn('SSO', 'Refused token for user id ' . (int) $user->id . ': ' . $refusal);
        throw new moodle_exception('error_sso_user_not_allowed', 'mod_skilland');
    }

    // Check if composer autoloader exists.
    $autoloadpath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadpath)) {
        throw new moodle_exception(
            'error',
            'mod_skilland',
            '',
            null,
            'JWT library not installed. Please run "composer install" in the plugin directory.'
        );
    }

    require_once($autoloadpath);

    // Check if JWT class is available.
    if (!class_exists('\Firebase\JWT\JWT')) {
        throw new moodle_exception(
            'error',
            'mod_skilland',
            '',
            null,
            'JWT library not found. Please run "composer install" in the plugin directory.'
        );
    }

    // Get SSO secret from config.
    $ssosecret = get_config('mod_skilland', 'sso_secret');
    if (empty($ssosecret)) {
        throw new moodle_exception(
            'error',
            'mod_skilland',
            '',
            null,
            'SSO Shared Secret is not configured. Please set it in the plugin settings.'
        );
    }

    // Only courses the user can author in: a student enrolment must not make an Expert a
    // collaborator on that course's skill (SKL-999), the same capability that derives the role.
    $enrolledcourses = enrol_get_users_courses($user->id, true);

    $courseaccess = [];
    foreach ($enrolledcourses as $course) {
        if (!has_capability('mod/skilland:addinstance', context_course::instance($course->id), $user)) {
            continue;
        }
        // Get Skilland skill ID from course custom field.
        $skillandskillid = skilland_get_course_customfield_value($course->id);

        if ($skillandskillid) {
            $courseaccess[] = [
                'moodleCourseId' => (int)$course->id,
                'skillandSkillId' => $skillandskillid,
            ];
        }
    }

    // Generate a unique nonce for this token.
    $nonce = bin2hex(random_bytes(16));

    // Prepare token payload.
    $issuedat = time();
    $payload = [
        'sub' => (string) $user->id,
        'email' => $user->email,
        'name' => fullname($user),
        'orgId' => $orgid,
        'role' => $role,
        'nonce' => $nonce,
        'iat' => $issuedat,
        'exp' => $issuedat + 60, // The token only has to survive the auto-submitted form.
        'aud' => skilland_get_sso_audience(),
        'iss' => $CFG->wwwroot,
        'source' => 'moodle',
        'courseAccess' => $courseaccess,
    ];

    // Sign and return the token.
    try {
        $token = \Firebase\JWT\JWT::encode($payload, $ssosecret, 'HS256');
        logger::debug('SSO', 'Generated token for user id ' . $user->id);
        return $token;
    } catch (Exception $e) {
        logger::error('SSO', 'Failed to generate token - ' . $e->getMessage());
        throw new moodle_exception(
            'error',
            'mod_skilland',
            '',
            null,
            'Failed to generate SSO token: ' . $e->getMessage()
        );
    }
}

/**
 * Get the Skilland base URL the plugin talks to (REST API, SSO, Studio links), without a trailing slash.
 *
 * Uses the optional frontend_url override, else the Skilland URL setting (graphql_endpoint), both
 * normalised by \mod_skilland\local\skilland_url::normalise(), else MOD_SKILLAND_DEFAULT_URL.
 *
 * @return string
 */
function skilland_get_frontend_url(): string {
    $frontendurl = \mod_skilland\local\skilland_url::normalise((string) get_config('mod_skilland', 'frontend_url'));
    if ($frontendurl === '') {
        $frontendurl = mod_skilland_get_skilland_url();
    }
    return $frontendurl !== '' ? $frontendurl : MOD_SKILLAND_DEFAULT_URL;
}

/**
 * Drop a Frontend URL that only holds the old default, so the Skilland URL applies (SKL-991).
 *
 * Until 0.9.45-beta the Frontend URL defaulted to https://app.skilland.ai, so a site that only
 * set the Skilland URL to its own Skilland server kept sending every request to that default.
 * A value an administrator chose (any other address) is kept.
 *
 * @return bool True when the stored value was the old default and was removed.
 */
function mod_skilland_clear_default_frontend_url(): bool {
    $stored = \mod_skilland\local\skilland_url::normalise((string) get_config('mod_skilland', 'frontend_url'));
    if (strtolower($stored) !== MOD_SKILLAND_DEFAULT_URL) {
        return false;
    }
    unset_config('frontend_url', 'mod_skilland');
    return true;
}

/**
 * Get the audience of SSO tokens: the origin (scheme://host[:port]) of the Skilland frontend.
 *
 * @return string
 */
function skilland_get_sso_audience(): string {
    $parts = parse_url(skilland_get_frontend_url());
    if (empty($parts['scheme']) || empty($parts['host'])) {
        return skilland_get_frontend_url();
    }

    $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $origin .= ':' . $parts['port'];
    }
    return $origin;
}

/**
 * Get the Skilland endpoint the SSO form posts to. It never carries the token.
 *
 * @return string
 */
function skilland_get_sso_endpoint(): string {
    return skilland_get_frontend_url() . '/sso-login';
}

/**
 * Render a standalone page that POSTs the SSO token to Skilland.
 *
 * The token travels in the request body, so it never lands in a URL, browser history,
 * a Referer header or an access log.
 *
 * @param string $token The signed SSO token
 * @param string $redirect Skilland path to open after login
 * @return string Complete HTML document
 */
function skilland_render_sso_post_form(string $token, string $redirect): string {
    $action = s(skilland_get_sso_endpoint());
    $message = s(get_string('sso_redirecting', 'mod_skilland'));
    $continue = s(get_string('sso_continue', 'mod_skilland'));
    $tokenvalue = s($token);
    $redirectvalue = s($redirect);

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex">
<title>{$message}</title>
</head>
<body>
<form id="skilland-sso" method="post" action="{$action}">
<input type="hidden" name="token" value="{$tokenvalue}">
<input type="hidden" name="redirect" value="{$redirectvalue}">
<p>{$message}</p>
<noscript><button type="submit">{$continue}</button></noscript>
</form>
<script>document.getElementById('skilland-sso').submit();</script>
</body>
</html>
HTML;
}

/**
 * Strip the query string and fragment from a URL so signed download links are not logged.
 *
 * @param string $url
 * @return string
 */
function mod_skilland_redact_url(string $url): string {
    $cut = strcspn($url, '?#');
    return $cut < strlen($url) ? substr($url, 0, $cut) . '?[redacted]' : $url;
}

/**
 * Check if a hostname is local/internal: a known Docker service name, or a
 * loopback, private, link-local or reserved IP literal.
 *
 * Names are matched exactly (case-insensitive); there is no prefix or substring matching.
 *
 * @param string $host The hostname to check.
 * @return bool True if the host is local/internal.
 */
function mod_skilland_is_local_host(string $host): bool {
    $host = strtolower(trim($host));
    if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
        $host = substr($host, 1, -1);
    }
    if ($host === '') {
        return false;
    }

    $localnames = ['localhost', 'host.docker.internal', 'skilland-back', 'skilland-web', 'hocuspocus', 'postgres'];
    if (in_array($host, $localnames, true)) {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Whether the local development relaxations are on ($CFG->mod_skilland_allow_http in config.php).
 *
 * @return bool
 */
function mod_skilland_dev_network_allowed(): bool {
    global $CFG;
    return !empty($CFG->mod_skilland_allow_http);
}

/**
 * Build a curl client for an outbound Skilland request.
 *
 * Moodle's curl security (blocked hosts/ports) stays on, except for local hosts when the
 * development flag is set. Redirects are never followed, so credentials cannot be replayed
 * to another host.
 *
 * @param string $url The URL the client will call.
 * @return \curl
 */
function mod_skilland_make_curl(string $url): \curl {
    global $CFG;
    require_once($CFG->libdir . '/filelib.php');

    $host = (string) parse_url($url, PHP_URL_HOST);
    $options = [
        'CURLOPT_FOLLOWLOCATION' => false,
        'CURLOPT_MAXREDIRS' => 0,
    ];

    if (mod_skilland_dev_network_allowed() && mod_skilland_is_local_host($host)) {
        $curl = new \curl(['ignoresecurity' => true]);
        $options['CURLOPT_PROXY'] = '';
        $options['CURLOPT_NOPROXY'] = '*';
    } else {
        $curl = new \curl();
    }

    $curl->setopt($options);
    return $curl;
}

/**
 * Require an https:// URL unless the development flag is set.
 *
 * @param string $url
 * @param string $what Short label of the URL for the error message.
 * @throws moodle_exception error_insecure_url
 */
function mod_skilland_require_https(string $url, string $what): void {
    if (mod_skilland_dev_network_allowed()) {
        return;
    }
    if (strtolower((string) parse_url(trim($url), PHP_URL_SCHEME)) !== 'https') {
        throw new moodle_exception('error_insecure_url', 'mod_skilland', '', $what);
    }
}

/**
 * Whether a SCORM package may be downloaded from this URL's host.
 *
 * Allowed hosts are the host of $endpoint (the caller passes the frontend URL and the Skilland
 * URL) and the patterns in the package_hosts setting (comma-separated, case-insensitive;
 * "*.example.com" matches subdomains of example.com only; the default "*.skilland.ai,
 * *.amazonaws.com" covers presigned S3 URLs, virtual-hosted and path-style).
 * IP literals and local hosts (see mod_skilland_is_local_host()) are refused unless the
 * development flag is set, even when the endpoint or the setting names them.
 *
 * @param string $packageurl
 * @param string $endpoint
 * @return bool
 */
function mod_skilland_package_host_allowed(string $packageurl, string $endpoint): bool {
    $normalize = function (string $host): string {
        $host = rtrim(strtolower(trim($host)), '.');
        if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }
        return $host;
    };

    $host = $normalize((string) parse_url($packageurl, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }

    $dev = mod_skilland_dev_network_allowed();
    if (mod_skilland_is_local_host($host)) {
        // Local and internal targets only in development, whatever the endpoint or package_hosts say.
        return $dev;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false && !$dev) {
        return false;
    }

    $endpointhost = $normalize((string) parse_url($endpoint, PHP_URL_HOST));
    if ($endpointhost !== '' && $host === $endpointhost) {
        return true;
    }

    $patterns = get_config('mod_skilland', 'package_hosts');
    if ($patterns === null || $patterns === false) {
        $patterns = '*.skilland.ai, *.amazonaws.com';
    }

    foreach (explode(',', (string) $patterns) as $pattern) {
        $pattern = $normalize($pattern);
        if ($pattern === '') {
            continue;
        }
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1);
            if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        } else if ($host === $pattern) {
            return true;
        }
    }

    return false;
}

/**
 * Download a SCORM package through the injectable api_client (\core\di).
 *
 * @param string $packageurl
 * @param int $expectedsize Size in bytes the API announced for the package; 0 skips the check.
 * @return string Path of the downloaded zip; the caller deletes it.
 * @throws moodle_exception On any failed check; the temp file is removed first.
 */
function mod_skilland_download_package(string $packageurl, int $expectedsize = 0): string {
    return \core\di::get(\mod_skilland\local\api_client::class)->download_package($packageurl, $expectedsize);
}

/**
 * Download a SCORM package to a temp file after validating its URL, size and format.
 *
 * @param string $packageurl
 * @param int $expectedsize Size in bytes the API announced for the package; 0 skips the check.
 * @return string Path of the downloaded zip; the caller deletes it.
 * @throws moodle_exception On any failed check; the temp file is removed first.
 */
function mod_skilland_download_package_http(string $packageurl, int $expectedsize = 0): string {
    mod_skilland_require_https($packageurl, 'package');

    // The REST routes hand out presigned storage URLs (matched by package_hosts) or, in
    // development, URLs on the frontend host or the Skilland URL host itself.
    $endpoint = mod_skilland_get_skilland_url();
    if (
        !mod_skilland_package_host_allowed($packageurl, skilland_get_frontend_url()) &&
            !mod_skilland_package_host_allowed($packageurl, $endpoint)
    ) {
        $host = (string) parse_url($packageurl, PHP_URL_HOST);
        throw new moodle_exception('error_package_host_not_allowed', 'mod_skilland', '', $host);
    }

    $maxmb = (int) get_config('mod_skilland', 'package_max_mb');
    if ($maxmb <= 0) {
        $maxmb = 200;
    }
    $maxbytes = $maxmb * 1024 * 1024;

    $tempfile = tempnam(make_temp_directory('skilland_scorm'), 'skl');
    if ($tempfile === false) {
        throw new moodle_exception('error_scorm_download_failed', 'mod_skilland', '', 'Could not create temp file');
    }

    $fail = function (string $code, $a = null) use ($tempfile): void {
        @unlink($tempfile);
        throw new moodle_exception($code, 'mod_skilland', '', $a);
    };

    $fp = fopen($tempfile, 'w');
    if (!$fp) {
        $fail('error_scorm_download_failed', 'Could not create temp file');
    }

    $curl = mod_skilland_make_curl($packageurl);
    $curl->setopt([
        'CURLOPT_CONNECTTIMEOUT' => 30,
        'CURLOPT_TIMEOUT' => 180,
        'CURLOPT_MAXFILESIZE' => $maxbytes,
    ]);
    $curl->download_one($packageurl, null, ['file' => $fp]);
    fclose($fp);
    clearstatcache(true, $tempfile);

    $httpcode = (int) ($curl->get_info()['http_code'] ?? 0);
    if ($httpcode >= 300 && $httpcode < 400) {
        $fail('error_http_redirect', $httpcode);
    }
    if ($httpcode < 200 || $httpcode >= 300 || $curl->get_errno()) {
        $fail('error_scorm_download_failed', 'HTTP ' . $httpcode);
    }

    $size = file_exists($tempfile) ? filesize($tempfile) : 0;
    if (!$size) {
        $fail('error_scorm_download_failed', 'Empty file');
    }
    if ($size > $maxbytes) {
        $fail('error_package_too_large', $maxmb);
    }
    if ($expectedsize > 0 && $size !== $expectedsize) {
        logger::error('SCORM', 'Package size mismatch - expected ' . $expectedsize . ' bytes, got ' . $size);
        $fail('error_scorm_download_failed', 'Size mismatch');
    }

    $magic = (string) file_get_contents($tempfile, false, null, 0, 4);
    if ($magic !== "PK\x03\x04") {
        $fail('error_package_not_zip');
    }
    $zip = new \ZipArchive();
    if ($zip->open($tempfile) !== true) {
        $fail('error_package_not_zip');
    }
    $zip->close();

    return $tempfile;
}

/** Maximum number of attempts mod_skilland_rest_get_http() makes. */
const MOD_SKILLAND_REST_MAX_ATTEMPTS = 3;

/** No new attempt starts once this many milliseconds have passed since the first one. */
const MOD_SKILLAND_REST_RETRY_BUDGET_MS = 20000;

/** Upper bound of a Retry-After delay the plugin honours, in milliseconds. */
const MOD_SKILLAND_RETRY_AFTER_CAP_MS = 5000;

/** curl errnos treated as transient: DNS, connect, timeout, TLS handshake, empty reply, receive error. */
const MOD_SKILLAND_TRANSIENT_CURL_ERRNOS = [6, 7, 28, 35, 52, 56];

/**
 * Whether a failed REST GET is worth retrying.
 *
 * @param int $httpcode HTTP status, 0 when no response arrived.
 * @param int $errno curl errno.
 * @return bool
 */
function mod_skilland_is_transient(int $httpcode, int $errno): bool {
    if ($httpcode === 0) {
        return in_array($errno, MOD_SKILLAND_TRANSIENT_CURL_ERRNOS, true);
    }
    return in_array($httpcode, [429, 500, 502, 503, 504], true);
}

/**
 * Delay before retry number $attempt (1-based), in milliseconds.
 *
 * Exponential backoff (250 ms, 500 ms) plus up to 250 ms of jitter. An integer-seconds
 * Retry-After value overrides it, capped at MOD_SKILLAND_RETRY_AFTER_CAP_MS; an HTTP-date or
 * any other value is ignored.
 *
 * @param int $attempt
 * @param string|null $retryafter Raw Retry-After header value, null when absent.
 * @return int
 */
function mod_skilland_retry_delay_ms(int $attempt, ?string $retryafter): int {
    if ($retryafter !== null) {
        $value = trim($retryafter);
        if ($value !== '' && ctype_digit($value)) {
            return (int) min((int) $value * 1000, MOD_SKILLAND_RETRY_AFTER_CAP_MS);
        }
    }
    return 250 * (2 ** max(0, $attempt - 1)) + random_int(0, 250);
}

/**
 * Sleep between retries through the injectable retry_sleeper (\core\di).
 *
 * @param int $ms
 */
function mod_skilland_retry_sleep(int $ms): void {
    \core\di::get(\mod_skilland\local\retry_sleeper::class)->sleep_ms($ms);
}

/**
 * A response header from a curl client, matched case-insensitively.
 *
 * @param \curl $curl
 * @param string $name
 * @return string|null The last value of the header, null when absent.
 */
function mod_skilland_response_header(\curl $curl, string $name): ?string {
    if (!method_exists($curl, 'getResponse')) {
        return null;
    }
    $headers = $curl->getResponse();
    if (!is_array($headers)) {
        return null;
    }
    foreach ($headers as $key => $value) {
        if (is_string($key) && strcasecmp(trim($key), $name) === 0) {
            if (is_array($value)) {
                $value = end($value);
            }
            return is_scalar($value) ? (string) $value : null;
        }
    }
    return null;
}

/** Error codes whose language string is safe and useful to show a client as is. */
const MOD_SKILLAND_CLIENT_ERROR_CODES = [
    'error_config_missing_orgid',
    'error_config_missing_apikey',
    'error_config_missing_endpoint',
    'error_config_invalid_credentials',
    'error_config_missing_topicid',
    'error_config_missing_courseid',
    'error_config_missing_lessonid',
    'error_http_redirect',
    'error_scorm_not_available',
    'error_plugin_disabled',
    'error_course_not_mapped',
    'error_course_not_mapped_to_skill',
    'error_lessons_not_in_topic',
    'error_provision_in_progress',
    'error_scorm_signature_missing',
    'error_scorm_signature_unknown_key',
    'error_scorm_signature_malformed',
    'error_scorm_signature_invalid',
    'error_scorm_hash_mismatch',
    'error_create_insufficient_role',
    'error_create_inactive_member',
    'error_create_name_taken',
    'error_create_not_a_member',
    'error_create_rate_limited',
];

/**
 * Whether an exception is an allowlisted mod_skilland error that is safe to show a client.
 *
 * @param \Throwable $e
 * @return bool
 */
function mod_skilland_is_client_error(\Throwable $e): bool {
    return $e instanceof moodle_exception && !($e instanceof dml_exception) && $e->module === 'mod_skilland' &&
        in_array($e->errorcode, MOD_SKILLAND_CLIENT_ERROR_CODES, true);
}

/**
 * The message a web service may return to the browser for a caught exception.
 *
 * Only mod_skilland errors that tell the user what to fix pass through, as their language
 * string without $a or debuginfo (error_http_redirect keeps its numeric status). Anything else
 * (error_graphql_http, error_graphql_invalid_json, error_scorm_fetch_failed,
 * dml_exception, plain exceptions) becomes error_api_unavailable, so endpoints, curl errors and
 * SQL never reach the client. With devmode on, the raw message is appended for debugging.
 *
 * Pass-through allowlist: error_config_missing_orgid, error_config_missing_apikey,
 * error_config_missing_endpoint, error_config_invalid_credentials, error_config_missing_topicid,
 * error_config_missing_courseid, error_config_missing_lessonid, error_http_redirect,
 * error_scorm_not_available, error_plugin_disabled, error_course_not_mapped,
 * error_course_not_mapped_to_skill, error_lessons_not_in_topic, error_provision_in_progress,
 * error_scorm_signature_missing, error_scorm_signature_unknown_key, error_scorm_signature_malformed,
 * error_scorm_signature_invalid, error_scorm_hash_mismatch, error_create_insufficient_role,
 * error_create_inactive_member, error_create_name_taken, error_create_not_a_member,
 * error_create_rate_limited.
 * Add any future user-facing error code to MOD_SKILLAND_CLIENT_ERROR_CODES.
 *
 * @param \Throwable $e The caught exception.
 * @return string
 */
function mod_skilland_client_error_message(\Throwable $e): string {
    $message = get_string('error_api_unavailable', 'mod_skilland');
    if (mod_skilland_is_client_error($e)) {
        $a = null;
        if ($e->errorcode === 'error_http_redirect' && is_numeric($e->a)) {
            $a = (int) $e->a;
        }
        $message = get_string($e->errorcode, 'mod_skilland', $a);
    }
    if (get_config('mod_skilland', 'devmode')) {
        $message .= ' (' . $e->getMessage() . ')';
    }
    return $message;
}

/**
 * Remembers, in the user's session, the Skilland Studio path to open once the Moodle course form
 * has saved (SKL-664). The SSO token is minted at click time by sso_redirect.php, never here.
 *
 * @param int $courseid Moodle course ID.
 * @param string $path Skilland Studio path accepted by mod_skilland_is_studio_path().
 * @return void
 * @throws coding_exception When the path is not a Skilland Studio path.
 */
function mod_skilland_set_pending_studio_path(int $courseid, string $path): void {
    global $SESSION;

    if (!mod_skilland_is_studio_path($path)) {
        throw new coding_exception('Pending Skilland path must be a Skilland Studio path (/skills/...)');
    }
    if (!is_object($SESSION)) {
        $SESSION = new stdClass();
    }
    if (!isset($SESSION->mod_skilland_pending_studio) || !is_array($SESSION->mod_skilland_pending_studio)) {
        $SESSION->mod_skilland_pending_studio = [];
    }
    $SESSION->mod_skilland_pending_studio[$courseid] = $path;
}

/**
 * Returns the pending Skilland Studio path for a course without consuming it (SKL-664).
 *
 * @param int $courseid Moodle course ID.
 * @return string|null The path, or null when none is pending or it is not a Skilland Studio path.
 */
function mod_skilland_peek_pending_studio_path(int $courseid): ?string {
    global $SESSION;

    if (!is_object($SESSION) || empty($SESSION->mod_skilland_pending_studio[$courseid])) {
        return null;
    }
    $path = $SESSION->mod_skilland_pending_studio[$courseid];
    if (!is_string($path) || !mod_skilland_is_studio_path($path)) {
        return null;
    }
    return $path;
}

/**
 * Returns and clears the pending Skilland Studio path for a course (SKL-664).
 *
 * @param int $courseid Moodle course ID.
 * @return string|null The path, or null when none is pending or it is not a Skilland Studio path.
 */
function mod_skilland_take_pending_studio_path(int $courseid): ?string {
    global $SESSION;

    $path = mod_skilland_peek_pending_studio_path($courseid);
    if (is_object($SESSION) && isset($SESSION->mod_skilland_pending_studio[$courseid])) {
        unset($SESSION->mod_skilland_pending_studio[$courseid]);
    }
    return $path;
}

/**
 * Find a playable lesson among the visible lessons of this activity.
 *
 * @param array $lessons Visible lessons of this activity, keyed by lesson id.
 * @param int $lessonid The requested lesson id.
 * @return stdClass|null The lesson, or null when it is hidden, belongs to another activity or does not exist.
 */
function skilland_find_visible_lesson(array $lessons, int $lessonid): ?stdClass {
    return $lessons[$lessonid] ?? null;
}

/**
 * Detect a linked SCORM that was deleted (or is being deleted) and treat the activity as
 * unprovisioned for this request by clearing scormcmid in memory only.
 *
 * @param stdClass $skilland The skilland activity record; scormcmid is nulled when missing.
 * @return bool True when scormcmid was set but the SCORM module is gone.
 */
function skilland_detect_missing_scorm(stdClass $skilland): bool {
    if (empty($skilland->scormcmid)) {
        return false;
    }
    if (skilland_get_linked_scorm_cm($skilland)) {
        return false;
    }
    $skilland->scormcmid = null;
    return true;
}

/**
 * The code of a lesson, `L<topic>.<position>`, the same one the activity form shows.
 *
 * The position is the lesson's 1-based place in the topic in Skilland (`skillandposition`), so a
 * lesson left out of the activity leaves a gap. An activity saved before positions were stored
 * (0, unknown) falls back to its order in the activity (`orderindex`), then to `$fallback`.
 *
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @param stdClass $lesson The skilland_lesson record.
 * @param int $fallback The number to use when the record carries neither position.
 * @return string The lesson code, e.g. L1.3.
 */
function skilland_lesson_label(int $topicorderindex, stdClass $lesson, int $fallback = 0): string {
    $position = (int) ($lesson->skillandposition ?? 0);
    if ($position <= 0) {
        $position = (int) ($lesson->orderindex ?? 0);
    }
    if ($position <= 0) {
        $position = $fallback;
    }
    return 'L' . $topicorderindex . '.' . $position;
}

/**
 * The mod_skilland renderer of the view page.
 *
 * @return \mod_skilland\output\renderer
 */
function skilland_view_renderer(): \mod_skilland\output\renderer {
    global $PAGE;
    return $PAGE->get_renderer('mod_skilland');
}

/**
 * Render the lesson list view with progress indicators.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param array $lessons Array of lesson records.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @return string HTML output.
 */
function skilland_render_lesson_list($skilland, $lessons, $cm, $topicorderindex = 1) {
    global $USER;

    $progress = [];
    $canprovision = false;
    if (!empty($lessons)) {
        // Merge the learner's SCORM tracks into the progress store, then read it (SKL-668).
        if (skilland_refresh_progress($skilland, (int) $USER->id)) {
            skilland_recompute_user($skilland, (int) $USER->id);
        }
        $progress = skilland_get_user_progress((int) $skilland->id, (int) $USER->id);

        logger::debug('Progress', 'Progress for user ' . $USER->id . ', skilland ' . $skilland->id . ': ' . json_encode($progress));

        // Teachers see which visible lessons are missing from the installed package (SKL-655).
        $canprovision = !empty($skilland->scormcmid) &&
            has_capability('mod/skilland:provision', context_module::instance($cm->id));
    }

    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_list(
        $skilland,
        $lessons,
        $cm,
        (int) $topicorderindex,
        $progress,
        $canprovision
    ));
}

/**
 * Render the SCORM player view with embedded iframe in fullscreen mode.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $lesson The lesson record to play.
 * @param stdClass $cm The course module record.
 * @param array $alllessons All lessons for navigation.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @return string HTML output.
 */
function skilland_render_player_view($skilland, $lesson, $cm, $alllessons, $topicorderindex = 1) {
    global $USER, $DB, $PAGE, $CFG;

    $renderer = skilland_view_renderer();

    // Check the lesson is visible here, has a valid SCO mapping and the SCORM module still exists.
    $found = \mod_skilland\output\lesson_navigation::position_of($lesson, $alllessons) !== null;
    $scormcm = null;
    if ($found && !empty($lesson->scoid) && !empty($skilland->scormcmid)) {
        $scormcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
    }
    if (!$scormcm) {
        return $renderer->render(new \mod_skilland\output\player(
            $skilland,
            $lesson,
            $cm,
            $alllessons,
            (int) $topicorderindex,
            null
        ));
    }

    // Build the SCORM player URL.
    $scorm = $DB->get_record('scorm', ['id' => $scormcm->instance], '*', MUST_EXIST);

    // Get or create a SCORM attempt for this user.
    require_once($CFG->dirroot . '/mod/scorm/locallib.php');
    $attempt = scorm_get_last_attempt($scorm->id, $USER->id);
    if (empty($attempt)) {
        $attempt = 1;
    }

    // Build the player URL with the specific SCO.
    $scormplayerurl = new moodle_url('/mod/scorm/player.php', [
        'scoid' => $lesson->scoid,
        'cm' => $skilland->scormcmid,
        'mode' => 'normal',
        'newattempt' => 'off',
        'display' => 'popup',
    ]);

    $player = new \mod_skilland\output\player(
        $skilland,
        $lesson,
        $cm,
        $alllessons,
        (int) $topicorderindex,
        $scormplayerurl
    );
    $html = $renderer->render($player);

    // Load the fullscreen JavaScript module.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/fullscreen_player', 'init', [[
        'debug' => (bool)$devmode,
        'backurl' => $player->get_back_url()->out(false),
    ]]);

    return $html;
}

/**
 * Render prev/next navigation for the player view.
 *
 * @param stdClass $currentlesson The current lesson record.
 * @param array $alllessons All lessons for this activity.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @param stdClass $skilland The skilland activity record.
 * @return string HTML output.
 */
function skilland_render_player_navigation($currentlesson, $alllessons, $cm, $topicorderindex, $skilland) {
    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_navigation(
        $currentlesson,
        $alllessons,
        $cm,
        (int) $topicorderindex,
        $skilland,
        \mod_skilland\output\lesson_navigation::STYLE_PLAYER
    ));
}

/**
 * Render the bottom navigation bar for fullscreen player view.
 *
 * @param stdClass $currentlesson The current lesson record.
 * @param array $alllessons All lessons for this activity.
 * @param stdClass $cm The course module record.
 * @param int $topicorderindex The topic order index (T1, T2, etc.).
 * @param stdClass $skilland The skilland activity record.
 * @return string HTML output.
 */
function skilland_render_fullscreen_navigation($currentlesson, $alllessons, $cm, $topicorderindex, $skilland) {
    return skilland_view_renderer()->render(new \mod_skilland\output\lesson_navigation(
        $currentlesson,
        $alllessons,
        $cm,
        (int) $topicorderindex,
        $skilland,
        \mod_skilland\output\lesson_navigation::STYLE_FULLSCREEN
    ));
}

/**
 * Render the provision view for teachers when SCORM is not yet created.
 *
 * @param stdClass $skilland The skilland activity record.
 * @param stdClass $cm The course module record.
 * @return string HTML output.
 */
function skilland_render_provision_view($skilland, $cm) {
    global $PAGE;

    $html = skilland_view_renderer()->render(new \mod_skilland\output\provision($skilland, $cm));

    // Load the JavaScript module for provisioning.
    $devmode = get_config('mod_skilland', 'devmode');
    $PAGE->requires->js_call_amd('mod_skilland/provision_scorm', 'init', [[
        'skillandid' => (int)$skilland->id,
        'cmid' => (int)$cm->id,
        'debug' => (bool)$devmode,
    ]]);

    return $html;
}

/**
 * Convert a timestamp received from Skilland (Unix number or ISO 8601 string) to an int.
 *
 * Anything empty or unparseable becomes 0; the offending value is never logged, only its length.
 *
 * @param mixed $value The received value.
 * @return int Unix timestamp, or 0.
 */
function skilland_parse_timestamp($value): int {
    if (is_int($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return 0;
    }
    $parsed = strtotime($value);
    if ($parsed === false) {
        logger::debug('Timestamp', 'Unparseable timestamp ignored, length ' . strlen($value));
        return 0;
    }
    return $parsed;
}
