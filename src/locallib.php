<?php
defined('MOODLE_INTERNAL') || die();

use mod_skilland\graphql_exception;
use mod_skilland\logger;

/**
 * Local library functions for mod_skilland
 *
 * This file contains helper functions for managing Moodle course-to-Skilland course mappings
 * and other internal operations.
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
    return $DB->get_record('skilland_course', array('course' => $courseid));
}

/**
 * Check if a Moodle course has a Skilland course mapping.
 *
 * @param int $courseid Moodle course ID
 * @return bool True if mapping exists, false otherwise
 */
function skilland_course_has_mapping($courseid) {
    global $DB;
    return $DB->record_exists('skilland_course', array('course' => $courseid));
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

    // Use global org ID if not provided
    if (empty($orgid)) {
        $orgid = get_config('mod_skilland', 'orgid');
    }

    // Check if mapping already exists
    $existing = $DB->get_record('skilland_course', array('course' => $courseid));

    $now = time();

    if ($existing) {
        // Update existing mapping
        $existing->skilland_courseid = $skillandcourseid;
        $existing->skilland_orgid = $orgid;
        $existing->timemodified = $now;

        if ($DB->update_record('skilland_course', $existing)) {
            return $existing->id;
        }
        return false;
    } else {
        // Create new mapping
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

    $mapping = $DB->get_record('skilland_course', array('course' => $courseid));
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
 * Delete Skilland course mapping.
 * Note: This should be used carefully as it will affect all activities in the course.
 *
 * @param int $courseid Moodle course ID
 * @return bool True on success, false on failure
 */
function skilland_delete_course_mapping($courseid) {
    global $DB;
    return $DB->delete_records('skilland_course', array('course' => $courseid));
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
            } catch (Exception $catException) {
                // Critical failure only if we absolutely cannot get a category AND we need to create one.
                throw new Exception('Cannot create mandatory Skilland category: ' . $catException->getMessage());
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
            'itemid' => 0
        ];

        $field = \core_customfield\field_controller::create(0, $fielddata, $category);
        $field->set('name', get_string('customfield_skilland_course_id', 'mod_skilland'));
        $field->set('shortname', $fieldshortname);

        // Build description.
        $description = get_string('customfield_skilland_course_id_desc', 'mod_skilland');
        $field->set('description', $description);
        $field->set('descriptionformat', FORMAT_HTML);
        $field->set('configdata', '{"required":"0","defaultvalue":"","displaysize":50,"maxlength":255,"ispassword":"0","link":"","locked":"1","visibility":"2"}');
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
 * Anything else that is not a non-empty JSON object ('', '{}', '[]', 'array()',
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

/**
 * Execute a GraphQL query against Skilland's GraphQL endpoint.
 *
 * @param string $query The GraphQL query string
 * @param array $variables Optional variables for the query
 * @return array The decoded JSON response data
 * @throws moodle_exception If configuration is missing or API call fails
 */
function mod_skilland_graphql(string $query, array $variables = []): array {
    global $CFG;

    // Ensure curl class is available.
    require_once($CFG->libdir . '/filelib.php');

    // Get configuration.
    $config = get_config('mod_skilland');
    $orgid = $config->orgid ?? '';
    $apikey = $config->apikey ?? '';
    $endpoint = $config->graphql_endpoint ?? '';

    logger::debug('GraphQL', 'Starting request to ' . mod_skilland_redact_url($endpoint));
    logger::debug('GraphQL', 'Org ID: ' . ($orgid ? 'SET' : 'MISSING'));
    logger::debug('GraphQL', 'API Key: ' . ($apikey ? 'SET' : 'MISSING'));

    // Validate configuration.
    if (empty($orgid)) {
        logger::error('GraphQL', 'Missing orgid');
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }
    if (empty($apikey)) {
        logger::error('GraphQL', 'Missing apikey');
        throw new moodle_exception('error_config_missing_apikey', 'mod_skilland');
    }
    if (empty($endpoint)) {
        logger::error('GraphQL', 'Missing endpoint');
        throw new moodle_exception('error_config_missing_endpoint', 'mod_skilland');
    }

    mod_skilland_require_https($endpoint, 'endpoint');

    // Build JSON payload.
    // Ensure variables is always an object (associative array in PHP), not an array.
    // If variables is an empty array, convert to empty object for JSON encoding.
    if (empty($variables)) {
        $variables = new \stdClass(); // Empty object
    }
    $payload = [
        'query' => $query,
        'variables' => $variables
    ];
    $jsonpayload = json_encode($payload);
    logger::debug('GraphQL', 'Variables: ' . implode(', ', array_keys((array) $variables)));

    $curl = mod_skilland_make_curl($endpoint);
    $curl->setopt([
        'CURLOPT_CONNECTTIMEOUT' => 10,
        'CURLOPT_TIMEOUT' => 30,
    ]);

    $headers = [
        'Content-Type: application/json',
        'X-Skilland-Org-Id: ' . $orgid,
        'X-Skilland-Api-Key: ' . $apikey
    ];
    $curl->setHeader($headers);
    logger::debug('GraphQL', 'Headers set, making POST request to ' . mod_skilland_redact_url($endpoint));
    logger::debug('GraphQL', 'Payload length: ' . strlen($jsonpayload));

    // Make POST request.
    try {
        $response = $curl->post($endpoint, $jsonpayload);
        logger::debug('GraphQL', 'Response received, length: ' . strlen($response));
    } catch (Exception $e) {
        logger::error('GraphQL', 'Exception during POST: ' . $e->getMessage());
        throw $e;
    }

    // Check for HTTP errors.
    $info = $curl->get_info();
    $httpcode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
    $errno = $curl->get_errno();
    // Try both property and method access for error message.
    $curlerror = method_exists($curl, 'error') ? $curl->error() : ($curl->error ?? 'Unknown error');

    logger::debug('GraphQL', 'HTTP code: ' . $httpcode);
    logger::debug('GraphQL', 'CURL errno: ' . $errno);
    logger::debug('GraphQL', 'CURL error: ' . $curlerror);

    if ($httpcode >= 300 && $httpcode < 400) {
        logger::error('GraphQL', 'Refusing redirect (HTTP ' . $httpcode . ') from ' . mod_skilland_redact_url($endpoint));
        throw new moodle_exception('error_http_redirect', 'mod_skilland', '', $httpcode);
    }

    if ($httpcode < 200 || $httpcode >= 300) {
        // The exception carries only the status: the endpoint and curl details go to the log.
        $safeendpoint = mod_skilland_redact_url($endpoint);
        if ($errno || $httpcode == 0) {
            $details = '';
            if ($errno) {
                $details .= ' (errno: ' . $errno . ')';
            }
            if ($curlerror && $curlerror !== 'Unknown error') {
                $details .= ' ' . $curlerror;
            }
            logger::error('GraphQL', 'Connection to Skilland API at ' . $safeendpoint . ' failed: HTTP ' . $httpcode .
                $details);

            $refused = $errno == 7 || strpos($curlerror, 'Connection refused') !== false ||
                strpos($curlerror, 'Could not connect') !== false;
            $host = (string) parse_url($endpoint, PHP_URL_HOST);
            if ($refused && ($host === 'localhost' || $host === '127.0.0.1')) {
                logger::debug('GraphQL', 'If Moodle is running in Docker, use "host.docker.internal" instead of "' .
                    $host . '" in the GraphQL endpoint setting.');
            }
        } else {
            logger::error('GraphQL', 'HTTP error ' . $httpcode . ' from Skilland API at ' . $safeendpoint);
        }
        throw new moodle_exception('error_graphql_http', 'mod_skilland', '', 'HTTP ' . $httpcode);
    }

    // Decode JSON response.
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('error_graphql_invalid_json', 'mod_skilland');
    }

    // Check for GraphQL errors.
    $error = mod_skilland_first_graphql_error(is_array($data) ? ($data['errors'] ?? null) : null);
    if ($error !== null) {
        mod_skilland_map_graphql_error($error);
    }

    // Return data.
    return $data['data'] ?? [];
}

/**
 * Check if a topic's SCORM content has changed by querying the lightweight hash endpoint.
 *
 * Returns null if the API call fails (non-fatal for polling use cases).
 *
 * @param string $topicid The Skilland topic ID.
 * @return array|null Hash info array with contentHash, packageHash, generatedAt, hasPackage, isStale — or null on failure.
 */
function mod_skilland_check_topic_snapshot(string $topicid): ?array {
    // Test hook: return override value if set (used by PHPUnit tests).
    if (array_key_exists('_test_topic_snapshot', $GLOBALS)) {
        return $GLOBALS['_test_topic_snapshot'];
    }

    $query = <<<'GRAPHQL'
query TopicScormHash($topicId: ID!) {
  topicScormHash(topicId: $topicId) {
    contentHash
    packageHash
    generatedAt
    hasPackage
    isStale
  }
}
GRAPHQL;

    try {
        $result = mod_skilland_graphql($query, ['topicId' => $topicid]);
        return $result['topicScormHash'] ?? null;
    } catch (\Exception $e) {
        logger::warn('SnapshotCheck', 'Failed to check topic snapshot for ' . $topicid . ': ' . $e->getMessage());
        return null;
    }
}

/**
 * Fetch the list of courses from Skilland for the configured organization.
 *
 * @return array Array of course objects with id, name, code, and status
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_courses(): array {
    $config = get_config('mod_skilland');
    $orgid = $config->orgid ?? '';

    if (empty($orgid)) {
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }

    $query = <<<'GRAPHQL'
query MoodleListCourses($orgId: ID!) {
  organization(id: $orgId) {
    id
    name
    courses {
      id
      name
      code
      status
    }
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['orgId' => $orgid]);

        if (!isset($data['organization']['courses'])) {
            return [];
        }

        return $data['organization']['courses'];
    } catch (moodle_exception $e) {
        // Re-throw with context.
        throw $e;
    }
}

/**
 * Fetch the list of Skilland courses that a user can edit (owner or collaborator).
 *
 * @param string $useremail Email of the user
 * @return array Array of course objects with id, name, code, and status
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_user_courses(string $useremail): array {
    $config = get_config('mod_skilland');
    $orgid = $config->orgid ?? '';

    if (empty($orgid)) {
        throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');
    }

    if (empty($useremail)) {
        return [];
    }

    $query = <<<'GRAPHQL'
query MoodleUserCourses($orgId: ID!, $userEmail: String!) {
  moodleUserCourses(orgId: $orgId, userEmail: $userEmail) {
    id
    name
    code
    status
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['orgId' => $orgid, 'userEmail' => $useremail]);

        if (!isset($data['moodleUserCourses'])) {
            return [];
        }

        return $data['moodleUserCourses'];
    } catch (moodle_exception $e) {
        logger::warn('locallib', 'Failed to fetch user courses: ' . $e->getMessage());
        return [];
    }
}

/**
 * Create a new course (skill) in Skilland using the Moodle course name.
 *
 * Uses the createSkillFromMoodle mutation which handles user auto-provisioning.
 *
 * @param string $name Course name
 * @param string $useremail Email of the user creating the course
 * @return array Array with id, name, status, creationStep
 * @throws moodle_exception If API call fails
 */
function mod_skilland_create_course(string $name, string $useremail): array {
    if (empty($name)) {
        throw new moodle_exception('error', 'mod_skilland', '', null, 'Course name is required');
    }
    if (empty($useremail)) {
        throw new moodle_exception('error', 'mod_skilland', '', null, 'User email is required');
    }

    $query = <<<'GRAPHQL'
mutation MoodleCreateCourse($name: String!, $userEmail: String!) {
  createSkillFromMoodle(name: $name, userEmail: $userEmail) {
    id
    name
    status
    creationStep
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['name' => $name, 'userEmail' => $useremail]);

        if (!isset($data['createSkillFromMoodle'])) {
            throw new moodle_exception('error', 'mod_skilland', '', null, 'Failed to create course in Skilland');
        }

        return $data['createSkillFromMoodle'];
    } catch (moodle_exception $e) {
        throw $e;
    }
}

/**
 * Fetch the list of topics from Skilland for a given course.
 *
 * @param string $courseid Skilland course ID
 * @return array Array of topic objects with id, name, and code
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_topics(string $courseid): array {
    if (empty($courseid)) {
        throw new moodle_exception('error_config_missing_courseid', 'mod_skilland');
    }

    $query = <<<'GRAPHQL'
query MoodleListTopics($courseId: ID!) {
  course(id: $courseId) {
    id
    name
    topics {
      id
      name
      code
      description
    }
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['courseId' => $courseid]);

        if (!isset($data['course']) || empty($data['course'])) {
            // Return a proper structure with empty topics array
            return [
                'id' => $courseid,
                'name' => '',
                'topics' => []
            ];
        }

        return $data['course'];
    } catch (moodle_exception $e) {
        // Re-throw with context.
        throw $e;
    }
}

/**
 * Fetch the list of lessons from Skilland for a given topic.
 *
 * @param string $topicid Skilland topic ID
 * @return array Array of lesson objects with id, name, and updatedAt
 * @throws moodle_exception If API call fails
 */
function mod_skilland_fetch_lessons(string $topicid): array {
    if (empty($topicid)) {
        throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
    }

    $query = <<<'GRAPHQL'
query GetTopicLessons($topicId: ID!) {
  topic(id: $topicId) {
    id
    name
    lessons {
      id
      name
      updatedAt
    }
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['topicId' => $topicid]);

        if (!isset($data['topic']['lessons'])) {
            return [];
        }

        return $data['topic']['lessons'];
    } catch (moodle_exception $e) {
        // Re-throw with context.
        throw $e;
    }
}

/** GraphQL extensions.code the API returns when a topic has no SCORM package. */
const MOD_SKILLAND_GQL_SCORM_NOT_AVAILABLE = 'SCORM_NOT_AVAILABLE';

/** GraphQL extensions.code the API returns when a topic does not exist. */
const MOD_SKILLAND_GQL_TOPIC_NOT_FOUND = 'TOPIC_NOT_FOUND';

/**
 * Fetch SCORM package information from Skilland for a topic (multi-SCO package).
 *
 * @param string $topicid Skilland topic ID
 * @return array Array with packageUrl, packageSize, packageHash, generatedAt, expiresAt, mappings
 * @throws moodle_exception If API call fails or SCORM is not available
 */
function mod_skilland_fetch_topic_scorm(string $topicid): array {
    if (empty($topicid)) {
        throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
    }

    $query = <<<'GRAPHQL'
query GetTopicScorm($topicId: ID!) {
  topicScorm(topicId: $topicId) {
    packageUrl
    packageSize
    packageHash
    generatedAt
    expiresAt
    mappings
  }
}
GRAPHQL;

    try {
        $data = mod_skilland_graphql($query, ['topicId' => $topicid]);
    } catch (\Throwable $e) {
        $graphqlcode = $e instanceof graphql_exception ? $e->graphqlcode : '';
        if ($graphqlcode === MOD_SKILLAND_GQL_SCORM_NOT_AVAILABLE) {
            throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
        }
        if ($graphqlcode === MOD_SKILLAND_GQL_TOPIC_NOT_FOUND) {
            throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
        }
        // Configuration and other user-facing errors (error_http_redirect, ...) keep their own code.
        if (mod_skilland_is_client_error($e) ||
                ($e instanceof moodle_exception && str_starts_with((string) $e->errorcode, 'error_config_'))) {
            throw $e;
        }
        logger::error('SCORM', 'Fetching the SCORM package of topic ' . $topicid . ' failed: ' . get_class($e) .
            ': ' . $e->getMessage());
        throw new moodle_exception('error_scorm_fetch_failed', 'mod_skilland');
    }

    if (!isset($data['topicScorm']) || !is_array($data['topicScorm'])) {
        throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
    }

    $scorm = $data['topicScorm'];

    // Build mappings array: lessonId => scoId.
    // The mappings field is a [JSON] scalar, so it comes as an array of objects.
    $mappings = [];
    if (!empty($scorm['mappings']) && is_array($scorm['mappings'])) {
        foreach ($scorm['mappings'] as $mapping) {
            // Handle both object and array formats.
            if (is_array($mapping)) {
                $lessonId = $mapping['lessonId'] ?? null;
                $scoId = $mapping['scoId'] ?? null;
            } else if (is_object($mapping)) {
                $lessonId = $mapping->lessonId ?? null;
                $scoId = $mapping->scoId ?? null;
            } else {
                continue;
            }
            if ($lessonId && $scoId && (is_string($lessonId) || is_int($lessonId))) {
                $mappings[$lessonId] = $scoId;
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
 * Fetch the topic SCORM package info from Skilland, download the zip and verify its hash.
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

    $packageurl = $scorminfo['packageUrl'];
    $expectedhash = $scorminfo['packageHash'] ?? '';

    logger::debug('SCORM', 'Downloading topic SCORM package from ' . mod_skilland_redact_url($packageurl));

    $tempfile = mod_skilland_download_package($packageurl);

    try {
        logger::debug('SCORM', 'Downloaded topic SCORM package (' . filesize($tempfile) . ' bytes)');

        if (!empty($expectedhash)) {
            $hashparts = explode(':', $expectedhash, 2);
            $algorithm = count($hashparts) === 2 ? $hashparts[0] : 'sha256';
            $expected = count($hashparts) === 2 ? $hashparts[1] : $expectedhash;

            if (!in_array(strtolower($algorithm), hash_algos(), true)) {
                throw new moodle_exception('error_scorm_hash_mismatch', 'mod_skilland');
            }
            $actualhash = hash_file($algorithm, $tempfile);
            if ($actualhash === false || strcasecmp($actualhash, $expected) !== 0) {
                logger::error('SCORM', 'Hash mismatch - expected ' . $expected . ', got ' . $actualhash);
                throw new moodle_exception('error_scorm_hash_mismatch', 'mod_skilland');
            }
            logger::debug('SCORM', 'Package hash verified successfully');
        }
    } catch (\Throwable $e) {
        @unlink($tempfile);
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
function skilland_create_topic_scorm_module(stdClass $skilland, stdClass $course, int $sectionnum,
        string $packagepath): int {
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
    // The SkilLand activity owns the grade (SKL-668): the hidden SCORM keeps no grade item.
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
        debugging('mod_skilland: could not delete SCORM course module ' . $cmid . ': ' . $e->getMessage(),
            DEBUG_DEVELOPER);
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
    foreach (['scormcmid', 'scorm_provisioned', 'scomappings', 'snapshotcreatedat'] as $field) {
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
 * @return int The SCORM course module ID
 * @throws moodle_exception If provisioning fails or is already in progress
 */
function skilland_provision_topic_scorm($skilland, $course, $sectionnum = 0) {
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

        $cmid = skilland_provision_topic_scorm_locked($current, $course, (int) $sectionnum);
        skilland_copy_provisioning_fields($current, $skilland);
        return $cmid;
    } finally {
        $lock->release();
    }
}

/**
 * Provision the topic SCORM package. The caller holds the provisioning lock.
 *
 * Download, create the module through create_module(), map the SCOs, then write the
 * provisioning fields last. Any failure after the module exists deletes it again and clears
 * the lesson SCO mapping, so a failed run leaves nothing behind.
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are updated.
 * @param stdClass $course The Moodle course record.
 * @param int $sectionnum Section number the SCORM goes into.
 * @return int The new SCORM course module id.
 * @throws moodle_exception If provisioning fails.
 */
function skilland_provision_topic_scorm_locked(stdClass $skilland, stdClass $course, int $sectionnum): int {
    global $DB;

    $package = skilland_download_topic_scorm_package((string) $skilland->skilland_topicid);
    $scorminfo = $package['info'];

    try {
        $cmid = skilland_create_topic_scorm_module($skilland, $course, $sectionnum, $package['path']);

        try {
            $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $cmid]);
            skilland_map_topic_scos($skilland, $scormid, $scorminfo['mappings'] ?? []);

            $fields = (object) [
                'id' => $skilland->id,
                'scormcmid' => $cmid,
                'scorm_provisioned' => time(),
                'scomappings' => json_encode($scorminfo['mappings'] ?? []),
                'snapshotcreatedat' => !empty($scorminfo['generatedAt']) ? strtotime($scorminfo['generatedAt']) : time(),
            ];
            $DB->update_record('skilland', $fields);
        } catch (\Throwable $e) {
            logger::error('SCORM', 'Provisioning failed after creating cmid ' . $cmid . ' - rolling back: ' .
                $e->getMessage());
            skilland_delete_scorm_module($cmid);
            skilland_clear_lesson_scos((int) $skilland->id);
            throw $e;
        }
    } finally {
        @unlink($package['path']);
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
                $datarecord->set('value', $skillandcourseid);
                $datarecord->save();
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
                    $datarecord->set('value', $skillandcourseid);
                    $datarecord->save();
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
 * Update an existing topic SCORM package with the latest content from Skilland.
 *
 * Under the same per-activity lock as provisioning, this function:
 * 1. Deletes the old SCORM activity and all its tracking data
 * 2. Fetches the current lessons from Skilland (before the build, so a stamp is never newer
 *    than the packaged content)
 * 3. Provisions a new SCORM package with skilland_provision_topic_scorm_locked()
 * 4. Only once that build succeeded, stamps each lesson's updatedat with the fetched value
 *
 * WARNING: This will delete all student progress/grades for this topic!
 *
 * @param stdClass $skilland The skilland activity record; its provisioning fields are updated.
 * @param stdClass $course The Moodle course record
 * @param int $sectionnum The section number for the SCORM
 * @return int The new SCORM course module ID
 * @throws moodle_exception If update fails or provisioning is already in progress
 */
function skilland_update_topic_scorm($skilland, $course, $sectionnum = 0) {
    // Test hook: return override value if set (used by PHPUnit tests).
    if (array_key_exists('_test_update_topic_scorm', $GLOBALS)) {
        $hook = $GLOBALS['_test_update_topic_scorm'];
        return $hook instanceof \Closure ? $hook($skilland, $course, $sectionnum) : $hook;
    }

    global $DB;

    skilland_require_scorm_apis();

    logger::debug('SCORM', 'Updating topic SCORM for skilland id ' . $skilland->id);

    $lock = skilland_get_provision_lock((int) $skilland->id);
    try {
        $current = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);

        // Step 1: Delete the old SCORM activity if there is one.
        if (!empty($current->scormcmid)) {
            logger::debug('SCORM', 'Deleting old SCORM activity cmid ' . $current->scormcmid);
            skilland_delete_scorm_module((int) $current->scormcmid);

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
            skilland_copy_provisioning_fields($current, $skilland);

            logger::debug('SCORM', 'Old SCORM activity deleted');
        }

        // Step 2: Fetch fresh lesson data from Skilland to get updated timestamps.
        $lessons = mod_skilland_fetch_lessons($current->skilland_topicid);

        // Step 3: Provision the new SCORM package (the lock is already held).
        $newcmid = skilland_provision_topic_scorm_locked($current, $course, (int) $sectionnum);
        skilland_copy_provisioning_fields($current, $skilland);

        // Step 4: updatedat = version of the lesson in the installed package; only a successful
        // build advances it.
        foreach ($lessons as $lesson) {
            $updatedAt = !empty($lesson['updatedAt']) ? strtotime($lesson['updatedAt']) : time();
            $DB->set_field('skilland_lesson', 'updatedat', $updatedAt, [
                'skillandid' => $current->id,
                'skilland_lessonid' => $lesson['id']
            ]);
        }
        logger::debug('SCORM', 'Updated lesson timestamps from Skilland API');
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
    $scoreelements = ['cmi.core.score.raw', 'cmi.score.raw'];

    [$scosql, $params] = $DB->get_in_or_equal(array_keys($scotolesson), SQL_PARAMS_NAMED, 'sco');
    [$elementsql, $elementparams] = $DB->get_in_or_equal(array_merge($statuselements, $scoreelements),
        SQL_PARAMS_NAMED, 'el');
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
    foreach ($tracks as $track) {
        $lessonid = $scotolesson[(int) $track->scoid] ?? null;
        if ($lessonid === null) {
            continue;
        }
        $userid = (int) $track->userid;
        if (!isset($progress[$userid][$lessonid])) {
            $progress[$userid][$lessonid] = ['status' => null, 'score' => null];
        }
        $entry =& $progress[$userid][$lessonid];
        if (in_array($track->element, $statuselements, true)) {
            if ($entry['status'] === null) {
                $entry['status'] = skilland_normalise_scorm_status((string) $track->value);
            }
        } else if ($entry['score'] === null && is_numeric($track->value)) {
            $entry['score'] = (float) $track->value;
        }
        unset($entry);
    }

    return $progress;
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
function skilland_refresh_progress(stdClass $skilland, int $userid, ?array $tracks = null,
        ?array $existing = null): bool {
    global $DB;

    if ($tracks === null) {
        $tracks = skilland_read_scorm_progress($skilland, [$userid])[$userid] ?? [];
    }
    if (!$tracks) {
        return false;
    }

    if ($existing === null) {
        $existing = $DB->get_records('skilland_progress', ['skillandid' => $skilland->id, 'userid' => $userid], '',
            'lessonid, id, status, score');
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
    foreach ($DB->get_records('skilland_progress', ['skillandid' => $skillandid], '',
            'id, userid, lessonid, status, score') as $row) {
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
    $rows = $DB->get_records('skilland_progress', ['skillandid' => $skillandid, 'userid' => $userid], '',
        'lessonid, status, score');
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
 * Generate an SSO token for authenticating a Moodle user to Skilland.
 *
 * This function creates a signed JWT token that allows seamless authentication
 * from Moodle to Skilland without requiring the user to log in again.
 *
 * @param stdClass $user The Moodle user object
 * @param string $orgid The Skilland organization ID
 * @return string The signed JWT token
 * @throws moodle_exception If SSO secret is not configured or JWT library is not available
 */
function skilland_generate_sso_token($user, $orgid) {
    // Check if composer autoloader exists
    $autoloadpath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadpath)) {
        throw new moodle_exception('error', 'mod_skilland', '', null,
            'JWT library not installed. Please run "composer install" in the plugin directory.');
    }

    require_once($autoloadpath);

    // Check if JWT class is available
    if (!class_exists('\Firebase\JWT\JWT')) {
        throw new moodle_exception('error', 'mod_skilland', '', null,
            'JWT library not found. Please run "composer install" in the plugin directory.');
    }

    // Get SSO secret from config
    $ssosecret = get_config('mod_skilland', 'sso_secret');
    if (empty($ssosecret)) {
        throw new moodle_exception('error', 'mod_skilland', '', null,
            'SSO Shared Secret is not configured. Please set it in the plugin settings.');
    }

    // Get ALL courses user is enrolled in
    $enrolled_courses = enrol_get_users_courses($user->id, true);

    $course_access = [];
    foreach ($enrolled_courses as $course) {
        // Get Skilland skill ID from course custom field
        $skilland_skill_id = skilland_get_course_customfield_value($course->id);

        if ($skilland_skill_id) {
            $course_access[] = [
                'moodleCourseId' => (int)$course->id,
                'skillandSkillId' => $skilland_skill_id
            ];
        }
    }

    // Generate a unique nonce for this token
    $nonce = bin2hex(random_bytes(16));

    // Prepare token payload
    $payload = [
        'email' => $user->email,
        'name' => fullname($user),
        'orgId' => $orgid,
        'role' => 'Expert', // Default role for SSO users
        'nonce' => $nonce,
        'iat' => time(), // Issued at
        'source' => 'moodle',
        'courseAccess' => $course_access
    ];

    // Sign and return the token
    try {
        $token = \Firebase\JWT\JWT::encode($payload, $ssosecret, 'HS256');
        logger::debug('SSO', 'Generated token for user id ' . $user->id);
        return $token;
    } catch (Exception $e) {
        logger::error('SSO', 'Failed to generate token - ' . $e->getMessage());
        throw new moodle_exception('error', 'mod_skilland', '', null,
            'Failed to generate SSO token: ' . $e->getMessage());
    }
}

/**
 * Get the Skilland frontend URL for SSO login.
 *
 * This function constructs the SSO login URL with token and redirect parameters.
 *
 * @param string $token The SSO token
 * @param string $redirect The path to redirect to after login (default: /dashboard)
 * @return string The complete SSO login URL
 */
function skilland_get_sso_url($token, $redirect = '/dashboard') {
    // Get frontend URL from config
    $frontendurl = get_config('mod_skilland', 'frontend_url');
    if (empty($frontendurl)) {
        // Fallback to extracting from GraphQL endpoint if frontend_url not configured
        $graphqlendpoint = get_config('mod_skilland', 'graphql_endpoint');
        if (empty($graphqlendpoint)) {
            $graphqlendpoint = 'https://api.skilland.com/graphql';
        }
        // Extract base URL (remove /graphql suffix if present)
        $frontendurl = preg_replace('/\/graphql$/', '', $graphqlendpoint);
    }

    // Remove trailing slash if present
    $frontendurl = rtrim($frontendurl, '/');

    // Construct SSO URL
    $ssourl = $frontendurl . '/sso-login?' . http_build_query([
        'token' => $token,
        'redirect' => $redirect
    ]);

    return $ssourl;
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
 * Allowed hosts are the GraphQL endpoint host and the patterns in the package_hosts setting
 * (comma-separated, case-insensitive; "*.example.com" matches subdomains of example.com only).
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
 * Download a SCORM package to a temp file after validating its URL, size and format.
 *
 * @param string $packageurl
 * @return string Path of the downloaded zip; the caller deletes it.
 * @throws moodle_exception On any failed check; the temp file is removed first.
 */
function mod_skilland_download_package(string $packageurl): string {
    mod_skilland_require_https($packageurl, 'package');

    $endpoint = (string) (get_config('mod_skilland', 'graphql_endpoint') ?? '');
    if (!mod_skilland_package_host_allowed($packageurl, $endpoint)) {
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

/**
 * Normalise the errors member of a GraphQL response to its first error.
 *
 * @param mixed $errors The decoded "errors" member, null when absent.
 * @return array|null The first error as an array, or null when the response carries no errors.
 * @throws graphql_exception error_graphql_unknown when errors is present but malformed.
 */
function mod_skilland_first_graphql_error(mixed $errors): ?array {
    if ($errors === null) {
        return null;
    }
    if (!is_array($errors) || $errors === [] || array_keys($errors) !== range(0, count($errors) - 1)) {
        logger::error('GraphQL', 'Malformed errors member in response (' . get_debug_type($errors) . ')');
        throw new graphql_exception('error_graphql_unknown');
    }
    $first = $errors[0];
    if (is_array($first)) {
        return $first;
    }
    if (is_scalar($first)) {
        return ['message' => (string) $first];
    }
    logger::error('GraphQL', 'Malformed first error in response (' . get_debug_type($first) . ')');
    throw new graphql_exception('error_graphql_unknown');
}

/**
 * Map a GraphQL error response to the appropriate graphql_exception.
 *
 * Extracted from mod_skilland_graphql() for testability. Every field is read defensively: a
 * non-array extensions or a non-string code, message or details is treated as absent.
 *
 * @param array $error The first error from a GraphQL errors array.
 * @throws graphql_exception Always throws, carrying extensions.code as $graphqlcode.
 */
function mod_skilland_map_graphql_error(array $error): never {
    $extensions = is_array($error['extensions'] ?? null) ? $error['extensions'] : [];
    $errorcode = is_string($extensions['code'] ?? null) ? $extensions['code'] : '';
    $errormessage = is_string($error['message'] ?? null) ? $error['message'] :
        get_string('error_graphql_unknown', 'mod_skilland');
    $errordetails = is_string($extensions['details'] ?? null) ? $extensions['details'] : '';

    logger::error('GraphQL', 'Error received - Code: ' . $errorcode . ', Message: ' . $errormessage);

    switch ($errorcode) {
        case 'SKILLAND_MISSING_ORG_ID':
            throw new graphql_exception('error_config_missing_orgid', $errorcode);

        case 'SKILLAND_MISSING_API_KEY':
            throw new graphql_exception('error_config_missing_apikey', $errorcode);

        case 'SKILLAND_INVALID_ORG_ID_FORMAT':
            $detailedMsg = $errordetails ?: 'Invalid Organization ID format. Please check your Organization ID in plugin settings.';
            throw new graphql_exception('error_graphql', $errorcode, $detailedMsg);

        case 'SKILLAND_ORG_NOT_FOUND':
            $detailedMsg = $errordetails ?: 'Organization not found. Please verify your Organization ID in plugin settings.';
            throw new graphql_exception('error_graphql', $errorcode, $detailedMsg);

        case 'SKILLAND_API_KEY_NOT_FOUND':
            $detailedMsg = $errordetails ?: 'No API key found for this organization. Please generate an API key in Skilland organization settings.';
            throw new graphql_exception('error_graphql', $errorcode, $detailedMsg);

        case 'SKILLAND_API_KEY_INACTIVE':
            $detailedMsg = $errordetails ?: 'API key is inactive. Please regenerate the API key in Skilland organization settings.';
            throw new graphql_exception('error_graphql', $errorcode, $detailedMsg);

        case 'SKILLAND_INVALID_API_KEY':
        case 'SKILLAND_ORG_MISMATCH':
            $detailedMsg = $errordetails ?: 'Invalid API key. Please verify your API key in plugin settings.';
            throw new graphql_exception('error_config_invalid_credentials', $errorcode, $detailedMsg);

        default:
            $finalMessage = $errordetails ?: $errormessage;
            throw new graphql_exception('error_graphql', $errorcode, $finalMessage);
    }
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
 * (error_graphql, error_graphql_http, error_graphql_invalid_json, error_scorm_fetch_failed,
 * dml_exception, plain exceptions) becomes error_api_unavailable, so endpoints, curl errors and
 * SQL never reach the client. With devmode on, the raw message is appended for debugging.
 *
 * Pass-through allowlist: error_config_missing_orgid, error_config_missing_apikey,
 * error_config_missing_endpoint, error_config_invalid_credentials, error_config_missing_topicid,
 * error_config_missing_courseid, error_config_missing_lessonid, error_http_redirect,
 * error_scorm_not_available, error_plugin_disabled, error_course_not_mapped,
 * error_course_not_mapped_to_skill, error_lessons_not_in_topic, error_provision_in_progress.
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
 * Remembers, in the user's session, the SkilLand Studio path to open once the Moodle course form
 * has saved (SKL-664). The SSO token is minted at click time by sso_redirect.php, never here.
 *
 * @param int $courseid Moodle course ID.
 * @param string $path SkilLand Studio path; must start with /skills-studio/.
 * @return void
 * @throws coding_exception When the path is not a SkilLand Studio path.
 */
function mod_skilland_set_pending_studio_path(int $courseid, string $path): void {
    global $SESSION;

    if (strpos($path, '/skills-studio/') !== 0) {
        throw new coding_exception('Pending SkilLand path must start with /skills-studio/');
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
 * Returns the pending SkilLand Studio path for a course without consuming it (SKL-664).
 *
 * @param int $courseid Moodle course ID.
 * @return string|null The path, or null when none is pending or it is not a SkilLand Studio path.
 */
function mod_skilland_peek_pending_studio_path(int $courseid): ?string {
    global $SESSION;

    if (!is_object($SESSION) || empty($SESSION->mod_skilland_pending_studio[$courseid])) {
        return null;
    }
    $path = $SESSION->mod_skilland_pending_studio[$courseid];
    if (!is_string($path) || strpos($path, '/skills-studio/') !== 0) {
        return null;
    }
    return $path;
}

/**
 * Returns and clears the pending SkilLand Studio path for a course (SKL-664).
 *
 * @param int $courseid Moodle course ID.
 * @return string|null The path, or null when none is pending or it is not a SkilLand Studio path.
 */
function mod_skilland_take_pending_studio_path(int $courseid): ?string {
    global $SESSION;

    $path = mod_skilland_peek_pending_studio_path($courseid);
    if (is_object($SESSION) && isset($SESSION->mod_skilland_pending_studio[$courseid])) {
        unset($SESSION->mod_skilland_pending_studio[$courseid]);
    }
    return $path;
}
