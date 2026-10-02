<?php

namespace core\task;

abstract class scheduled_task {
    abstract public function get_name();
    abstract public function execute();
}

abstract class adhoc_task {
    public function get_name() {
        return static::class;
    }
    abstract public function execute();
}

class manager {
    /** @var adhoc_task[] Every queued ad-hoc task, in order. */
    public static array $queued = [];

    public static function queue_adhoc_task(adhoc_task $task, bool $checkforexisting = false): bool {
        self::$queued[] = $task;
        return true;
    }
}
