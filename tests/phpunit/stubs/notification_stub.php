<?php
// \core\notification stub (namespaced, so it lives in its own file).
// Every queued notification is recorded in $GLOBALS['_test_notifications'] as ['type' => ..., 'message' => ...].

namespace core;

class notification {
    const SUCCESS = 'success';
    const WARNING = 'warning';
    const INFO = 'info';
    const ERROR = 'error';

    public static function add($message, $level = null) {
        $GLOBALS['_test_notifications'][] = ['type' => $level ?? self::INFO, 'message' => $message];
    }

    public static function success($message) {
        self::add($message, self::SUCCESS);
    }

    public static function warning($message) {
        self::add($message, self::WARNING);
    }

    public static function info($message) {
        self::add($message, self::INFO);
    }

    public static function error($message) {
        self::add($message, self::ERROR);
    }
}
