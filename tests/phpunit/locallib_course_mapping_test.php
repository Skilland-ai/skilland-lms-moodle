<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_course_mapping_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_customfield_value'] = [];
        unset($GLOBALS['_test_curl_response']);
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_curl_response'], $GLOBALS['_test_customfield_value']);
        parent::tearDown();
    }

    private function stubTopics(string $courseid, array $topicids): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ];
        $topics = array_map(fn($id) => ['id' => $id, 'name' => $id, 'description' => ''], $topicids);
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['topics' => $topics]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
    }

    // ---------------------------------------------------------------
    // skilland_get_mapped_courseid(): the custom field is the only mapping (SKL-661, SKL-689)
    // ---------------------------------------------------------------

    public function test_get_mapped_courseid_reads_the_custom_field(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-field';

        $this->assertSame('skill-field', skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_never_reads_a_table(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-field';

        skilland_get_mapped_courseid(10);
        skilland_get_mapped_courseid(11);

        $this->assertSame([], $this->db->get_calls_for('get_record'));
        $this->assertSame([], $this->db->get_calls_for('record_exists'));
    }

    public function test_get_mapped_courseid_returns_null_when_unmapped(): void {
        $this->assertNull(skilland_get_mapped_courseid(999));
    }

    // ---------------------------------------------------------------
    // skilland_require_mapped_course() (SKL-661)
    // ---------------------------------------------------------------

    public function test_require_mapped_course_passes_on_match(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->assertSame('skill-a', skilland_require_mapped_course(10, 'skill-a'));
    }

    public function test_require_mapped_course_throws_on_mismatch(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, 'skill-b');
    }

    public function test_require_mapped_course_throws_when_unmapped(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        skilland_require_mapped_course(10, 'skill-a');
    }

    public function test_require_mapped_course_throws_when_the_custom_field_is_empty(): void {
        $GLOBALS['_test_customfield_value'][10] = '';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/^error_course_not_mapped$/');

        skilland_require_mapped_course(10, 'skill-a');
    }

    // ---------------------------------------------------------------
    // skilland_topic_belongs_to_course() (SKL-661)
    // ---------------------------------------------------------------

    public function test_topic_belongs_to_course_true_for_listed_topic(): void {
        $this->stubTopics('skill-a', ['topic-1', 'topic-2']);

        $this->assertTrue(skilland_topic_belongs_to_course('topic-2', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_for_foreign_topic(): void {
        $this->stubTopics('skill-a', ['topic-1', 'topic-2']);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-foreign', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_for_empty_ids(): void {
        $this->stubTopics('skill-a', ['topic-1']);

        $this->assertFalse(skilland_topic_belongs_to_course('', 'skill-a'));
        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', ''));
    }

    public function test_get_mapped_courseid_empty_custom_field_is_unmapped(): void {
        $GLOBALS['_test_customfield_value'][10] = '';

        $this->assertNull(skilland_get_mapped_courseid(10));
    }

    public function test_get_mapped_courseid_null_custom_field_is_unmapped(): void {
        $GLOBALS['_test_customfield_value'][10] = null;

        $this->assertNull(skilland_get_mapped_courseid(10));
    }

    public function test_require_mapped_course_rejects_empty_request_id(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, '');
    }

    public function test_require_mapped_course_is_case_sensitive(): void {
        $GLOBALS['_test_customfield_value'][10] = 'skill-a';

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('error_course_not_mapped_to_skill');

        skilland_require_mapped_course(10, 'SKILL-A');
    }

    public function test_topic_belongs_to_course_false_for_empty_topic_list(): void {
        $this->stubTopics('skill-a', []);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_false_when_the_skill_is_not_in_the_organization(): void {
        $this->stubTopics('skill-a', []);
        $GLOBALS['_test_curl_response']['http_code'] = 404;
        $GLOBALS['_test_curl_response']['body'] = json_encode(['error' => 'Skill not found']);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_ignores_topics_without_id(): void {
        $this->stubTopics('skill-a', []);
        $GLOBALS['_test_curl_response']['body'] = json_encode(['topics' => [['name' => 'topic-1']]]);

        $this->assertFalse(skilland_topic_belongs_to_course('topic-1', 'skill-a'));
    }

    public function test_topic_belongs_to_course_throws_on_api_failure(): void {
        $this->stubTopics('skill-a', ['topic-1']);
        $GLOBALS['_test_curl_response']['http_code'] = 500;
        $GLOBALS['_test_curl_response']['body'] = 'boom';

        $this->expectException(\moodle_exception::class);

        skilland_topic_belongs_to_course('topic-1', 'skill-a');
    }

    // ---------------------------------------------------------------
    // Fail closed on a blank field (SKL-689)
    // ---------------------------------------------------------------

    /**
     * A field holding only whitespace is unmapped.
     */
    public function test_get_mapped_courseid_whitespace_custom_field_is_unmapped(): void {
        $GLOBALS['_test_customfield_value'][10] = " \t\n ";

        $this->assertNull(skilland_get_mapped_courseid(10));
    }

    /**
     * The mapped value is returned without the whitespace around it.
     */
    public function test_get_mapped_courseid_trims_the_custom_field(): void {
        $GLOBALS['_test_customfield_value'][10] = ' skill-a ';

        $this->assertSame('skill-a', skilland_get_mapped_courseid(10));
    }

    /**
     * A whitespace field never authorizes a request, even one naming the same whitespace.
     */
    public function test_require_mapped_course_whitespace_field_fails_closed(): void {
        $GLOBALS['_test_customfield_value'][10] = '   ';

        foreach (['   ', '', 'skill-a'] as $requested) {
            try {
                skilland_require_mapped_course(10, $requested);
                $this->fail('A whitespace field must not authorize ' . json_encode($requested));
            } catch (\moodle_exception $e) {
                $this->assertSame('error_course_not_mapped', $e->errorcode);
            }
        }
    }

    /**
     * A course with no custom field data at all fails closed.
     */
    public function test_require_mapped_course_course_without_field_data_fails_closed(): void {
        // Course 10 is not in _test_customfield_value: the handler returns no data for it.
        try {
            skilland_require_mapped_course(10, 'skill-a');
            $this->fail('An unmapped course must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_course_not_mapped', $e->errorcode);
        }
        $this->assertSame([], $this->db->get_calls_for('get_record'), 'No table is consulted as a fallback');
    }

    /**
     * The SSO handoff of an unmapped course opens the skills list, never a skill or topic path.
     */
    public function test_sso_studio_path_of_an_unmapped_course_is_the_skills_list(): void {
        $GLOBALS['_test_customfield_value'][10] = '';
        $GLOBALS['_test_customfield_value'][11] = '  ';

        $this->assertSame('/skills', skilland_studio_redirect_path(skilland_get_mapped_courseid(10) ?? '', 'topic-1'));
        $this->assertSame('/skills', skilland_studio_redirect_path(skilland_get_mapped_courseid(11) ?? '', 'topic-1'));
        $this->assertSame('/skills', skilland_studio_redirect_path(skilland_get_mapped_courseid(12) ?? '', 'topic-1'));
    }

    /**
     * sso_redirect.php resolves the skill only through skilland_get_mapped_courseid().
     */
    public function test_sso_redirect_resolves_the_skill_only_from_the_custom_field(): void {
        $source = file_get_contents(__DIR__ . '/../../src/sso_redirect.php');

        $this->assertStringContainsString('$skillandcourseid = skilland_get_mapped_courseid($courseid) ?? \'\';', $source);
        $this->assertSame(1, preg_match_all('/\$skillandcourseid\s*=/', $source));
        $this->assertStringNotContainsString('skilland_get_course_customfield_value', $source);
        $this->assertStringNotContainsString('skilland_get_skilland_courseid', $source);
    }
}
