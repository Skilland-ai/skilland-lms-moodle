<?php

// Moodle constants used by source files.
if (!defined('FEATURE_MOD_INTRO')) {
    define('FEATURE_MOD_INTRO', 'mod_intro');
}
if (!defined('PARAM_INT')) {
    define('PARAM_INT', 'int');
}
if (!defined('PARAM_TEXT')) {
    define('PARAM_TEXT', 'text');
}
if (!defined('PARAM_RAW')) {
    define('PARAM_RAW', 'raw');
}
if (!defined('PARAM_URL')) {
    define('PARAM_URL', 'url');
}
if (!defined('SQL_PARAMS_NAMED')) {
    define('SQL_PARAMS_NAMED', 1);
}
if (!defined('MUST_EXIST')) {
    define('MUST_EXIST', 2);
}
if (!defined('IGNORE_MISSING')) {
    define('IGNORE_MISSING', 0);
}
if (!defined('FORMAT_HTML')) {
    define('FORMAT_HTML', 1);
}

// Exception class used throughout Moodle code.
if (!class_exists('moodle_exception')) {
    class moodle_exception extends \Exception {
        public $errorcode;
        public $module;
        public $link;
        public $a;
        public $debuginfo;

        public function __construct($errorcode, $module = '', $link = '', $a = null, $debuginfo = null) {
            $this->errorcode = $errorcode;
            $this->module = $module;
            $this->link = $link;
            $this->a = $a;
            $this->debuginfo = $debuginfo;
            parent::__construct($errorcode . ($a ? ": $a" : ''));
        }
    }
}

if (!class_exists('dml_exception')) {
    class dml_exception extends \moodle_exception {
        public function __construct(string $error, $a = null, $debuginfo = null) {
            parent::__construct($error, '', '', $a, $debuginfo);
        }
    }
}

if (!class_exists('dml_missing_record_exception')) {
    class dml_missing_record_exception extends \dml_exception {
        public function __construct(string $table) {
            parent::__construct('dmlreadexception', null, "Record not found in table: $table");
        }
    }
}

// html_writer stub.
if (!class_exists('html_writer')) {
    class html_writer {
        public static function start_div(string $class = '', array $attributes = []): string {
            $attrs = self::build_attrs(array_merge(['class' => $class], $attributes));
            return '<div' . $attrs . '>';
        }

        public static function end_div(): string {
            return '</div>';
        }

        public static function div(string $content, string $class = '', array $attributes = []): string {
            return self::start_div($class, $attributes) . $content . self::end_div();
        }

        public static function link($url, string $text, array $attributes = []): string {
            $href = is_object($url) ? $url->out(false) : $url;
            $attrs = self::build_attrs(array_merge(['href' => $href], $attributes));
            return '<a' . $attrs . '>' . $text . '</a>';
        }

        public static function span(string $content, string $class = '', array $attributes = []): string {
            $attrs = self::build_attrs(array_merge(['class' => $class], $attributes));
            return '<span' . $attrs . '>' . $content . '</span>';
        }

        public static function tag(string $tag, string $content, array $attributes = []): string {
            $attrs = self::build_attrs($attributes);
            return '<' . $tag . $attrs . '>' . $content . '</' . $tag . '>';
        }

        public static function start_tag(string $tag, array $attributes = []): string {
            $attrs = self::build_attrs($attributes);
            return '<' . $tag . $attrs . '>';
        }

        public static function end_tag(string $tag): string {
            return '</' . $tag . '>';
        }

        private static function build_attrs(array $attributes): string {
            $result = '';
            foreach ($attributes as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $result .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
                }
            }
            return $result;
        }
    }
}

// cached_cm_info stub — returned by {mod}_get_coursemodule_info().
if (!class_exists('cached_cm_info')) {
    class cached_cm_info {
        public $name;
        public $icon;
        public $iconurl;
        public $customdata;
    }
}

// moodle_url stub.
if (!class_exists('moodle_url')) {
    class moodle_url {
        private $path;
        private $params;

        public function __construct(string $path = '', array $params = []) {
            $this->path = $path;
            $this->params = $params;
        }

        public function out(bool $escaped = true): string {
            $url = $this->path;
            if (!empty($this->params)) {
                $url .= '?' . http_build_query($this->params);
            }
            return $url;
        }

        public function set_anchor(string $anchor): void {
            // No-op for tests.
        }
    }
}

// Simple function stubs.
if (!function_exists('format_string')) {
    function format_string($string) {
        return $string;
    }
}

if (!function_exists('required_param')) {
    function required_param(string $name, string $type) {
        return $_GET[$name] ?? $_POST[$name] ?? null;
    }
}

if (!function_exists('optional_param')) {
    function optional_param(string $name, $default, string $type) {
        return $_GET[$name] ?? $_POST[$name] ?? $default;
    }
}

if (!function_exists('fullname')) {
    function fullname($user): string {
        $first = $user->firstname ?? '';
        $last = $user->lastname ?? '';
        return trim($first . ' ' . $last);
    }
}

if (!function_exists('enrol_get_users_courses')) {
    function enrol_get_users_courses($userid, $active = true) {
        return $GLOBALS['_test_enrolled_courses'] ?? [];
    }
}

if (!function_exists('userdate')) {
    function userdate($date, $format = '') {
        return date('Y-m-d', $date);
    }
}

// Moodle course-module helper stubs — configurable via $GLOBALS for tests.
if (!function_exists('get_coursemodule_from_id')) {
    function get_coursemodule_from_id($module, $cmid, $courseid = 0, $sectionflag = false, $strictness = 0) {
        if (array_key_exists('_test_get_coursemodule_from_id', $GLOBALS)) {
            return $GLOBALS['_test_get_coursemodule_from_id'];
        }
        return (object)['id' => $cmid, 'instance' => $cmid, 'course' => 1, 'section' => 1];
    }
}

if (!function_exists('get_coursemodule_from_instance')) {
    function get_coursemodule_from_instance($module, $instanceid, $courseid = 0, $sectionflag = false, $strictness = 0) {
        if (array_key_exists('_test_get_coursemodule_from_instance', $GLOBALS)) {
            return $GLOBALS['_test_get_coursemodule_from_instance'];
        }
        return (object)['id' => $instanceid, 'instance' => $instanceid, 'course' => 1, 'section' => 1];
    }
}

if (!function_exists('get_course')) {
    function get_course($courseid) {
        if (array_key_exists('_test_get_course', $GLOBALS)) {
            return $GLOBALS['_test_get_course'];
        }
        return (object)['id' => $courseid, 'fullname' => 'Test Course'];
    }
}

if (!function_exists('course_delete_module')) {
    function course_delete_module($cmid) {
        // No-op for tests.
    }
}

// Admin setting base classes used by mod_skilland\admin_setting_* classes.
if (!class_exists('admin_setting_configtext')) {
    class admin_setting_configtext {
        public $name;
        public $visiblename;
        public $description;
        public $defaultsetting;
        public $paramtype;

        public function __construct($name, $visiblename, $description, $defaultsetting, $paramtype = PARAM_RAW, $size = null) {
            $this->name = $name;
            $this->visiblename = $visiblename;
            $this->description = $description;
            $this->defaultsetting = $defaultsetting;
            $this->paramtype = $paramtype;
        }

        public function validate($data) {
            return true;
        }
    }
}

if (!class_exists('admin_setting_configpasswordunmask')) {
    class admin_setting_configpasswordunmask extends admin_setting_configtext {
        public function __construct($name, $visiblename, $description, $defaultsetting) {
            parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW);
        }
    }
}

// Scheduled task base class loaded from separate file (namespaces can't be in if blocks).
if (!class_exists('core\task\scheduled_task')) {
    require_once __DIR__ . '/scheduled_task_stub.php';
}
