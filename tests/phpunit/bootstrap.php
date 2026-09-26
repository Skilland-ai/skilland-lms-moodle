<?php
// Stub Moodle globals and functions for standalone PHPUnit testing.
// This allows testing plugin classes without a full Moodle installation.

define('MOODLE_INTERNAL', true);
define('DEBUG_DEVELOPER', 38911);
define('DEBUG_NORMAL', 15);
define('DEBUG_MINIMAL', 1);

// Load stubs before anything else.
require_once __DIR__ . '/stubs/moodle_stubs.php';
require_once __DIR__ . '/stubs/fake_database.php';
require_once __DIR__ . '/stubs/ziparchive.php';
require_once __DIR__ . '/stubs/core_external.php';

// Global $DB — lightweight in-memory mock.
$GLOBALS['DB'] = new FakeDatabase();
$DB = $GLOBALS['DB'];

// Global $CFG with paths that source files reference.
$CFG = new stdClass();
$CFG->dirroot = realpath(__DIR__ . '/../../src');
$CFG->libdir  = __DIR__ . '/stubs';
$CFG->wwwroot = 'http://localhost';
$GLOBALS['CFG'] = $CFG;

// Track calls to debugging() for assertions.
$GLOBALS['_test_debug_messages'] = [];
$GLOBALS['_test_plugin_config'] = [];

if (!function_exists('debugging')) {
    function debugging(string $message, int $level = DEBUG_NORMAL): void {
        $GLOBALS['_test_debug_messages'][] = ['message' => $message, 'level' => $level];
    }
}

if (!function_exists('get_config')) {
    function get_config(string $plugin, ?string $name = null) {
        $config = $GLOBALS['_test_plugin_config'][$plugin] ?? new \stdClass();
        if ($name !== null) {
            return $config->$name ?? null;
        }
        return $config;
    }
}

if (!function_exists('get_string')) {
    function get_string(string $identifier, string $component = ''): string {
        return $identifier;
    }
}

// Autoload plugin classes from src/classes/.
spl_autoload_register(function ($class) {
    $prefix = 'mod_skilland\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = __DIR__ . '/../../src/classes/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load source files that define plain functions (not classes).
// These are guarded by defined('MOODLE_INTERNAL') || die() which we satisfy above.
require_once __DIR__ . '/../../src/locallib.php';
require_once __DIR__ . '/../../src/lib.php';

// Load view.php function definitions (extracted to avoid script-level code).
require_once __DIR__ . '/stubs/view_functions.php';
