<?php
// Minimal external API stubs for standalone testing of classes/external.php.
// Resolved through $CFG->libdir . '/externallib.php'.

if (!defined('VALUE_REQUIRED')) {
    define('VALUE_REQUIRED', 1);
}
if (!defined('VALUE_OPTIONAL')) {
    define('VALUE_OPTIONAL', 2);
}
if (!defined('VALUE_DEFAULT')) {
    define('VALUE_DEFAULT', 0);
}

if (!class_exists('external_description')) {
    abstract class external_description {
        public $desc;
        public $required;

        public function __construct($desc = '', $required = VALUE_REQUIRED) {
            $this->desc = $desc;
            $this->required = $required;
        }
    }
}

if (!class_exists('external_value')) {
    class external_value extends external_description {
        public $type;

        public function __construct($type, $desc = '', $required = VALUE_REQUIRED, $default = null, $allownull = true) {
            parent::__construct($desc, $required);
            $this->type = $type;
        }
    }
}

if (!class_exists('external_single_structure')) {
    class external_single_structure extends external_description {
        public $keys;

        public function __construct(array $keys = [], $desc = '', $required = VALUE_REQUIRED, $default = null) {
            parent::__construct($desc, $required);
            $this->keys = $keys;
        }
    }
}

if (!class_exists('external_function_parameters')) {
    class external_function_parameters extends external_single_structure {
    }
}

if (!class_exists('external_multiple_structure')) {
    class external_multiple_structure extends external_description {
        public $content;

        public function __construct($content, $desc = '', $required = VALUE_REQUIRED, $default = null) {
            parent::__construct($desc, $required);
            $this->content = $content;
        }
    }
}

if (!class_exists('external_api')) {
    class external_api {
        public static function validate_parameters(external_description $description, $params) {
            return $params;
        }
    }
}
