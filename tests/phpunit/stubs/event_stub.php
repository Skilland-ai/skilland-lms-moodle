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

/**
 * Like Moodle's: objectid is the cmid, other carries modulename and instanceid.
 */
class course_module_deleted extends base {
    protected function init() {
        $this->data['objecttable'] = 'course_modules';
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
    }
}

// mod_scorm tracking events (Moodle 4.x): the context is the SCORM module, so contextinstanceid
// is its cmid, and relateduserid is the learner.
namespace mod_scorm\event;

abstract class cmielement_submitted extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'scorm_scoes_value';
    }
}

class status_submitted extends cmielement_submitted {
}

class scoreraw_submitted extends cmielement_submitted {
}
