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
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ]];
        // Course A (id 10) is mapped to skill-a, course B (id 20) to skill-b.
        $GLOBALS['_test_customfield_value'] = [10 => 'skill-a', 20 => 'skill-b'];
        $GLOBALS['_test_denied_capabilities'] = [];
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_capability_course_ids']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['_test_curl_response'],
            $GLOBALS['_test_customfield_value'],
            $GLOBALS['_test_denied_capabilities'],
            $GLOBALS['_test_capability_course_ids']
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
    // fetch_topics::execute()
    // ---------------------------------------------------------------

    public function test_fetch_topics_rejects_another_courses_skill(): void {
        $this->stubCourseTopics('skill-b', ['topic-b1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_topics::execute('skill-b', 10);
    }

    public function test_fetch_topics_rejects_unmapped_course(): void {
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        \mod_skilland\external\fetch_topics::execute('skill-a', 30);
    }

    public function test_fetch_topics_returns_topics_for_mapped_skill(): void {
        $this->stubCourseTopics('skill-a', ['topic-a1', 'topic-a2']);

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', 10);

        $this->assertNull($result['error']);
        $this->assertSame(['topic-a1', 'topic-a2'], array_column($result['topics'], 'id'));
    }

    public function test_fetch_topics_checks_capability_first(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:accessstudio'];

        $this->expectException(\required_capability_exception::class);

        \mod_skilland\external\fetch_topics::execute('skill-a', 10);
    }

    // ---------------------------------------------------------------
    // fetch_lessons::execute()
    // ---------------------------------------------------------------

    public function test_fetch_lessons_rejects_foreign_topic(): void {
        // Course A's skill lists only topic-a1; topic-b1 belongs to course B's skill.
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_lessons::execute('topic-b1', 10);
    }

    public function test_fetch_lessons_rejects_unmapped_course(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        \mod_skilland\external\fetch_lessons::execute('topic-a1', 30);
    }

    public function test_fetch_lessons_accepts_topic_of_mapped_skill(): void {
        // The curl stub answers every query with the same body; a course payload
        // carries no lessons, so the call succeeds with an empty list.
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $result = \mod_skilland\external\fetch_lessons::execute('topic-a1', 10);

        $this->assertNull($result['error']);
        $this->assertSame([], $result['lessons']);
    }

    // ---------------------------------------------------------------
    // Parameter types and the removed legacy endpoint
    // ---------------------------------------------------------------

    public function test_id_params_are_alphanumext(): void {
        $topics = \mod_skilland\external\fetch_topics::execute_parameters();
        $lessons = \mod_skilland\external\fetch_lessons::execute_parameters();

        $this->assertSame(PARAM_ALPHANUMEXT, $topics->keys['courseid']->type);
        $this->assertSame(PARAM_ALPHANUMEXT, $lessons->keys['topicid']->type);
    }

    public function test_legacy_lesson_scorm_endpoint_is_gone(): void {
        $this->assertFileDoesNotExist(__DIR__ . '/../../src/classes/external/provision_lesson_scorm.php');
        $this->assertStringNotContainsString("'mod_skilland_provision_lesson_scorm", $this->srcFile('db/services.php'));

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

    // ---------------------------------------------------------------
    // Gap coverage (SKL-661 review)
    // ---------------------------------------------------------------

    private function srcFile(string $rel): string {
        return file_get_contents(__DIR__ . '/../../src/' . $rel);
    }

    public function test_teacher_of_course_a_cannot_fetch_topics_via_course_b(): void {
        // The caller teaches course 10 only; naming course 20 (and its skill) fails on the capability.
        $GLOBALS['_test_capability_course_ids'] = [10];
        $this->stubCourseTopics('skill-b', ['topic-b1']);

        $this->expectException(\required_capability_exception::class);

        \mod_skilland\external\fetch_topics::execute('skill-b', 20);
    }

    public function test_teacher_of_course_a_cannot_fetch_lessons_via_course_b(): void {
        $GLOBALS['_test_capability_course_ids'] = [10];
        $this->stubCourseTopics('skill-b', ['topic-b1']);

        $this->expectException(\required_capability_exception::class);

        \mod_skilland\external\fetch_lessons::execute('topic-b1', 20);
    }

    public function test_teacher_of_course_a_still_reads_own_course(): void {
        $GLOBALS['_test_capability_course_ids'] = [10];
        $this->stubCourseTopics('skill-a', ['topic-a1']);

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', 10);

        $this->assertSame(['topic-a1'], array_column($result['topics'], 'id'));
    }

    public function test_fetch_lessons_checks_capability_first(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:accessstudio'];

        $this->expectException(\required_capability_exception::class);

        \mod_skilland\external\fetch_lessons::execute('topic-a1', 10);
    }

    public function test_fetch_lessons_rejects_when_skill_has_no_topics(): void {
        $this->stubCourseTopics('skill-a', []);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_lessons::execute('topic-a1', 10);
    }

    public function test_fetch_lessons_rejects_when_api_returns_no_course(): void {
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['data' => ['course' => null]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_lessons::execute('topic-a1', 10);
    }

    public function test_fetch_lessons_throws_when_topic_check_api_fails(): void {
        // An API failure during the ownership check must surface as an exception, never as [].
        $GLOBALS['_test_curl_response'] = [
            'body' => 'boom',
            'http_code' => 500,
            'errno' => 0,
            'error' => '',
        ];

        $this->expectException(\moodle_exception::class);

        \mod_skilland\external\fetch_lessons::execute('topic-a1', 10);
    }

    public function test_fetch_topics_returns_empty_list_for_mapped_skill_without_topics(): void {
        $this->stubCourseTopics('skill-a', []);

        $result = \mod_skilland\external\fetch_topics::execute('skill-a', 10);

        $this->assertNull($result['error']);
        $this->assertSame([], $result['topics']);
    }

    public function test_fetch_topics_custom_field_wins_over_table(): void {
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 10, 'skilland_courseid' => 'skill-table'],
        ]);
        $this->stubCourseTopics('skill-table', ['topic-t1']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_topics::execute('skill-table', 10);
    }

    public function test_fetch_topics_uses_table_when_custom_field_empty_string(): void {
        $GLOBALS['_test_customfield_value'][40] = '';
        $this->db->seed('skilland_course', [
            (object)['id' => 1, 'course' => 40, 'skilland_courseid' => 'skill-table'],
        ]);
        $this->stubCourseTopics('skill-table', ['topic-t1']);

        $result = \mod_skilland\external\fetch_topics::execute('skill-table', 40);

        $this->assertSame(['topic-t1'], array_column($result['topics'], 'id'));
    }

    public function test_fetch_topics_rejects_empty_courseid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        \mod_skilland\external\fetch_topics::execute('', 10);
    }

    public function test_legacy_lesson_scorm_absent_from_services_and_amd_build(): void {
        $this->assertStringNotContainsString('provision_lesson_scorm', $this->srcFile('db/services.php'));
        $this->assertStringNotContainsString('provision_lesson_scorm', $this->srcFile('amd/build/provision_scorm.min.js'));
        $this->assertStringNotContainsString('provision_lesson_scorm', $this->srcFile('amd/src/provision_scorm.js'));
        $this->assertStringContainsString("'mod_skilland_provision_topic_scorm_ajax'", $this->srcFile('db/services.php'));
    }

    public function test_scormcmid_dropped_from_lesson_schema_and_backup(): void {
        $xml = simplexml_load_string($this->srcFile('db/install.xml'));
        $fields = [];
        foreach ($xml->xpath("//TABLE[@NAME='skilland_lesson']/FIELDS/FIELD") as $f) {
            $fields[] = (string)$f['NAME'];
        }
        $this->assertNotEmpty($fields);
        $this->assertNotContains('scormcmid', $fields);

        $backup = $this->srcFile('backup/moodle2/backup_skilland_stepslib.php');
        $this->assertMatchesRegularExpression("/new backup_nested_element\('lesson'/", $backup);
        preg_match("/new backup_nested_element\('lesson',.*?\)\);/s", $backup, $m);
        $this->assertNotEmpty($m);
        $this->assertStringNotContainsString('scormcmid', $m[0]);

        // The activity-level scormcmid (topic SCORM) is still mapped; the lesson-level one is dropped.
        $restore = $this->srcFile('backup/moodle2/restore_skilland_stepslib.php');
        $start = strpos($restore, 'function process_skilland_lesson');
        $this->assertNotFalse($start);
        $lessonfn = substr($restore, $start, strpos($restore, 'insert_record(\'skilland_lesson\'', $start) - $start);
        $this->assertStringNotContainsString("get_mappingid('course_module', \$data->scormcmid)", $lessonfn);
        $this->assertStringContainsString('unset($data->scormcmid)', $lessonfn);
    }

    public function test_upgrade_step_matches_version_and_guards_drop(): void {
        preg_match('/\$plugin->version\s*=\s*(\d+);/', $this->srcFile('version.php'), $vm);
        $this->assertNotEmpty($vm, 'plugin version not found');
        $this->assertGreaterThanOrEqual(2026092522, (int)$vm[1], 'version must reach the 2026092522 upgrade step');

        $upgrade = $this->srcFile('db/upgrade.php');
        $start = strpos($upgrade, 'if ($oldversion < 2026092522)');
        $this->assertNotFalse($start, 'upgrade step for 2026092522 missing');
        $block = substr($upgrade, $start, strpos($upgrade, "upgrade_mod_savepoint(true, 2026092522, 'skilland')", $start) - $start);

        $this->assertStringContainsString("upgrade_mod_savepoint(true, 2026092522, 'skilland')", $upgrade);
        $this->assertStringContainsString("\$configdata['locked'] = '1'", $block);
        $this->assertStringContainsString("new xmldb_field('scormcmid')", $block);
        $this->assertMatchesRegularExpression('/if \(\$dbman->field_exists\(\$table, \$lessonfield\)\) \{\s*\$dbman->drop_field\(\$table, \$lessonfield\);/', $block);
    }

    public function test_new_customfield_is_created_locked(): void {
        $this->assertMatchesRegularExpression('/"locked":"1"/', $this->srcFile('locallib.php'));
    }

    public function test_new_error_strings_exist_in_en_and_es(): void {
        foreach (['en', 'es'] as $lang) {
            $string = [];
            require __DIR__ . "/../../src/lang/$lang/skilland.php";
            foreach (['error_course_not_mapped', 'error_course_not_mapped_to_skill'] as $key) {
                $this->assertArrayHasKey($key, $string, "$lang missing $key");
                $this->assertNotSame('', trim($string[$key]));
            }
        }
    }

    public function test_param_alphanumext_in_external_and_form_source(): void {
        $this->assertStringContainsString("'courseid' => new external_value(PARAM_ALPHANUMEXT",
            $this->srcFile('classes/external/fetch_topics.php'));
        $this->assertStringContainsString("'topicid' => new external_value(PARAM_ALPHANUMEXT",
            $this->srcFile('classes/external/fetch_lessons.php'));

        $form = $this->srcFile('mod_form.php');
        $this->assertStringContainsString("setType('skilland_topicid', PARAM_ALPHANUMEXT)", $form);
        $this->assertStringContainsString("setType('skilland_topicid_saved', PARAM_ALPHANUMEXT)", $form);
    }

    public function test_form_validation_checks_topic_against_mapped_skill(): void {
        $form = $this->srcFile('mod_form.php');
        $this->assertMatchesRegularExpression(
            "/skilland_topic_belongs_to_course\(\(string\)\\\$topicid, \(string\)\\\$skillandcourseid\)/",
            $form
        );
        $this->assertStringContainsString("get_string('error_course_not_mapped_to_skill', 'mod_skilland')", $form);
    }
}
