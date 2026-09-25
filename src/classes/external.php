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
 * External API for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once(__DIR__ . '/../locallib.php');

use mod_skilland\logger;

/**
 * External API class for mod_skilland.
 */
class mod_skilland_external extends external_api {
    /**
     * Throw an exception if the Skilland module is disabled.
     *
     * @throws moodle_exception
     */
    private static function require_enabled(): void {
        if (!skilland_is_enabled()) {
            throw new moodle_exception('error_plugin_disabled', 'mod_skilland');
        }
    }

    /**
     * Fetch courses from Skilland via GraphQL.
     * Non-admin users will only see courses where they are enrolled with editing capability.
     *
     * @param int $moodlecourseid Moodle course ID
     * @return array
     */
    public static function fetch_courses_ajax(int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::fetch_courses_ajax_parameters(), [
            'moodlecourseid' => $moodlecourseid,
        ]);
        $moodlecourseid = $params['moodlecourseid'];

        // Check capability in course context.
        $context = context_course::instance($moodlecourseid);
        require_capability('mod/skilland:accessstudio', $context);

        logger::debug('AJAX', 'fetch_courses_ajax called');

        try {
            $courses = mod_skilland_fetch_courses();
            logger::debug('AJAX', 'Received ' . count($courses) . ' courses from API');

            // For non-admin users, filter to only show Skilland courses
            // that are mapped to Moodle courses where the user has editing permissions.
            $isadmin = has_capability('moodle/site:config', context_system::instance());

            if (!$isadmin) {
                // Get list of Skilland course IDs from Moodle courses
                // where user has editing capability.
                $allowedSkillandIds = self::get_user_allowed_skilland_courses($USER->id);
                logger::debug('AJAX', 'User has access to ' . count($allowedSkillandIds) . ' Moodle-mapped Skilland courses');

                // Also get courses where user has edit permissions in Skilland
                // (owner or collaborator).
                $userCourses = mod_skilland_fetch_user_courses($USER->email);
                foreach ($userCourses as $course) {
                    $allowedSkillandIds[$course['id']] = true;
                }
                logger::debug('AJAX', 'After adding Skilland-editable courses: ' . count($allowedSkillandIds) . ' total allowed');

                // Filter API response to only return matching courses.
                $courses = array_filter($courses, function($course) use ($allowedSkillandIds) {
                    return isset($allowedSkillandIds[$course['id']]);
                });

                // Re-index array after filtering.
                $courses = array_values($courses);
                logger::debug('AJAX', 'After filtering: ' . count($courses) . ' courses available to user');
            }

            // Format courses for response.
            $formatted = [];
            foreach ($courses as $course) {
                $formatted[] = [
                    'id' => $course['id'],
                    'name' => $course['name'] ?? '',
                    'code' => $course['code'] ?? '',
                    'status' => $course['status'] ?? '',
                ];
            }

            return [
                'courses' => $formatted,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'fetch_courses error: ' . $e->getMessage());
            return [
                'courses' => [],
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'fetch_courses error: ' . $e->getMessage());
            return [
                'courses' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get array of Skilland course IDs that the user has access to.
     * Based on Moodle courses where the user is enrolled with editing capability.
     *
     * @param int $userid User ID
     * @return array Associative array with Skilland course IDs as keys
     */
    private static function get_user_allowed_skilland_courses(int $userid): array {
        $allowedSkillandIds = [];

        // Get user's enrolled courses.
        $enrolledcourses = enrol_get_users_courses($userid, true);

        foreach ($enrolledcourses as $course) {
            // Check if user has editing capability in this course.
            $ctx = context_course::instance($course->id);
            if (has_capability('moodle/course:update', $ctx, $userid)) {
                // Get the Skilland course ID from the custom field.
                $skillandId = skilland_get_course_customfield_value($course->id);
                if ($skillandId) {
                    $allowedSkillandIds[$skillandId] = true;
                }
            }
        }

        return $allowedSkillandIds;
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function fetch_courses_ajax_parameters() {
        return new external_function_parameters([
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function fetch_courses_ajax_returns() {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Course ID'),
                    'name' => new external_value(PARAM_TEXT, 'Course name'),
                    'code' => new external_value(PARAM_TEXT, 'Course code', VALUE_OPTIONAL),
                    'status' => new external_value(PARAM_TEXT, 'Course status', VALUE_OPTIONAL),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Create a new course in Skilland and map it to a Moodle course.
     *
     * @param int $moodlecourseid Moodle course ID
     * @return array
     */
    public static function create_course_ajax(int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::create_course_ajax_parameters(), [
            'moodlecourseid' => $moodlecourseid,
        ]);
        $moodlecourseid = $params['moodlecourseid'];

        // Check capability in course context.
        $context = context_course::instance($moodlecourseid);
        require_capability('mod/skilland:accessstudio', $context);

        logger::debug('AJAX', 'create_course_ajax called for moodle course ' . $moodlecourseid);

        try {
            // Get the Moodle course name.
            $course = get_course($moodlecourseid);
            $coursename = $course->fullname;

            // Create course in Skilland.
            $skill = mod_skilland_create_course($coursename, $USER->email);
            $skillid = $skill['id'];
            $skillname = $skill['name'] ?? $coursename;
            $creationstep = $skill['creationStep'] ?? 'microcredential-upload';

            logger::debug('AJAX', 'Created Skilland course: ' . $skillid . ' (' . $skillname . ')');

            // Save mapping to course custom field.
            skilland_set_course_customfield_value($moodlecourseid, $skillid);

            // Generate SSO redirect URL.
            $orgid = get_config('mod_skilland', 'orgid');
            $token = skilland_generate_sso_token($USER, $orgid);
            $redirect = '/skills-studio/create/' . $creationstep . '/' . $skillid;
            $redirecturl = skilland_get_sso_url($token, $redirect);

            return [
                'skillid' => $skillid,
                'name' => $skillname,
                'redirect_url' => $redirecturl,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'create_course error: ' . $e->getMessage());
            return [
                'skillid' => '',
                'name' => '',
                'redirect_url' => '',
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'create_course error: ' . $e->getMessage());
            return [
                'skillid' => '',
                'name' => '',
                'redirect_url' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of create_course_ajax parameters.
     *
     * @return external_function_parameters
     */
    public static function create_course_ajax_parameters() {
        return new external_function_parameters([
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of create_course_ajax result value.
     *
     * @return external_single_structure
     */
    public static function create_course_ajax_returns() {
        return new external_single_structure([
            'skillid' => new external_value(PARAM_TEXT, 'Skilland skill/course ID'),
            'name' => new external_value(PARAM_TEXT, 'Course name'),
            'redirect_url' => new external_value(PARAM_URL, 'SSO redirect URL to Skilland'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Fetch topics from Skilland via GraphQL for a given course.
     *
     * @param string $courseid Skilland course ID
     * @return array
     */
    public static function fetch_topics_ajax(string $courseid, int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::fetch_topics_ajax_parameters(), [
            'courseid' => $courseid,
            'moodlecourseid' => $moodlecourseid,
        ]);
        $courseid = $params['courseid'];
        $moodlecourseid = $params['moodlecourseid'];

        // Check capability in course context.
        $context = context_course::instance($moodlecourseid);
        require_capability('moodle/course:update', $context);

        // The requested Skilland course must be the one this Moodle course is mapped to.
        $courseid = skilland_require_mapped_course($moodlecourseid, $courseid);

        logger::debug('AJAX', 'fetch_topics_ajax called with courseid: ' . $courseid);

        try {
            $courseData = mod_skilland_fetch_topics($courseid);

            $topics = $courseData['topics'] ?? [];
            logger::debug('AJAX', 'Received ' . count($topics) . ' topics');

            // Format topics for response.
            $formatted = [];
            foreach ($topics as $topic) {
                $formatted[] = [
                    'id' => $topic['id'],
                    'name' => $topic['name'] ?? '',
                    'code' => $topic['code'] ?? '',
                    'description' => clean_text($topic['description'] ?? '', FORMAT_HTML),
                ];
            }

            $courseDetails = [
                'id' => $courseData['id'],
                'name' => $courseData['name'] ?? '',
                'code' => $courseData['code'] ?? $courseData['id'], // Fallback to ID if code is missing
            ];

            return [
                'course' => $courseDetails,
                'topics' => $formatted,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'fetch_topics error: ' . $e->getMessage());
            return [
                'course' => null,
                'topics' => [],
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'fetch_topics error: ' . $e->getMessage());
            return [
                'course' => null,
                'topics' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function fetch_topics_ajax_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_ALPHANUMEXT, 'Skilland course ID', VALUE_REQUIRED),
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function fetch_topics_ajax_returns() {
        return new external_single_structure([
            'course' => new external_single_structure([
                'id' => new external_value(PARAM_TEXT, 'Course ID'),
                'name' => new external_value(PARAM_TEXT, 'Course name', VALUE_OPTIONAL),
                'code' => new external_value(PARAM_TEXT, 'Course code', VALUE_OPTIONAL),
            ], 'Course details', VALUE_OPTIONAL),
            'topics' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Topic ID'),
                    'name' => new external_value(PARAM_TEXT, 'Topic name'),
                    'code' => new external_value(PARAM_TEXT, 'Topic code', VALUE_OPTIONAL),
                    'description' => new external_value(PARAM_CLEANHTML, 'Topic description', VALUE_OPTIONAL),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Fetch lessons from Skilland via GraphQL for a given topic.
     *
     * @param string $topicid Skilland topic ID
     * @return array
     */
    public static function fetch_lessons_ajax(string $topicid, int $moodlecourseid) {
        global $USER;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::fetch_lessons_ajax_parameters(), [
            'topicid' => $topicid,
            'moodlecourseid' => $moodlecourseid,
        ]);
        $topicid = $params['topicid'];
        $moodlecourseid = $params['moodlecourseid'];

        // Check capability in course context.
        $context = context_course::instance($moodlecourseid);
        require_capability('moodle/course:update', $context);

        // The requested topic must belong to the Skilland course this Moodle course is mapped to.
        $skillandcourseid = skilland_get_mapped_courseid($moodlecourseid);
        if ($skillandcourseid === null) {
            throw new moodle_exception('error_course_not_mapped', 'mod_skilland');
        }
        if (!skilland_topic_belongs_to_course($topicid, $skillandcourseid)) {
            throw new moodle_exception('error_course_not_mapped_to_skill', 'mod_skilland');
        }

        logger::debug('AJAX', 'fetch_lessons_ajax called with topicid: ' . $topicid);

        try {
            $lessons = mod_skilland_fetch_lessons($topicid);
            logger::debug('AJAX', 'Received ' . count($lessons) . ' lessons');

            // Format lessons for response.
            $formatted = [];
            foreach ($lessons as $lesson) {
                $formatted[] = [
                    'id' => $lesson['id'],
                    'name' => $lesson['name'] ?? '',
                    'updatedAt' => $lesson['updatedAt'] ?? '',
                ];
            }

            return [
                'lessons' => $formatted,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'fetch_lessons error: ' . $e->getMessage());
            return [
                'lessons' => [],
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'fetch_lessons error: ' . $e->getMessage());
            return [
                'lessons' => [],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function fetch_lessons_ajax_parameters() {
        return new external_function_parameters([
            'topicid' => new external_value(PARAM_ALPHANUMEXT, 'Skilland topic ID', VALUE_REQUIRED),
            'moodlecourseid' => new external_value(PARAM_INT, 'Moodle course ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function fetch_lessons_ajax_returns() {
        return new external_single_structure([
            'lessons' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_TEXT, 'Lesson ID'),
                    'name' => new external_value(PARAM_TEXT, 'Lesson name'),
                    'updatedAt' => new external_value(PARAM_TEXT, 'Last update timestamp'),
                ])
            ),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Provision a topic-level SCORM package containing all lessons as SCOs.
     *
     * @param int $skillandid The skilland activity record ID
     * @param int $cmid The course module ID of the skilland activity
     * @return array
     */
    public static function provision_topic_scorm_ajax(int $skillandid, int $cmid) {
        global $DB;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::provision_topic_scorm_ajax_parameters(), [
            'skillandid' => $skillandid,
            'cmid' => $cmid,
        ]);

        $skillandid = $params['skillandid'];
        $cmid = $params['cmid'];

        // Get the course module and context.
        $cm = get_coursemodule_from_id('skilland', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Check capability.
        require_capability('mod/skilland:provision', $context);

        logger::debug('AJAX', 'provision_topic_scorm_ajax called for skilland ' . $skillandid . ', cm ' . $cmid);

        try {
            // Get the skilland activity record.
            $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);

            // Verify this is the right activity.
            if ($skilland->id != $cm->instance) {
                throw new moodle_exception('invalidactivity', 'mod_skilland');
            }

            // Get the course.
            $course = get_course($cm->course);

            // Get the section number for the skilland activity.
            $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

            // Call the topic-level provisioning function.
            $scormcmid = skilland_provision_topic_scorm($skilland, $course, $sectionnum);

            logger::debug('AJAX', 'Successfully provisioned topic SCORM, cmid = ' . $scormcmid);

            return [
                'success' => true,
                'scormcmid' => $scormcmid,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'provision_topic_scorm error: ' . $e->getMessage());
            return [
                'success' => false,
                'scormcmid' => 0,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'provision_topic_scorm error: ' . $e->getMessage());
            return [
                'success' => false,
                'scormcmid' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function provision_topic_scorm_ajax_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity record ID', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the skilland activity', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function provision_topic_scorm_ajax_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether provisioning succeeded'),
            'scormcmid' => new external_value(PARAM_INT, 'The new SCORM course module ID'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    /**
     * Update a topic-level SCORM package with the latest content from Skilland.
     *
     * WARNING: This deletes all student progress/grades for this topic!
     *
     * @param int $skillandid The skilland activity record ID
     * @param int $cmid The course module ID of the skilland activity
     * @return array
     */
    public static function update_topic_scorm_ajax(int $skillandid, int $cmid) {
        global $DB;

        self::require_enabled();

        // Validate parameters.
        $params = self::validate_parameters(self::update_topic_scorm_ajax_parameters(), [
            'skillandid' => $skillandid,
            'cmid' => $cmid,
        ]);

        $skillandid = $params['skillandid'];
        $cmid = $params['cmid'];

        // Get the course module and context.
        $cm = get_coursemodule_from_id('skilland', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        // Check capability.
        require_capability('mod/skilland:provision', $context);

        logger::debug('AJAX', 'update_topic_scorm_ajax called for skilland ' . $skillandid . ', cm ' . $cmid);

        try {
            // Get the skilland activity record.
            $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);

            // Verify this is the right activity.
            if ($skilland->id != $cm->instance) {
                throw new moodle_exception('invalidactivity', 'mod_skilland');
            }

            // Get the course.
            $course = get_course($cm->course);

            // Get the section number for the skilland activity.
            $sectionnum = $DB->get_field('course_sections', 'section', ['id' => $cm->section]);

            // Call the update function.
            $scormcmid = skilland_update_topic_scorm($skilland, $course, $sectionnum);

            logger::debug('AJAX', 'Successfully updated topic SCORM, cmid = ' . $scormcmid);

            return [
                'success' => true,
                'scormcmid' => $scormcmid,
                'error' => null,
            ];
        } catch (moodle_exception $e) {
            logger::error('AJAX', 'update_topic_scorm error: ' . $e->getMessage());
            return [
                'success' => false,
                'scormcmid' => 0,
                'error' => $e->getMessage(),
            ];
        } catch (Exception $e) {
            logger::error('AJAX', 'update_topic_scorm error: ' . $e->getMessage());
            return [
                'success' => false,
                'scormcmid' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function update_topic_scorm_ajax_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity record ID', VALUE_REQUIRED),
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the skilland activity', VALUE_REQUIRED),
        ]);
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function update_topic_scorm_ajax_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether update succeeded'),
            'scormcmid' => new external_value(PARAM_INT, 'The new SCORM course module ID'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }

    // ─── Check Topic Snapshot ─────────────────────────────────

    /**
     * Check if topic content has changed in Skilland.
     *
     * @param int $skillandid Skilland activity record ID.
     * @return array { isstale: bool, contenthash: string, error: string|null }
     */
    public static function check_topic_snapshot(int $skillandid) {
        global $DB;

        self::require_enabled();

        $params = self::validate_parameters(self::check_topic_snapshot_parameters(), [
            'skillandid' => $skillandid,
        ]);

        $skillandid = $params['skillandid'];

        // Get the skilland record.
        $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);

        // Get the course module for context validation.
        $cm = get_coursemodule_from_instance('skilland', $skilland->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Same capability that shows the update checker in view.php.
        require_capability('mod/skilland:provision', $context);

        if (empty($skilland->skilland_topicid)) {
            return [
                'isstale' => false,
                'contenthash' => '',
                'error' => 'No topic ID configured',
            ];
        }

        try {
            $hashinfo = mod_skilland_check_topic_snapshot($skilland->skilland_topicid);

            if ($hashinfo === null) {
                return [
                    'isstale' => false,
                    'contenthash' => '',
                    'error' => 'Could not reach Skilland API',
                ];
            }

            $currentHash = $skilland->snapshotid ?? '';
            $remoteHash = $hashinfo['contentHash'] ?? '';
            $isstale = !empty($remoteHash) && $currentHash !== $remoteHash;

            return [
                'isstale' => $isstale,
                'contenthash' => $remoteHash,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'isstale' => false,
                'contenthash' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Parameters for check_topic_snapshot.
     *
     * @return external_function_parameters
     */
    public static function check_topic_snapshot_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity record ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Return type for check_topic_snapshot.
     *
     * @return external_single_structure
     */
    public static function check_topic_snapshot_returns() {
        return new external_single_structure([
            'isstale' => new external_value(PARAM_BOOL, 'Whether content has changed since last sync'),
            'contenthash' => new external_value(PARAM_TEXT, 'Current content hash from Skilland'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
