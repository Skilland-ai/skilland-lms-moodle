<?php
// Minimal stand-ins for the upgrade API db/upgrade.php uses, for standalone tests.

if (!class_exists('xmldb_table')) {
    class xmldb_table {
        private $name;

        public function __construct($name) {
            $this->name = $name;
        }

        public function getName() {
            return $this->name;
        }

        public function __toString(): string {
            return $this->name;
        }
    }
}

if (!function_exists('upgrade_mod_savepoint')) {
    function upgrade_mod_savepoint($result, $version, $modname, $allowabort = true) {
        $GLOBALS['_test_upgrade_savepoints'][] = [$version, $modname];
    }
}
