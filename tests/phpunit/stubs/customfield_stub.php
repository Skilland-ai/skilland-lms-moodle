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
