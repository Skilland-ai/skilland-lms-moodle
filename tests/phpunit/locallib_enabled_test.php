<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

class locallib_enabled_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
    }

    public function test_returns_true_when_module_visible(): void {
        $this->db->seed('modules', [
            (object)['id' => 1, 'name' => 'skilland', 'visible' => 1],
        ]);

        $this->assertTrue(skilland_is_enabled());
    }

    public function test_returns_false_when_module_hidden(): void {
        $this->db->seed('modules', [
            (object)['id' => 1, 'name' => 'skilland', 'visible' => 0],
        ]);

        $this->assertFalse(skilland_is_enabled());
    }

    public function test_returns_false_when_module_not_installed(): void {
        // No modules seeded at all.
        $this->assertFalse(skilland_is_enabled());
    }

    public function test_returns_false_when_db_throws(): void {
        $this->db->set_throw_on_get_record(
            new \dml_exception('dmlreadexception', null, 'Table "modules" does not exist')
        );

        $this->assertFalse(skilland_is_enabled());
    }
}
