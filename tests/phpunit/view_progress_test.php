<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class view_progress_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        unset($GLOBALS['_test_get_coursemodule_from_id']);
        \mod_skilland\logger::reset_cache();
    }

    // ---------------------------------------------------------------
    // skilland_get_lessons_progress() — early exits
    // ---------------------------------------------------------------

    public function test_progress_returns_empty_when_tables_missing(): void {
        // Neither scorm_scoes_value nor scorm_attempt exists.
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', false);
        $this->db->get_manager()->set_table_exists('scorm_attempt', false);

        $result = skilland_get_lessons_progress(1, 10);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_empty_when_no_skilland_record(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        // No skilland record seeded.

        $result = skilland_get_lessons_progress(1, 999);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_empty_when_no_scormcmid(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('skilland', [
            (object)['id' => 10, 'scormcmid' => null],
        ]);

        $result = skilland_get_lessons_progress(1, 10);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_empty_when_scorm_cm_not_found(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('skilland', [
            (object)['id' => 10, 'scormcmid' => 100],
        ]);
        $GLOBALS['_test_get_coursemodule_from_id'] = false;

        $result = skilland_get_lessons_progress(1, 10);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_empty_when_scorm_record_not_found(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('skilland', [
            (object)['id' => 10, 'scormcmid' => 100],
        ]);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        // No scorm record seeded.

        $result = skilland_get_lessons_progress(1, 10);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_empty_when_no_lessons_have_scoid(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('skilland', [
            (object)['id' => 10, 'scormcmid' => 100],
        ]);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('scorm', [
            (object)['id' => 50, 'course' => 1],
        ]);
        // Lessons with no scoid.
        $this->db->seed('skilland_lesson', [
            (object)['id' => 1, 'skillandid' => 10, 'visible' => 1, 'scoid' => null, 'title' => 'No SCO'],
        ]);

        $result = skilland_get_lessons_progress(1, 10);

        $this->assertEmpty($result);
    }

    public function test_progress_returns_not_started_when_no_attempt(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $this->db->seed('skilland', [
            (object)['id' => 10, 'scormcmid' => 100],
        ]);
        $GLOBALS['_test_get_coursemodule_from_id'] = (object)['id' => 100, 'instance' => 50, 'course' => 1];
        $this->db->seed('scorm', [
            (object)['id' => 50, 'course' => 1],
        ]);
        $this->db->seed('skilland_lesson', [
            (object)['id' => 1, 'skillandid' => 10, 'visible' => 1, 'scoid' => 200, 'title' => 'Lesson A'],
            (object)['id' => 2, 'skillandid' => 10, 'visible' => 1, 'scoid' => 201, 'title' => 'Lesson B'],
        ]);
        // No scorm_attempt seeded → get_record_sql returns false.

        $result = skilland_get_lessons_progress(1, 10);

        // Should return not_started for all lessons.
        $this->assertCount(2, $result);
        $this->assertEquals('not_started', $result[1]['status']);
        $this->assertNull($result[1]['score']);
        $this->assertEquals('not_started', $result[2]['status']);
    }
}
