<?php
// Minimal core_external API stubs (Moodle 4.2+) for standalone testing of classes/external/*.php.
// Namespaced, so it cannot sit inside class_exists guards: bootstrap.php loads it once with require_once.
//
// external_api::validate_context() records every context it receives in
// $GLOBALS['_test_validated_contexts'] and appends ['validate_context', $context] to
// $GLOBALS['_test_call_order'] (require_capability() appends to the same list, so tests can
// prove the order). It throws \require_login_exception when the context's instance id is listed
// in $GLOBALS['_test_login_denied_ids'] (a suspended enrolment, a hidden course, ...).

namespace {
    if (!defined('VALUE_REQUIRED')) {
        define('VALUE_REQUIRED', 1);
    }
    if (!defined('VALUE_OPTIONAL')) {
        define('VALUE_OPTIONAL', 2);
    }
    if (!defined('VALUE_DEFAULT')) {
        define('VALUE_DEFAULT', 0);
    }
}

namespace core_external {

    abstract class external_description {
        public $desc;
        public $required;

        public function __construct($desc = '', $required = VALUE_REQUIRED) {
            $this->desc = $desc;
            $this->required = $required;
        }
    }

    class external_value extends external_description {
        public $type;
        public $default;
        public $allownull;

        public function __construct($type, $desc = '', $required = VALUE_REQUIRED, $default = null, $allownull = true) {
            parent::__construct($desc, $required);
            $this->type = $type;
            $this->default = $default;
            $this->allownull = $allownull;
        }
    }

    class external_single_structure extends external_description {
        public $keys;

        public function __construct(array $keys = [], $desc = '', $required = VALUE_REQUIRED, $default = null) {
            parent::__construct($desc, $required);
            $this->keys = $keys;
        }
    }

    class external_function_parameters extends external_single_structure {
    }

    class external_multiple_structure extends external_description {
        public $content;

        public function __construct($content, $desc = '', $required = VALUE_REQUIRED, $default = null) {
            parent::__construct($desc, $required);
            $this->content = $content;
        }
    }

    class external_api {
        public static function validate_parameters(external_description $description, $params) {
            return $params;
        }

        public static function validate_context($context) {
            $GLOBALS['_test_validated_contexts'][] = $context;
            $GLOBALS['_test_call_order'][] = ['validate_context', $context];
            $instanceid = $context->instanceid ?? null;
            if ($instanceid !== null && in_array($instanceid, $GLOBALS['_test_login_denied_ids'] ?? [], true)) {
                throw new \require_login_exception('Suspended or not enrolled');
            }
        }
    }
}
