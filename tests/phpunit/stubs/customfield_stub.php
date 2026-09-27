<?php
// Minimal core_customfield stub for standalone testing.
// Course custom field values come from $GLOBALS['_test_customfield_value'][<courseid>].

namespace core_customfield;

class field_controller {
    private $data;

    public function __construct(array $data) {
        $this->data = $data;
    }

    public function get(string $name) {
        return $this->data[$name] ?? null;
    }
}

class data_controller {
    private $field;
    private $value;

    public function __construct(field_controller $field, $value) {
        $this->field = $field;
        $this->value = $value;
    }

    public function get_field(): field_controller {
        return $this->field;
    }

    public function get_value() {
        return $this->value;
    }

    // A course mapping is a text field: core stores it in charvalue (value keeps a copy).
    public function datafield(): string {
        return 'charvalue';
    }

    // set() + save() write the value back to $GLOBALS['_test_customfield_value'] for the course.
    // Like core, a record without an id (no data yet for this course) cannot be saved without a
    // contextid, and only the datafield() column is what the field reads back.
    private $pending = [];

    public function get(string $name) {
        if ($name === 'id') {
            return $this->pending['id'] ?? ($this->value === null ? 0 : 1);
        }
        return $this->pending[$name] ?? ($name === $this->datafield() || $name === 'value' ? $this->value : null);
    }

    public function set(string $name, $value) {
        $this->pending[$name] = $value;
    }

    public function save() {
        if (!$this->get('id') && empty($this->pending['contextid'])) {
            throw new \coding_exception('customfield_data: contextid is required for a new record');
        }
        if (array_key_exists($this->datafield(), $this->pending)) {
            $this->value = $this->pending[$this->datafield()];
            $GLOBALS['_test_customfield_saved'][] = $this->value;
        }
    }
}

class handler {
    public static function get_handler(string $component, string $area, int $itemid = 0): handler {
        return new handler();
    }

    public function get_categories_with_fields(): array {
        return [];
    }

    public function get_instance_data(int $instanceid, bool $returnall = false): array {
        $values = $GLOBALS['_test_customfield_value'] ?? [];
        if (!array_key_exists($instanceid, $values)) {
            return [];
        }
        $field = new field_controller(['shortname' => 'skilland_course_id']);
        return [new data_controller($field, $values[$instanceid])];
    }
}
