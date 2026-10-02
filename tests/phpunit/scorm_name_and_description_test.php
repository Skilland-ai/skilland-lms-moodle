<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * Hide Skilland labels also applies to the hidden topic SCORM's name, which follows the activity's
 * name, and the activity can show its description on the course page (SKL-689).
 */
class scorm_name_and_description_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    private const GLOBALS_TO_RESET = [
        '_test_curl_response', '_test_customfield_value', '_test_cm_from_db', '_test_get_coursemodule_from_id',
        '_test_scorm_grade_item_updates', '_test_rebuilt_course_caches', '_test_format_module_intro',
        '_test_curl_requests', '_test_curl_last', '_test_curl_responses',
    ];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = ['mod_skilland' => (object)[
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000/graphql',
        ]];
        $GLOBALS['_test_customfield_value'] = [3 => 'skill-a'];
        $GLOBALS['_test_curl_response'] = [
            'body' => json_encode(['topics' => [['id' => 'topic1', 'name' => 'topic1', 'description' => '']]]),
            'http_code' => 200,
            'errno' => 0,
            'error' => '',
        ];
        $GLOBALS['_test_cm_from_db'] = true;
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    /**
     * Seed a provisioned activity (id 7, course 3) and its hidden SCORM (cm 50, scorm 60).
     *
     * @param string $name The activity name.
     * @param int $hidelabels The hidelabels setting.
     * @param string $scormname The current SCORM name.
     */
    private function seedProvisioned(string $name, int $hidelabels, string $scormname): void {
        $this->db->seed('skilland', [(object)[
            'id' => 7, 'course' => 3, 'name' => $name, 'hidelabels' => $hidelabels, 'skilland_topicid' => 'topic1',
            'scormcmid' => 50, 'grade' => 0, 'topic_orderindex' => 1,
        ]]);
        $this->db->seed('course_modules', [
            (object)['id' => 50, 'course' => 3, 'module' => 99, 'instance' => 60, 'section' => 20,
                'idnumber' => 'skilland_topic_7'],
        ]);
        $this->db->seed('scorm', [(object)['id' => 60, 'course' => 3, 'name' => $scormname, 'maxgrade' => 0]]);
    }

    private function scormName(): string {
        return (string) $this->db->get_record('scorm', ['id' => 60])->name;
    }

    // ---------------------------------------------------------------
    // skilland_strip_topic_label()
    // ---------------------------------------------------------------

    public function test_strip_topic_label_removes_the_leading_topic_code(): void {
        $this->assertSame('Foo', skilland_strip_topic_label('T1 - Foo'));
        $this->assertSame('Foo', skilland_strip_topic_label('T12-Foo'));
        $this->assertSame('Foo', skilland_strip_topic_label('T3 -Foo'));
    }

    public function test_strip_topic_label_keeps_a_code_that_does_not_lead(): void {
        $this->assertSame('Foo T1 - Bar', skilland_strip_topic_label('Foo T1 - Bar'));
        $this->assertSame('Topic - Foo', skilland_strip_topic_label('Topic - Foo'));
        $this->assertSame('', skilland_strip_topic_label(''));
    }

    // ---------------------------------------------------------------
    // skilland_scorm_module_name()
    // ---------------------------------------------------------------

    public function test_scorm_module_name_keeps_the_code_without_hidelabels(): void {
        $this->assertSame('T1 - Foo (SCORM)', skilland_scorm_module_name('T1 - Foo'));
        $this->assertSame('T1 - Foo (SCORM)', skilland_scorm_module_name('T1 - Foo', false));
    }

    public function test_scorm_module_name_strips_the_code_with_hidelabels(): void {
        $this->assertSame('Foo (SCORM)', skilland_scorm_module_name('T1 - Foo', true));
        $this->assertSame('Foo T1 - Bar (SCORM)', skilland_scorm_module_name('Foo T1 - Bar', true));
    }

    public function test_scorm_module_name_caps_a_long_multibyte_name_with_hidelabels(): void {
        $name = skilland_scorm_module_name('T1 - ' . str_repeat('é', 255), true);

        $this->assertLessThanOrEqual(255, \core_text::strlen($name));
        $this->assertStringEndsWith(' (SCORM)', $name);
        $this->assertStringStartsWith('é', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    // ---------------------------------------------------------------
    // skilland_sync_scorm_module_name()
    // ---------------------------------------------------------------

    public function test_sync_renames_the_scorm_and_its_grade_item_with_hidelabels(): void {
        $this->seedProvisioned('T1 - Topic', 1, 'T1 - Topic (SCORM)');

        skilland_sync_scorm_module_name($this->db->get_record('skilland', ['id' => 7]));

        $this->assertSame('Topic (SCORM)', $this->scormName());
        $updates = $GLOBALS['_test_scorm_grade_item_updates'] ?? [];
        $this->assertCount(1, $updates);
        $this->assertSame('Topic (SCORM)', $updates[0]->name);
        $this->assertSame('skilland_topic_7', $updates[0]->cmidnumber);
        $this->assertSame([3], $GLOBALS['_test_rebuilt_course_caches'] ?? []);
    }

    public function test_sync_restores_the_code_without_hidelabels(): void {
        $this->seedProvisioned('T1 - Topic', 0, 'Topic (SCORM)');

        skilland_sync_scorm_module_name($this->db->get_record('skilland', ['id' => 7]));

        $this->assertSame('T1 - Topic (SCORM)', $this->scormName());
    }

    public function test_sync_leaves_a_scorm_that_already_has_the_name(): void {
        $this->seedProvisioned('T1 - Topic', 1, 'Topic (SCORM)');

        skilland_sync_scorm_module_name($this->db->get_record('skilland', ['id' => 7]));

        $this->assertEmpty($this->db->get_calls_for('set_field'));
        $this->assertEmpty($GLOBALS['_test_scorm_grade_item_updates'] ?? []);
        $this->assertEmpty($GLOBALS['_test_rebuilt_course_caches'] ?? []);
    }

    public function test_sync_ignores_an_unprovisioned_or_missing_scorm(): void {
        skilland_sync_scorm_module_name((object)['id' => 7, 'course' => 3, 'name' => 'T1 - A', 'hidelabels' => 1,
            'scormcmid' => null]);
        skilland_sync_scorm_module_name((object)['id' => 7, 'course' => 3, 'name' => 'T1 - A', 'hidelabels' => 1,
            'scormcmid' => 404]);

        $this->assertEmpty($this->db->get_calls_for('set_field'));
        $this->assertEmpty($GLOBALS['_test_scorm_grade_item_updates'] ?? []);
    }

    // ---------------------------------------------------------------
    // skilland_update_instance() renames the SCORM
    // ---------------------------------------------------------------

    private function updateData(array $fields): \stdClass {
        return (object)($fields + ['course' => 3, 'instance' => 7, 'skilland_topicid' => 'topic1']);
    }

    public function test_update_instance_toggling_hidelabels_renames_the_scorm(): void {
        $this->seedProvisioned('T1 - Topic', 0, 'T1 - Topic (SCORM)');

        $this->assertTrue(skilland_update_instance($this->updateData(['name' => 'T1 - Topic', 'hidelabels' => 1])));
        $this->assertSame('Topic (SCORM)', $this->scormName());

        $this->assertTrue(skilland_update_instance($this->updateData(['name' => 'T1 - Topic', 'hidelabels' => 0])));
        $this->assertSame('T1 - Topic (SCORM)', $this->scormName());
    }

    public function test_update_instance_renaming_the_activity_renames_the_scorm(): void {
        $this->seedProvisioned('T1 - Topic', 0, 'T1 - Topic (SCORM)');

        $this->assertTrue(skilland_update_instance($this->updateData(['name' => 'T1 - Renamed', 'hidelabels' => 0])));

        $this->assertSame('T1 - Renamed (SCORM)', $this->scormName());
    }

    public function test_update_instance_without_a_name_change_leaves_the_scorm_alone(): void {
        $this->seedProvisioned('T1 - Topic', 0, 'Custom (SCORM)');

        $this->assertTrue(skilland_update_instance($this->updateData(['name' => 'T1 - Topic', 'hidelabels' => 0])));

        $this->assertSame('Custom (SCORM)', $this->scormName());
    }

    public function test_update_instance_survives_a_failing_rename(): void {
        $this->db = new class extends \FakeDatabase {
            public function set_field(string $table, string $field, $value, array $conditions = []): bool {
                throw new \RuntimeException('write failed for ' . $value);
            }
        };
        $GLOBALS['DB'] = $this->db;
        $this->seedProvisioned('T1 - Topic', 0, 'T1 - Topic (SCORM)');

        $this->assertTrue(skilland_update_instance($this->updateData(['name' => 'T1 - Renamed', 'hidelabels' => 1])));

        $messages = array_column($GLOBALS['_test_debug_messages'], 'message');
        $renames = array_values(array_filter($messages, fn($m) => str_contains($m, 'renaming the SCORM')));
        $this->assertCount(1, $renames);
        $this->assertStringNotContainsString('Renamed', $renames[0]);
    }

    // ---------------------------------------------------------------
    // Show description on the course page
    // ---------------------------------------------------------------

    public function test_supports_show_description(): void {
        $this->assertTrue(skilland_supports(FEATURE_SHOW_DESCRIPTION));
    }

    public function test_coursemodule_info_shows_the_intro_only_with_showdescription(): void {
        $this->db->seed('skilland', [(object)['id' => 1, 'name' => 'T1 - Topic', 'hidelabels' => 0,
            'intro' => 'About the topic', 'introformat' => 1]]);

        $without = skilland_get_coursemodule_info((object)['id' => 11, 'instance' => 1]);
        $off = skilland_get_coursemodule_info((object)['id' => 11, 'instance' => 1, 'showdescription' => 0]);
        $on = skilland_get_coursemodule_info((object)['id' => 11, 'instance' => 1, 'showdescription' => 1]);

        $this->assertNull($without->content);
        $this->assertNull($off->content);
        $this->assertSame('<div class="intro">About the topic</div>', $on->content);
        $this->assertSame([['module' => 'skilland', 'cmid' => 11, 'filter' => false]],
            $GLOBALS['_test_format_module_intro']);
    }
}
