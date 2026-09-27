<?php
// \core\di and \core\hook\di_configuration stubs (Moodle 4.4+ dependency injection).
//
// Real Moodle builds a PHP-DI container and dispatches \core\hook\di_configuration so plugins can
// bind their services; advanced_testcase resets it between tests. This stub keeps the same public
// API (get / set / reset_container) and resolves an id in this order:
//   1. an instance a test put there with \core\di::set();
//   2. a suite-wide default registered by bootstrap.php (set_suite_default(), stub-only), which
//      survives reset_container() — the retry sleeper records instead of sleeping;
//   3. the plugin's own binding from mod_skilland\hooks::di_configuration();
//   4. new $id() (PHP-DI autowiring of a class without constructor arguments).
// Resolved entries are cached like PHP-DI singletons until reset_container().

namespace core\hook;

class di_configuration {
    /** @var array<string, callable> */
    private $definitions = [];

    public function add_definition(string $id, callable $definition): self {
        $this->definitions[$id] = $definition;
        return $this;
    }

    /** Stub-only: the definitions the callbacks added. */
    public function get_definitions(): array {
        return $this->definitions;
    }
}

namespace core;

class di {
    /** @var array<string, mixed> */
    private static $entries = [];

    /** @var array<string, callable> */
    private static $suitedefaults = [];

    public static function get(string $id): mixed {
        if (array_key_exists($id, self::$entries)) {
            return self::$entries[$id];
        }
        if (isset(self::$suitedefaults[$id])) {
            return self::$entries[$id] = (self::$suitedefaults[$id])();
        }
        $hook = new \core\hook\di_configuration();
        \mod_skilland\hooks::di_configuration($hook);
        $definitions = $hook->get_definitions();
        if (isset($definitions[$id])) {
            return self::$entries[$id] = ($definitions[$id])();
        }
        return self::$entries[$id] = new $id();
    }

    public static function set(string $id, mixed $value): void {
        self::$entries[$id] = $value;
    }

    public static function reset_container(): void {
        self::$entries = [];
    }

    /** Stub-only: a default every test gets unless it sets its own; kept across reset_container(). */
    public static function set_suite_default(string $id, callable $factory): void {
        self::$suitedefaults[$id] = $factory;
    }
}
