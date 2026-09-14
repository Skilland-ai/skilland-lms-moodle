<?php

namespace core\task;

abstract class scheduled_task {
    abstract public function get_name();
    abstract public function execute();
}
