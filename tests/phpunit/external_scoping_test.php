<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-661: the topic and lesson endpoints only serve the Skilland course
 * mapped to the Moodle course the caller holds capabilities in.
 */
class external_scoping_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    public static function setUpBeforeClass(): void {
        require_once __DIR__ . '/../../src/classes/external.php';
    }

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        // The module is enabled.
        $this->db->seed('modules', [(object)['id' => 1, 'name' => 'skilland', 'visible' => 1]]);
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'http://localhost:8000/graphql',
        ]];
        // Course A (id 10) is mapped to skill-a, course B (id 20) to skill-b.
        $GLOBALS['_test_customfield_value'] = [10 => 'skill-a', 20 => 'skill-b'];
        $GLOBALS['_test_denied_capabilities'] = [];
        unset($GLOBALS['_test_curl_response']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['_test_curl_response'],
            $GLOBALS['_test_customfield_value'],
            $GLOBALS['_test_denied_capabilities']
        );
        parent::tearDown();
    }

    private function stubCourseTopics(string $courseid, array $topicids): void {
        $topics = array_map(fn($id) => ['id' => $id, 'name' => $id, 'code' => '', 'description' => ''], $topicids);
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => ['course' => ['id' => $courseid, 'name' => 'Skill', 'topics' => $topics]]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    // ---------------------------------------------------------------
    // fetch_topics_ajax()
    // ---------------------------------------------------------------

    public function test_fetch_topics_rejects_another_courses_skill(): void {
        $this->stubCourseTopics('skill-b', ['topic-b1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland_external::fetch_topics_ajax('skill-b', 10);
    }

    public function test_fetch_topics_rejects_unmapped_course(): void {
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        \mod_skilland_external::fetch_topics_ajax('skill-a', 30);
    }

    public function test_fetch_topics_returns_topics_for_mapped_skill(): void {
        $this->stubCourseTopics('skill-a', ['topic-a1', 'topic-a2']);

        $result = \mod_skilland_external::fetch_topics_ajax('skill-a', 10);

        $this->assertNull($result['error']);
        $this->assertSame(['topic-a1', 'topic-a2'], array_column($result['topics'], 'id'));
    }

    public function test_fetch_topics_checks_capability_first(): void {
        $GLOBALS['_test_denied_capabilities'] = ['moodle/course:update'];

        $this->expectException(\required_capability_exception::class);

        \mod_skilland_external::fetch_topics_ajax('skill-a', 10);
    }

    // ---------------------------------------------------------------
    // fetch_lessons_ajax()
    // ---------------------------------------------------------------

    public function test_fetch_lessons_rejects_foreign_topic(): void {
        // Course A's skill lists only topic-a1; topic-b1 belongs to course B's skill.
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland_external::fetch_lessons_ajax('topic-b1', 10);
    }

    public function test_fetch_lessons_rejects_unmapped_course(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        \mod_skilland_external::fetch_lessons_ajax('topic-a1', 30);
    }

    public function test_fetch_lessons_accepts_topic_of_mapped_skill(): void {
        // The curl stub answers every query with the same body; a course payload
        // carries no lessons, so the call succeeds with an empty list.
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $result = \mod_skilland_external::fetch_lessons_ajax('topic-a1', 10);

        $this->assertNull($result['error']);
        $this->assertSame([], $result['lessons']);
    }

    // ---------------------------------------------------------------
    // Parameter types and the removed legacy endpoint
    // ---------------------------------------------------------------

    public function test_id_params_are_alphanumext(): void {
        $topics = \mod_skilland_external::fetch_topics_ajax_parameters();
        $lessons = \mod_skilland_external::fetch_lessons_ajax_parameters();

        $this->assertSame(PARAM_ALPHANUMEXT, $topics->keys['courseid']->type);
        $this->assertSame(PARAM_ALPHANUMEXT, $lessons->keys['topicid']->type);
    }

    public function test_legacy_lesson_scorm_endpoint_is_gone(): void {
        $this->assertFalse(method_exists(\mod_skilland_external::class, 'provision_lesson_scorm_ajax'));

        $srcdir = realpath(__DIR__ . '/../../src');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcdir, \FilesystemIterator::SKIP_DOTS));
        $hits = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || strpos($file->getPathname(), '/vendor/') !== false) {
                continue;
            }
            $content = file_get_contents($file->getPathname());
            if (strpos($content, 'mod_skilland_provision_lesson_scorm_ajax') !== false
                    || strpos($content, 'skilland_provision_lesson_scorm') !== false) {
                $hits[] = substr($file->getPathname(), strlen($srcdir) + 1);
            }
        }

        $this->assertSame([], $hits, 'Legacy per-lesson SCORM path still referenced');
    }
}
