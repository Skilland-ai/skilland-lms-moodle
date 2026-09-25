<?php
defined('MOODLE_INTERNAL') || die();

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

    logger::debug('GraphQL', 'Starting request to ' . $endpoint);
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
    logger::debug('GraphQL', 'Headers set, making POST request to ' . $endpoint);
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
    logger::debug('GraphQL', 'CURL info dump: ' . print_r($info, true));

    if ($httpcode >= 300 && $httpcode < 400) {
        logger::error('GraphQL', 'Refusing redirect (HTTP ' . $httpcode . ') from ' . mod_skilland_redact_url($endpoint));
        throw new moodle_exception('error_http_redirect', 'mod_skilland', '', $httpcode);
    }

    if ($httpcode < 200 || $httpcode >= 300) {
        if ($errno) {
            // CURL error occurred (connection failed, DNS, SSL, etc.)
            $errormsg = 'HTTP error when calling Skilland API at ' . $endpoint . ': ' . $curlerror . ' (errno: ' . $errno . ')';

            // Check if this is a connection refused error and suggest Docker host fix
            if ($errno == 7 || (strpos($curlerror, 'Connection refused') !== false || strpos($curlerror, 'Could not connect') !== false)) {
                $parsed = parse_url($endpoint);
                $host = $parsed['host'] ?? '';

                // If using localhost and connection fails, suggest host.docker.internal for Docker
                if ($host === 'localhost' || $host === '127.0.0.1') {
                    $suggestedEndpoint = str_replace($host, 'host.docker.internal', $endpoint);
                    $errormsg .= ' If Moodle is running in Docker, try using "host.docker.internal" instead of "localhost" in the GraphQL endpoint setting. Suggested endpoint: ' . $suggestedEndpoint;
                }
            }

            logger::error('GraphQL', 'CURL error - ' . $errormsg);
        } else if ($httpcode == 0) {
            // HTTP 0 usually means connection failed - include curl error details if available
            $details = '';
            if ($errno) {
                $details = ' CURL errno: ' . $errno;
            }
            if ($curlerror && $curlerror !== 'Unknown error') {
                $details .= ' CURL error: ' . $curlerror;
            }

            $errormsg = 'HTTP error when calling Skilland API at ' . $endpoint . ': HTTP 0 (Connection failed.' . $details . ' Check endpoint URL and network connectivity.)';

            // Check if this is a connection refused error and suggest Docker host fix
            if ($errno == 7 || (strpos($curlerror, 'Connection refused') !== false || strpos($curlerror, 'Could not connect') !== false)) {
                $parsed = parse_url($endpoint);
                $host = $parsed['host'] ?? '';

                // If using localhost and connection fails, suggest host.docker.internal for Docker
                if ($host === 'localhost' || $host === '127.0.0.1') {
                    $suggestedEndpoint = str_replace($host, 'host.docker.internal', $endpoint);
                    $errormsg .= ' If Moodle is running in Docker, try using "host.docker.internal" instead of "localhost" in the GraphQL endpoint setting. Suggested endpoint: ' . $suggestedEndpoint;
                }
            }

            logger::error('GraphQL', 'HTTP 0 error - Connection failed to ' . $endpoint . $details);
        } else {
            $errormsg = 'HTTP error when calling Skilland API at ' . $endpoint . ': HTTP ' . $httpcode;
            logger::error('GraphQL', 'HTTP error - ' . $httpcode . ' for endpoint ' . $endpoint);
        }
        throw new moodle_exception('error_graphql_http', 'mod_skilland', '', $errormsg);
    }

    // Decode JSON response.
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('error_graphql_invalid_json', 'mod_skilland');
    }

    // Check for GraphQL errors.
    if (isset($data['errors']) && is_array($data['errors'])) {
        mod_skilland_map_graphql_error($data['errors'][0]);
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

        if (!isset($data['topicScorm'])) {
            throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
        }

        $scorm = $data['topicScorm'];

        // Build mappings array: lessonId => scoId.
        // The mappings field is a [JSON] scalar, so it comes as an array of objects.
        $mappings = [];
        if (!empty($scorm['mappings'])) {
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
                if ($lessonId && $scoId) {
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
    } catch (moodle_exception $e) {
        // Check for specific error codes in the message.
        $message = $e->getMessage();
        if (strpos($message, 'SCORM_NOT_AVAILABLE') !== false) {
            throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
        }
        if (strpos($message, 'TOPIC_NOT_FOUND') !== false) {
            throw new moodle_exception('error_config_missing_topicid', 'mod_skilland');
        }
        // Re-throw with context.
        throw new moodle_exception('error_scorm_fetch_failed', 'mod_skilland', '', $e->getMessage());
    }
}

/**
 * Provision a topic-level SCORM package containing all lessons as SCOs.
 *
 * This function:
 * 1. Fetches the topic SCORM package info from Skilland API (with lesson->SCO mappings)
 * 2. Downloads the single SCORM zip file
 * 3. Verifies the package hash
 * 4. Creates one hidden SCORM activity in Moodle
 * 5. Parses the SCORM package and extracts SCO identifiers
 * 6. Maps Skilland lesson IDs to Moodle SCO IDs using the API mappings
 * 7. Updates skilland_lesson records with scoid and sco_identifier
 * 8. Updates the skilland activity with scormcmid
 *
 * @param stdClass $skilland The skilland activity record
 * @param stdClass $course The Moodle course record
 * @param int $sectionnum The section number to add the SCORM to
 * @return int The new SCORM course module ID
 * @throws moodle_exception If provisioning fails
 */
function skilland_provision_topic_scorm($skilland, $course, $sectionnum = 0) {
    global $DB, $CFG, $USER;

    require_once($CFG->dirroot . '/course/lib.php');
    require_once($CFG->dirroot . '/mod/scorm/lib.php');
    require_once($CFG->dirroot . '/mod/scorm/locallib.php');
    require_once($CFG->libdir . '/filelib.php');

    // Step 1: Fetch topic SCORM package info from Skilland.
    logger::debug('SCORM', 'Provisioning topic SCORM for topic ' . $skilland->skilland_topicid);

    try {
        $scorminfo = mod_skilland_fetch_topic_scorm($skilland->skilland_topicid);
    } catch (moodle_exception $e) {
        logger::error('SCORM', 'Failed to fetch topic SCORM info - ' . $e->getMessage());
        throw $e;
    }

    if (empty($scorminfo['packageUrl'])) {
        throw new moodle_exception('error_scorm_not_available', 'mod_skilland');
    }

    // Step 2: Download the SCORM package to temp directory.
    $packageurl = $scorminfo['packageUrl'];
    $expectedhash = $scorminfo['packageHash'] ?? '';

    logger::debug('SCORM', 'Downloading topic SCORM package from ' . mod_skilland_redact_url($packageurl));

    $tempfile = mod_skilland_download_package($packageurl);

    logger::debug('SCORM', 'Downloaded topic SCORM package (' . filesize($tempfile) . ' bytes)');

    // Step 3: Verify the package hash (if provided).
    if (!empty($expectedhash)) {
        $hashparts = explode(':', $expectedhash, 2);
        $algorithm = count($hashparts) === 2 ? $hashparts[0] : 'sha256';
        $expected = count($hashparts) === 2 ? $hashparts[1] : $expectedhash;

        $actualhash = hash_file($algorithm, $tempfile);
        if (strcasecmp($actualhash, $expected) !== 0) {
            @unlink($tempfile);
            logger::error('SCORM', 'Hash mismatch - expected ' . $expected . ', got ' . $actualhash);
            throw new moodle_exception('error_scorm_hash_mismatch', 'mod_skilland');
        }
        logger::debug('SCORM', 'Package hash verified successfully');
    }

    // Step 4: Create the SCORM activity in Moodle.
    $scormname = $skilland->name . ' (SCORM)';

    // Get the SCORM module.
    $module = $DB->get_record('modules', ['name' => 'scorm'], '*', MUST_EXIST);

    // Get the section.
    $section = $DB->get_record('course_sections', [
        'course' => $course->id,
        'section' => $sectionnum
    ], '*', MUST_EXIST);

    // Create the course_modules record (hidden).
    $cm = new stdClass();
    $cm->course = $course->id;
    $cm->module = $module->id;
    $cm->instance = 0;
    $cm->section = $section->id;
    $cm->idnumber = 'skilland_topic_' . $skilland->id;
    $cm->added = time();
    $cm->visible = 1;  // Available to students but not shown on course page (stealth mode).
    $cm->visibleoncoursepage = 0;  // Not shown on course page.
    $cm->groupmode = 0;
    $cm->groupingid = 0;
    $cm->completion = 0;
    $cm->deletioninprogress = 0;

    $cmid = $DB->insert_record('course_modules', $cm);
    logger::debug('SCORM', 'Created course_modules record with id ' . $cmid);

    // Create the SCORM instance record.
    $scorm = new stdClass();
    $scorm->course = $course->id;
    $scorm->name = $scormname;
    $scorm->intro = '';
    $scorm->introformat = FORMAT_HTML;
    $scorm->scormtype = SCORM_TYPE_LOCAL;
    $scorm->reference = '';
    $scorm->version = 'SCORM_1.2';
    $scorm->maxgrade = 100;
    $scorm->grademethod = GRADESCOES;
    $scorm->whatgrade = HIGHESTATTEMPT;
    $scorm->maxattempt = 0;
    $scorm->forcecompleted = 0;
    $scorm->forcenewattempt = 0;
    $scorm->lastattemptlock = 0;
    $scorm->displayattemptstatus = 0;  // Don't show attempt status - managed by Skilland.
    $scorm->displaycoursestructure = 0;
    $scorm->updatefreq = SCORM_UPDATE_NEVER;
    $scorm->skipview = 2;  // Skip entry page.
    $scorm->hidebrowse = 1;
    $scorm->hidetoc = SCORM_TOC_DISABLED;  // Hide TOC - Skilland manages navigation.
    $scorm->nav = SCORM_NAV_DISABLED;  // No nav - single SCO per view.
    $scorm->navpositionleft = -100;
    $scorm->navpositiontop = -100;
    $scorm->auto = 0;
    $scorm->popup = 0;
    $scorm->width = 100;
    $scorm->height = 500;
    $scorm->timeopen = 0;
    $scorm->timeclose = 0;
    $scorm->displayactivityname = 0;  // Don't show name - Skilland shows it.
    $scorm->autocommit = 1;  // Auto-commit for better tracking.
    $scorm->masteryoverride = 1;
    $scorm->sha1hash = '';
    $scorm->revision = 0;
    $scorm->launch = 0;
    $scorm->timemodified = time();

    $scormid = $DB->insert_record('scorm', $scorm);
    $scorm->id = $scormid;
    logger::debug('SCORM', 'Created scorm record with id ' . $scormid);

    // Update course_modules with the instance id.
    $DB->set_field('course_modules', 'instance', $scormid, ['id' => $cmid]);

    // Add the SCORM to the section sequence.
    // Even with visibleoncoursepage=0 (stealth mode), activity MUST be in sequence
    // for Moodle's course_modinfo->get_cm() to work (required by SCORM player).
    $sequence = $section->sequence;
    if (!empty($sequence)) {
        $sequence .= ',' . $cmid;
    } else {
        $sequence = $cmid;
    }
    $DB->set_field('course_sections', 'sequence', $sequence, ['id' => $section->id]);

    // Rebuild the course cache.
    rebuild_course_cache($course->id, true);

    $context = context_module::instance($cmid);
    logger::debug('SCORM', 'Created SCORM activity with cmid ' . $cmid . ' (stealth mode)');

    // Step 5: Upload the SCORM package to the new activity.
    $fs = get_file_storage();

    $filerecord = [
        'contextid' => $context->id,
        'component' => 'mod_scorm',
        'filearea' => 'package',
        'itemid' => 0,
        'filepath' => '/',
        'filename' => 'scorm_package.zip',
        'userid' => $USER->id,
    ];

    $fs->delete_area_files($context->id, 'mod_scorm', 'package', 0);
    $storedfile = $fs->create_file_from_pathname($filerecord, $tempfile);
    @unlink($tempfile);

    if (!$storedfile) {
        throw new moodle_exception('error_scorm_upload_failed', 'mod_skilland');
    }

    logger::debug('SCORM', 'Uploaded SCORM package to file storage');

    // Step 6: Parse the SCORM package to create SCOs.
    $scorm->reference = $storedfile->get_filename();
    $scorm->cmid = $cmid;

    scorm_parse($scorm, true);
    $DB->update_record('scorm', $scorm);

    logger::debug('SCORM', 'Parsed SCORM package successfully');

    // Step 7: Map API lesson->SCO mappings to Moodle SCO records.
    // Get all SCOs created by the parse.
    $scos = $DB->get_records('scorm_scoes', ['scorm' => $scormid], 'id ASC');
    logger::debug('SCORM', 'Found ' . count($scos) . ' SCOs in parsed package');

    // Build a map of SCO identifier to Moodle SCO ID.
    $scoByIdentifier = [];
    foreach ($scos as $sco) {
        if (!empty($sco->identifier)) {
            $scoByIdentifier[$sco->identifier] = $sco->id;
        }
    }

    // Get API mappings (lessonId => scoId/identifier).
    $mappings = $scorminfo['mappings'] ?? [];
    logger::debug('SCORM', 'API provided ' . count($mappings) . ' lesson->SCO mappings');

    // Get all lessons for this Skilland activity.
    $lessons = $DB->get_records('skilland_lesson', ['skillandid' => $skilland->id, 'visible' => 1]);
    logger::debug('SCORM', 'Found ' . count($lessons) . ' lessons to map');

    // Update each lesson with its corresponding SCO ID.
    foreach ($lessons as $lesson) {
        $skillandLessonId = $lesson->skilland_lessonid;

        if (isset($mappings[$skillandLessonId])) {
            $scoIdentifier = $mappings[$skillandLessonId];
            $lesson->sco_identifier = $scoIdentifier;

            // Find the Moodle SCO ID by identifier.
            if (isset($scoByIdentifier[$scoIdentifier])) {
                $lesson->scoid = $scoByIdentifier[$scoIdentifier];
                logger::debug('SCORM', 'Mapped lesson ' . $skillandLessonId . ' to SCO ' . $lesson->scoid . ' (identifier: ' . $scoIdentifier . ')');
            } else {
                logger::warn('SCORM', 'SCO identifier ' . $scoIdentifier . ' not found in parsed SCOs for lesson ' . $skillandLessonId);
            }

            $DB->update_record('skilland_lesson', $lesson);
        } else {
            logger::warn('SCORM', 'No mapping found for lesson ' . $skillandLessonId);
        }
    }

    // Step 8: Update the skilland activity record with the SCORM cmid.
    $skilland->scormcmid = $cmid;
    $skilland->scorm_provisioned = time();
    $skilland->snapshotcreatedat = !empty($scorminfo['generatedAt']) ? strtotime($scorminfo['generatedAt']) : time();
    $DB->update_record('skilland', $skilland);

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
 * This function:
 * 1. Deletes the old SCORM activity and all its tracking data
 * 2. Creates a new SCORM package using skilland_provision_topic_scorm()
 * 3. Updates lesson timestamps to match current Skilland values
 *
 * WARNING: This will delete all student progress/grades for this topic!
 *
 * @param stdClass $skilland The skilland activity record
 * @param stdClass $course The Moodle course record
 * @param int $sectionnum The section number for the SCORM
 * @return int The new SCORM course module ID
 * @throws moodle_exception If update fails
 */
function skilland_update_topic_scorm($skilland, $course, $sectionnum = 0) {
    // Test hook: return override value if set (used by PHPUnit tests).
    if (array_key_exists('_test_update_topic_scorm', $GLOBALS)) {
        return $GLOBALS['_test_update_topic_scorm'];
    }

    global $DB, $CFG;

    require_once($CFG->dirroot . '/course/lib.php');

    logger::debug('SCORM', 'Updating topic SCORM for skilland id ' . $skilland->id);

    // Step 1: Delete the old SCORM activity if it exists
    if (!empty($skilland->scormcmid)) {
        $oldcm = get_coursemodule_from_id('scorm', $skilland->scormcmid, 0, false, IGNORE_MISSING);
        if ($oldcm) {
            logger::debug('SCORM', 'Deleting old SCORM activity cmid ' . $skilland->scormcmid);

            // Delete the course module (this also deletes SCORM tracking data)
            course_delete_module($oldcm->id);

            // Clear the scormcmid from skilland record
            $skilland->scormcmid = null;
            $skilland->scorm_provisioned = null;
            $DB->update_record('skilland', $skilland);

            // Clear SCO IDs from lesson records
            $DB->set_field('skilland_lesson', 'scoid', null, ['skillandid' => $skilland->id]);
            $DB->set_field('skilland_lesson', 'sco_identifier', null, ['skillandid' => $skilland->id]);

            logger::debug('SCORM', 'Old SCORM activity deleted');
        }
    }

    // Step 2: Fetch fresh lesson data from Skilland to get updated timestamps
    $lessons = mod_skilland_fetch_lessons($skilland->skilland_topicid);

    // Step 3: Update lesson timestamps in the database
    foreach ($lessons as $lesson) {
        $updatedAt = !empty($lesson['updatedAt']) ? strtotime($lesson['updatedAt']) : time();
        $DB->set_field('skilland_lesson', 'updatedat', $updatedAt, [
            'skillandid' => $skilland->id,
            'skilland_lessonid' => $lesson['id']
        ]);
    }
    logger::debug('SCORM', 'Updated lesson timestamps from Skilland API');

    // Step 4: Provision new SCORM package
    $newcmid = skilland_provision_topic_scorm($skilland, $course, $sectionnum);

    logger::debug('SCORM', 'Topic SCORM updated successfully, new cmid = ' . $newcmid);

    return $newcmid;
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
 * IP literals are refused unless the development flag is set.
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
    if (filter_var($host, FILTER_VALIDATE_IP) !== false && !$dev) {
        return false;
    }
    if ($dev && mod_skilland_is_local_host($host)) {
        return true;
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
 * Map a GraphQL error response to the appropriate moodle_exception.
 *
 * Extracted from mod_skilland_graphql() for testability.
 *
 * @param array $error The first error from a GraphQL errors array.
 * @throws moodle_exception Always throws.
 */
function mod_skilland_map_graphql_error(array $error): never {
    $errorcode = $error['extensions']['code'] ?? '';
    $errormessage = $error['message'] ?? get_string('error_graphql_unknown', 'mod_skilland');
    $errordetails = $error['extensions']['details'] ?? '';

    logger::error('GraphQL', 'Error received - Code: ' . $errorcode . ', Message: ' . $errormessage);

    switch ($errorcode) {
        case 'SKILLAND_MISSING_ORG_ID':
            throw new moodle_exception('error_config_missing_orgid', 'mod_skilland');

        case 'SKILLAND_MISSING_API_KEY':
            throw new moodle_exception('error_config_missing_apikey', 'mod_skilland');

        case 'SKILLAND_INVALID_ORG_ID_FORMAT':
            $detailedMsg = $errordetails ?: 'Invalid Organization ID format. Please check your Organization ID in plugin settings.';
            throw new moodle_exception('error_graphql', 'mod_skilland', '', $detailedMsg);

        case 'SKILLAND_ORG_NOT_FOUND':
            $detailedMsg = $errordetails ?: 'Organization not found. Please verify your Organization ID in plugin settings.';
            throw new moodle_exception('error_graphql', 'mod_skilland', '', $detailedMsg);

        case 'SKILLAND_API_KEY_NOT_FOUND':
            $detailedMsg = $errordetails ?: 'No API key found for this organization. Please generate an API key in Skilland organization settings.';
            throw new moodle_exception('error_graphql', 'mod_skilland', '', $detailedMsg);

        case 'SKILLAND_API_KEY_INACTIVE':
            $detailedMsg = $errordetails ?: 'API key is inactive. Please regenerate the API key in Skilland organization settings.';
            throw new moodle_exception('error_graphql', 'mod_skilland', '', $detailedMsg);

        case 'SKILLAND_INVALID_API_KEY':
        case 'SKILLAND_ORG_MISMATCH':
            $detailedMsg = $errordetails ?: 'Invalid API key. Please verify your API key in plugin settings.';
            throw new moodle_exception('error_config_invalid_credentials', 'mod_skilland', '', $detailedMsg);

        default:
            $finalMessage = $errordetails ?: $errormessage;
            throw new moodle_exception('error_graphql', 'mod_skilland', '', $finalMessage);
    }
}
