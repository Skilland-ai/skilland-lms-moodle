<?php
// Minimal core_external API stubs (Moodle 4.2+) for standalone testing of classes/external/*.php.
// Namespaced, so it cannot sit inside class_exists guards: bootstrap.php loads it once with require_once.
//
// external_api::validate_context() records every context it receives in
// $GLOBALS['_test_validated_contexts'] and appends ['validate_context', $context] to
// $GLOBALS['_test_call_order'] (require_capability() appends to the same list, so tests can
// prove the order). It throws \require_login_exception when the context's instance id is listed
// in $GLOBALS['_test_login_denied_ids'] (a suspended enrolment, a hidden course, ...).
//
// external_api::clean_returnvalue() is a faithful subset of core's: it throws
// \invalid_response_exception wherever core would reject a web service result (a null or scalar
// single structure, a missing VALUE_REQUIRED key, a non-array multiple structure, null for a value
// that does not allow it, an array for a scalar value), so tests can prove a payload survives the
// real response validation. It does not reproduce core's per-PARAM cleaning.

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

        public static function clean_returnvalue(external_description $description, $response) {
            if ($description instanceof external_value) {
                if (is_array($response) || is_object($response)) {
                    throw new \invalid_response_exception('Scalar type expected, array or object received.');
                }
                if ($response === null) {
                    if ($description->allownull) {
                        return null;
                    }
                    throw new \invalid_response_exception('Null value not allowed for ' . $description->desc);
                }
                if ($description->type === PARAM_BOOL) {
                    if (is_bool($response) || $response === 0 || $response === 1 || $response === '0'
                            || $response === '1') {
                        return (bool) $response;
                    }
                    throw new \invalid_response_exception('Invalid boolean: ' . print_r($response, true));
                }
                if ($description->type === PARAM_INT) {
                    if (is_int($response) || (is_string($response) && preg_match('/^-?\d+$/', $response))) {
                        return (int) $response;
                    }
                    throw new \invalid_response_exception('Invalid integer: ' . print_r($response, true));
                }
                return $response;
            }

            if ($description instanceof external_single_structure) {
                if (!is_array($response) && !is_object($response)) {
                    throw new \invalid_response_exception('Only arrays/objects accepted. The bad value is: \''
                        . print_r($response, true) . '\'');
                }
                $response = (array) $response;
                $result = [];
                foreach ($description->keys as $key => $subdesc) {
                    if (!array_key_exists($key, $response)) {
                        if ($subdesc->required == VALUE_REQUIRED) {
                            throw new \invalid_response_exception(
                                'Error in response - Missing following required key in a single structure: ' . $key);
                        }
                        if ($subdesc instanceof external_value && $subdesc->required == VALUE_DEFAULT) {
                            $result[$key] = self::clean_returnvalue($subdesc, $subdesc->default);
                        }
                        continue;
                    }
                    try {
                        $result[$key] = self::clean_returnvalue($subdesc, $response[$key]);
                    } catch (\invalid_response_exception $e) {
                        throw new \invalid_response_exception($key . ' => ' . $e->debuginfo);
                    }
                }
                return $result;
            }

            if ($description instanceof external_multiple_structure) {
                if (!is_array($response)) {
                    throw new \invalid_response_exception('Only arrays accepted. The bad value is: \''
                        . print_r($response, true) . '\'');
                }
                $result = [];
                foreach ($response as $item) {
                    $result[] = self::clean_returnvalue($description->content, $item);
                }
                return $result;
            }

            throw new \invalid_response_exception('Invalid external api response description');
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
