<?php
// Lock API stub (namespaced, so it lives in its own file).
// $GLOBALS['_test_lock_available'] = false makes get_lock() fail; every acquire and release
// is recorded in $GLOBALS['_test_lock_calls'].

namespace core\lock;

class lock {
    private $key;
    private $released = false;

    public function __construct(string $key) {
        $this->key = $key;
    }

    public function release(): bool {
        if (!$this->released) {
            $this->released = true;
            $GLOBALS['_test_lock_calls'][] = ['action' => 'release', 'key' => $this->key];
        }
        return true;
    }
}

class lock_factory {
    private $type;

    public function __construct(string $type) {
        $this->type = $type;
    }

    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        $key = $this->type . '/' . $resource;
        $available = $GLOBALS['_test_lock_available'] ?? true;
        $GLOBALS['_test_lock_calls'][] = ['action' => 'acquire', 'key' => $key, 'timeout' => $timeout,
            'acquired' => (bool) $available];
        return $available ? new lock($key) : false;
    }
}

class lock_config {
    public static function get_lock_factory(string $type): lock_factory {
        return new lock_factory($type);
    }
}
