<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-667: index.php's course_module_instance_list_viewed event, fired when the course-level
 * list of Skilland activities is viewed.
 */
class course_module_instance_list_viewed_event_test extends TestCase {

    private const COURSE_ID = 5;

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_events'] = [];
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_events']);
        parent::tearDown();
    }

    public function test_inherits_core_read_crud_and_other_edulevel(): void {
        // The plugin's class declares no init() of its own, so it inherits core's exactly.
        $event = \mod_skilland\event\course_module_instance_list_viewed::create([
            'context' => (object) ['id' => 42, 'contextlevel' => 50, 'instanceid' => self::COURSE_ID],
        ]);

        $data = $event->get_data();

        $this->assertSame('r', $data['crud']);
        $this->assertSame(\core\event\base::LEVEL_OTHER, $data['edulevel']);
    }

    public function test_init_does_not_declare_an_objecttable(): void {
        $event = \mod_skilland\event\course_module_instance_list_viewed::create([
            'context' => (object) ['id' => 42],
        ]);

        $this->assertArrayNotHasKey('objecttable', $event->get_data());
    }

    public function test_extends_the_core_course_module_instance_list_viewed_event(): void {
        $event = \mod_skilland\event\course_module_instance_list_viewed::create([
            'context' => (object) ['id' => 42],
        ]);

        $this->assertInstanceOf(\core\event\course_module_instance_list_viewed::class, $event);
        $this->assertInstanceOf(\core\event\base::class, $event);
    }

    public function test_trigger_records_the_event_with_its_course_snapshot(): void {
        $course = (object) ['id' => self::COURSE_ID, 'fullname' => 'Course Five'];

        $event = \mod_skilland\event\course_module_instance_list_viewed::create([
            'context' => (object) ['id' => 42],
        ]);
        $event->add_record_snapshot('course', $course);
        $event->trigger();

        $this->assertCount(1, $GLOBALS['_test_events']);
        $triggered = $GLOBALS['_test_events'][0];
        $this->assertSame($event, $triggered);
        $this->assertSame($course, $triggered->get_record_snapshot('course', self::COURSE_ID));
    }

    public function test_create_merges_caller_supplied_data_over_init_defaults(): void {
        $context = (object) ['id' => 99];

        $event = \mod_skilland\event\course_module_instance_list_viewed::create([
            'context' => $context,
            'courseid' => self::COURSE_ID,
        ]);

        $data = $event->get_data();
        $this->assertSame($context, $data['context']);
        $this->assertSame(self::COURSE_ID, $data['courseid']);
        // The caller-supplied data does not clobber what init() set.
        $this->assertSame('r', $data['crud']);
    }
}
