<?php

// Moodle constants used by source files.
if (!defined('FEATURE_MOD_INTRO')) {
    define('FEATURE_MOD_INTRO', 'mod_intro');
}
if (!defined('FEATURE_COMPLETION_TRACKS_VIEWS')) {
    define('FEATURE_COMPLETION_TRACKS_VIEWS', 'completion_tracks_views');
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
if (!defined('PARAM_CLEANHTML')) {
    define('PARAM_CLEANHTML', 'cleanhtml');
}
if (!defined('PARAM_ALPHANUMEXT')) {
    define('PARAM_ALPHANUMEXT', 'alphanumext');
}
if (!defined('PARAM_BOOL')) {
    define('PARAM_BOOL', 'bool');
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
if (!function_exists('clean_text')) {
    // Minimal stand-in for Moodle's HTML Purifier: drops script blocks and on* handlers.
    function clean_text($text, $format = FORMAT_HTML) {
        $text = preg_replace('#<script\b[^>]*>.*?</script>#is', '', (string)$text);
        return preg_replace('#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $text);
    }
}

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
        // Opt-in: resolve against the course_modules rows of the fake $DB.
        if (!empty($GLOBALS['_test_cm_from_db'])) {
            $cm = $GLOBALS['DB']->get_record('course_modules', ['id' => $cmid]);
            if (!$cm && $strictness === MUST_EXIST) {
                throw new \dml_missing_record_exception('course_modules');
            }
            return $cm;
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
    // Records the cmid in $GLOBALS['_test_deleted_cmids'] and removes the module rows from the fake $DB;
    // $GLOBALS['_test_course_delete_throw'] (an exception) makes it throw after recording.
    // A deleted module triggers \core\event\course_module_deleted (modulename from the row's
    // modname, default 'scorm'); $GLOBALS['_test_dispatch_observers'] = true also delivers it to
    // \mod_skilland\observer::course_module_deleted().
    function course_delete_module($cmid, $async = false) {
        $GLOBALS['_test_deleted_cmids'][] = (int)$cmid;
        if (!empty($GLOBALS['_test_course_delete_throw'])) {
            throw $GLOBALS['_test_course_delete_throw'];
        }
        $db = $GLOBALS['DB'];
        $cm = $db->get_record('course_modules', ['id' => $cmid]);
        if ($cm) {
            if (!empty($cm->instance)) {
                $db->delete_records('scorm_scoes', ['scorm' => $cm->instance]);
                $db->delete_records('scorm', ['id' => $cm->instance]);
            }
            $db->delete_records('course_modules', ['id' => $cmid]);
            $event = \core\event\course_module_deleted::create([
                'objectid' => (int)$cmid,
                'courseid' => $cm->course ?? 0,
                'contextinstanceid' => (int)$cmid,
                'other' => [
                    'modulename' => $cm->modname ?? 'scorm',
                    'instanceid' => $cm->instance ?? 0,
                ],
            ]);
            $event->trigger();
            if (!empty($GLOBALS['_test_dispatch_observers'])) {
                \mod_skilland\observer::course_module_deleted($event);
            }
        }
        return true;
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

if (!class_exists('invalid_parameter_exception')) {
    class invalid_parameter_exception extends \moodle_exception {
        public function __construct($debuginfo = null) {
            parent::__construct('invalidparameter', 'debug', '', null, $debuginfo);
        }
    }
}

if (!class_exists('required_capability_exception')) {
    class required_capability_exception extends \moodle_exception {
        public function __construct($context, $capability, $errormessage = 'nopermissions', $stringfile = '') {
            parent::__construct($errormessage, $stringfile, '', $capability);
        }
    }
}

// Context stubs — instance() returns a lightweight object carrying the id.
if (!class_exists('context_course')) {
    class context_course {
        public $instanceid;

        public static function instance($courseid) {
            $ctx = new self();
            $ctx->instanceid = (int)$courseid;
            return $ctx;
        }
    }
}

if (!class_exists('context_module')) {
    class context_module {
        public $instanceid;

        public static function instance($cmid) {
            $ctx = new self();
            $ctx->instanceid = (int)$cmid;
            return $ctx;
        }
    }
}

// cm_info stub — the course module object Moodle passes around after get_fast_modinfo().
if (!class_exists('cm_info')) {
    class cm_info {
        public $id;
        public $instance;
        public $course;
        public $customdata = [];

        public function __construct($id, $instance, $course) {
            $this->id = $id;
            $this->instance = $instance;
            $this->course = $course;
        }
    }
}

if (!class_exists('context_system')) {
    class context_system {
        public static function instance() {
            return new self();
        }
    }
}

// Capability stubs — every capability is granted unless listed in
// $GLOBALS['_test_denied_capabilities'], or unless $GLOBALS['_test_capability_course_ids']
// is set and the course context's instance id is not in it (a teacher of those courses only).
if (!function_exists('has_capability')) {
    function has_capability($capability, $context, $user = null) {
        if (in_array($capability, $GLOBALS['_test_denied_capabilities'] ?? [], true)) {
            return false;
        }
        if (isset($GLOBALS['_test_capability_course_ids']) && $context instanceof \context_course) {
            return in_array($context->instanceid, $GLOBALS['_test_capability_course_ids'], true);
        }
        return true;
    }
}

if (!function_exists('require_capability')) {
    function require_capability($capability, $context, $userid = null) {
        $GLOBALS['_test_call_order'][] = ['require_capability', $capability];
        if (!has_capability($capability, $context, $userid)) {
            throw new \required_capability_exception($context, $capability);
        }
    }
}

// Course custom field handler stub (namespaced, so it lives in its own file).
if (!class_exists('core_customfield\\handler')) {
    require_once __DIR__ . '/customfield_stub.php';
}

// Scheduled task base class loaded from separate file (namespaces can't be in if blocks).
if (!class_exists('core\task\scheduled_task')) {
    require_once __DIR__ . '/scheduled_task_stub.php';
}

// Event base classes loaded from a separate file (namespaces can't be in if blocks).
if (!class_exists('core\\event\\base')) {
    require_once __DIR__ . '/event_stub.php';
}

if (!function_exists('make_temp_directory')) {
    function make_temp_directory(string $directory, bool $exceptiononerror = true) {
        $dir = sys_get_temp_dir() . '/moodle_test_temp/' . $directory;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }
}

if (!function_exists('set_config')) {
    function set_config(string $name, $value, ?string $plugin = null): bool {
        $plugin = $plugin ?? 'core';
        if (!isset($GLOBALS['_test_plugin_config'][$plugin])) {
            $GLOBALS['_test_plugin_config'][$plugin] = new \stdClass();
        }
        $GLOBALS['_test_plugin_config'][$plugin]->$name = $value;
        return true;
    }
}


if (!defined('ANY_VERSION')) {
    define('ANY_VERSION', 'any');
}
if (!defined('DEBUG_DEVELOPER')) {
    define('DEBUG_DEVELOPER', 38911);
}

// mod_scorm constants (mod/scorm/lib.php and locallib.php).
foreach ([
    'SCORM_TYPE_LOCAL' => 'local',
    'SCORM_UPDATE_NEVER' => '0',
    'SCORM_TOC_DISABLED' => 3,
    'SCORM_NAV_DISABLED' => 0,
    'GRADESCOES' => '0',
    'GRADEHIGHEST' => '1',
    'HIGHESTATTEMPT' => '0',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

if (!class_exists('core_text')) {
    class core_text {
        public static function substr($text, $start, $len = null) {
            return mb_substr((string)$text, $start, $len, 'UTF-8');
        }

        public static function strlen($text) {
            return mb_strlen((string)$text, 'UTF-8');
        }
    }
}

if (!class_exists('context_user')) {
    class context_user {
        public $id;
        public $instanceid;

        public static function instance($userid) {
            $ctx = new self();
            $ctx->instanceid = (int)$userid;
            $ctx->id = 1000 + (int)$userid;
            return $ctx;
        }
    }
}

if (!function_exists('file_get_unused_draft_itemid')) {
    function file_get_unused_draft_itemid() {
        $GLOBALS['_test_draft_itemid'] = ($GLOBALS['_test_draft_itemid'] ?? 5000) + 1;
        return $GLOBALS['_test_draft_itemid'];
    }
}

// Minimal file storage: records every stored file in $GLOBALS['_test_stored_files'].
if (!class_exists('FakeFileStorage')) {
    class FakeStoredFile {
        public $record;

        public function __construct(array $record) {
            $this->record = $record;
        }

        public function get_filename() {
            return $this->record['filename'];
        }
    }

    class FakeFileStorage {
        public function create_file_from_pathname($filerecord, $pathname) {
            $record = (array)$filerecord;
            $record['pathname'] = $pathname;
            $record['exists'] = file_exists($pathname);
            $GLOBALS['_test_stored_files'][] = $record;
            return new FakeStoredFile($record);
        }

        public function delete_area_files($contextid, $component = false, $filearea = false, $itemid = false) {
            return true;
        }
    }
}

if (!function_exists('get_file_storage')) {
    function get_file_storage() {
        return new FakeFileStorage();
    }
}

// create_module() fake: inserts course_modules + scorm rows into the fake $DB, seeds
// scorm_scoes from $GLOBALS['_test_scorm_scoes'], records the moduleinfo in
// $GLOBALS['_test_create_module_calls'] and an event in $GLOBALS['_test_events'].
// $GLOBALS['_test_create_module_throw'] (an exception) makes it throw instead.
if (!function_exists('create_module')) {
    function create_module($moduleinfo) {
        $GLOBALS['_test_create_module_calls'][] = clone $moduleinfo;
        if (!empty($GLOBALS['_test_create_module_throw'])) {
            throw $GLOBALS['_test_create_module_throw'];
        }
        $db = $GLOBALS['DB'];
        $cmid = $db->insert_record('course_modules', (object)[
            'course' => $moduleinfo->course,
            'module' => 99,
            'instance' => 0,
            'section' => $moduleinfo->section,
            'idnumber' => $moduleinfo->idnumber ?? '',
            'visible' => $moduleinfo->visible,
            'visibleoncoursepage' => $GLOBALS['_test_create_module_visibleoncoursepage']
                ?? ($moduleinfo->visibleoncoursepage ?? 1),
        ]);
        // $GLOBALS['_test_create_module_throw_after_insert'] (an exception) makes it fail half way,
        // leaving the course_modules row behind like a real create_module() can.
        if (!empty($GLOBALS['_test_create_module_throw_after_insert'])) {
            throw $GLOBALS['_test_create_module_throw_after_insert'];
        }
        $scormid = $db->insert_record('scorm', (object)[
            'course' => $moduleinfo->course,
            'name' => $moduleinfo->name,
            'reference' => 'scorm_package.zip',
        ]);
        $db->set_field('course_modules', 'instance', $scormid, ['id' => $cmid]);
        foreach (($GLOBALS['_test_scorm_scoes'] ?? []) as $sco) {
            $row = (object)$sco;
            $row->scorm = $scormid;
            unset($row->id);
            $db->insert_record('scorm_scoes', $row);
        }
        $GLOBALS['_test_events'][] = ['name' => 'course_module_created', 'cmid' => $cmid];
        $moduleinfo->coursemodule = $cmid;
        $moduleinfo->instance = $scormid;
        return $moduleinfo;
    }
}

if (!function_exists('set_coursemodule_visible')) {
    function set_coursemodule_visible($id, $visible, $visibleoncoursepage = 1, $rebuildcache = true) {
        $GLOBALS['_test_set_visible_calls'][] = [(int)$id, (int)$visible, (int)$visibleoncoursepage];
        $db = $GLOBALS['DB'];
        $db->set_field('course_modules', 'visible', $visible, ['id' => $id]);
        $db->set_field('course_modules', 'visibleoncoursepage', $visibleoncoursepage, ['id' => $id]);
        return true;
    }
}

// Lock API (namespaced, so it lives in its own file).
if (!class_exists('core\\lock\\lock_config')) {
    require_once __DIR__ . '/lock_stub.php';
}

// \core\notification (namespaced, so it lives in its own file).
if (!class_exists('core\\notification')) {
    require_once __DIR__ . '/notification_stub.php';
}

// Thrown by require_login() and external_api::validate_context() when the user cannot enter the context.
if (!class_exists('require_login_exception')) {
    class require_login_exception extends \moodle_exception {
        public function __construct($debuginfo = null) {
            parent::__construct('requireloginerror', 'error', '', null, $debuginfo);
        }
    }
}

if (!class_exists('coding_exception')) {
    class coding_exception extends \moodle_exception {
        public function __construct($hint = '', $debuginfo = null) {
            parent::__construct('codingerror', 'debug', '', $hint, $debuginfo);
        }
    }
}

// Completion, grade and activity-purpose feature flags (lib/moodlelib.php, lib/gradelib.php).
foreach ([
    'FEATURE_COMPLETION_HAS_RULES' => 'completion_has_rules',
    'FEATURE_GRADE_HAS_GRADE' => 'grade_has_grade',
    'FEATURE_MOD_PURPOSE' => 'mod_purpose',
    'MOD_PURPOSE_CONTENT' => 'content',
    'GRADE_TYPE_NONE' => 0,
    'GRADE_TYPE_VALUE' => 1,
    'GRADE_TYPE_SCALE' => 2,
    'GRADE_TYPE_TEXT' => 3,
    'GRADE_UPDATE_OK' => 0,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

// grade_update() fake: records every call in $GLOBALS['_test_grade_updates'].
if (!function_exists('grade_update')) {
    function grade_update($source, $courseid, $itemtype, $itemmodule, $iteminstance, $itemnumber,
            $grades = null, $itemdetails = null) {
        $GLOBALS['_test_grade_updates'][] = [
            'source' => $source,
            'courseid' => $courseid,
            'itemtype' => $itemtype,
            'itemmodule' => $itemmodule,
            'iteminstance' => $iteminstance,
            'itemnumber' => $itemnumber,
            'grades' => $grades,
            'itemdetails' => $itemdetails,
        ];
        return GRADE_UPDATE_OK;
    }
}

// scorm_grade_item_update() fake: records the SCORM record in $GLOBALS['_test_scorm_grade_item_updates'].
if (!function_exists('scorm_grade_item_update')) {
    function scorm_grade_item_update($scorm, $grades = null) {
        $GLOBALS['_test_scorm_grade_item_updates'][] = clone $scorm;
        return GRADE_UPDATE_OK;
    }
}

// \core_completion\activity_custom_completion (namespaced, so it lives in its own file).
if (!class_exists('core_completion\\activity_custom_completion')) {
    require_once __DIR__ . '/custom_completion_stub.php';
}
