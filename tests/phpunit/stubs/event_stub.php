<?php
// Minimal \core\event base classes for standalone testing.
// trigger() appends the event to $GLOBALS['_test_events'].

namespace core\event;

abstract class base {
    const LEVEL_OTHER = 0;
    const LEVEL_TEACHING = 1;
    const LEVEL_PARTICIPATING = 2;

    /** @var array Event data, as in Moodle's base::$data. */
    protected $data = [];

    /** @var array Record snapshots keyed by table then id. */
    public $snapshots = [];

    abstract protected function init();

    final public function __construct() {
    }

    public static function create(?array $data = null) {
        $event = new static();
        $event->init();
        $event->data = array_merge($event->data, $data ?? []);
        return $event;
    }

    public function add_record_snapshot($tablename, $record) {
        $this->snapshots[$tablename][$record->id] = $record;
    }

    public function get_record_snapshot($tablename, $id) {
        return $this->snapshots[$tablename][$id] ?? false;
    }

    public function get_data() {
        return $this->data;
    }

    public function __get($name) {
        return $this->data[$name] ?? null;
    }

    public function trigger() {
        $GLOBALS['_test_events'][] = $this;
    }
}

abstract class course_module_viewed extends base {
}
