<?php

class FakeDatabaseManager {
    private $existing_tables = [];

    public function set_table_exists(string $table, bool $exists): void {
        $this->existing_tables[$table] = $exists;
    }

    public function table_exists(string $table): bool {
        return $this->existing_tables[$table] ?? false;
    }
}

class FakeDatabase {
    private $tables = [];
    private $calls = [];
    private $auto_increment = [];
    private $manager;
    private $throw_on_get_record = null;

    public function set_throw_on_get_record(?\Exception $exception): void {
        $this->throw_on_get_record = $exception;
    }

    public function __construct() {
        $this->manager = new FakeDatabaseManager();
    }

    public function seed(string $table, array $records): void {
        $this->tables[$table] = [];
        foreach ($records as $record) {
            $obj = is_object($record) ? $record : (object)$record;
            $key = $obj->id ?? count($this->tables[$table]) + 1;
            $this->tables[$table][$key] = $obj;
        }
    }

    public function reset(): void {
        $this->tables = [];
        $this->calls = [];
        $this->auto_increment = [];
    }

    public function get_calls(): array {
        return $this->calls;
    }

    public function get_calls_for(string $method): array {
        return array_values(array_filter($this->calls, fn($c) => $c['method'] === $method));
    }

    public function get_manager(): FakeDatabaseManager {
        return $this->manager;
    }

    public function get_record(string $table, array $conditions, string $fields = '*', int $strictness = 0) {
        $this->calls[] = ['method' => 'get_record', 'table' => $table, 'conditions' => $conditions];
        if ($this->throw_on_get_record !== null) {
            throw $this->throw_on_get_record;
        }
        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                return clone $record;
            }
        }
        if ($strictness === MUST_EXIST) {
            throw new \dml_missing_record_exception($table);
        }
        return false;
    }

    public function get_record_sql(string $sql, array $params = [], int $strictness = 0) {
        $this->calls[] = ['method' => 'get_record_sql', 'sql' => $sql, 'params' => $params];
        return false;
    }

    public function get_records(string $table, array $conditions = [], string $sort = '', string $fields = '*') {
        $this->calls[] = ['method' => 'get_records', 'table' => $table, 'conditions' => $conditions, 'fields' => $fields];
        $results = [];

        // Determine the key field — Moodle keys by the first field when $fields != '*'.
        $keyField = 'id';
        if ($fields !== '*') {
            $firstField = trim(explode(',', $fields)[0]);
            if (!empty($firstField)) {
                $keyField = $firstField;
            }
        }

        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                $key = (string)($record->$keyField ?? $record->id ?? spl_object_id($record));
                $results[$key] = clone $record;
            }
        }
        return $results;
    }

    public function get_records_select(string $table, string $select, ?array $params = null, string $sort = '', string $fields = '*') {
        $this->calls[] = ['method' => 'get_records_select', 'table' => $table, 'select' => $select, 'params' => $params];
        return $this->tables[$table] ?? [];
    }

    public function get_records_sql(string $sql, array $params = []) {
        $this->calls[] = ['method' => 'get_records_sql', 'sql' => $sql, 'params' => $params];
        return [];
    }

    public function insert_record(string $table, $dataobject, bool $returnid = true): int {
        $obj = is_object($dataobject) ? clone $dataobject : (object)$dataobject;
        if (!isset($this->auto_increment[$table])) {
            $existing_ids = array_map(fn($r) => $r->id ?? 0, $this->tables[$table] ?? []);
            $this->auto_increment[$table] = empty($existing_ids) ? 1 : max($existing_ids) + 1;
        }
        if (empty($obj->id)) {
            $obj->id = $this->auto_increment[$table]++;
        }
        $this->tables[$table][$obj->id] = $obj;
        $this->calls[] = ['method' => 'insert_record', 'table' => $table, 'data' => $obj];
        return $obj->id;
    }

    public function update_record(string $table, $dataobject): bool {
        $obj = is_object($dataobject) ? $dataobject : (object)$dataobject;
        if (isset($obj->id) && isset($this->tables[$table][$obj->id])) {
            // Like Moodle, only the fields present on the object are written.
            $merged = clone $this->tables[$table][$obj->id];
            foreach (get_object_vars($obj) as $field => $value) {
                $merged->$field = $value;
            }
            $this->tables[$table][$obj->id] = $merged;
        }
        $this->calls[] = ['method' => 'update_record', 'table' => $table, 'data' => clone $obj];
        return true;
    }

    public function delete_records(string $table, array $conditions = []): bool {
        $this->calls[] = ['method' => 'delete_records', 'table' => $table, 'conditions' => $conditions];
        if (!isset($this->tables[$table])) {
            return true;
        }
        foreach ($this->tables[$table] as $key => $record) {
            if ($this->matches($record, $conditions)) {
                unset($this->tables[$table][$key]);
            }
        }
        return true;
    }

    public function record_exists(string $table, array $conditions = []): bool {
        $this->calls[] = ['method' => 'record_exists', 'table' => $table, 'conditions' => $conditions];
        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                return true;
            }
        }
        return false;
    }

    public function set_field(string $table, string $field, $value, array $conditions = []): bool {
        $this->calls[] = ['method' => 'set_field', 'table' => $table, 'field' => $field, 'value' => $value, 'conditions' => $conditions];
        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                $record->$field = $value;
            }
        }
        return true;
    }

    public function get_field(string $table, string $field, array $conditions = []) {
        $this->calls[] = ['method' => 'get_field', 'table' => $table, 'field' => $field, 'conditions' => $conditions];
        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                return $record->$field ?? false;
            }
        }
        return false;
    }

    public function count_records(string $table, array $conditions = []): int {
        $this->calls[] = ['method' => 'count_records', 'table' => $table, 'conditions' => $conditions];
        $count = 0;
        foreach (($this->tables[$table] ?? []) as $record) {
            if ($this->matches($record, $conditions)) {
                $count++;
            }
        }
        return $count;
    }

    public function get_in_or_equal(array $items, int $type = SQL_PARAMS_NAMED, string $prefix = 'param'): array {
        if (empty($items)) {
            return ['= NULL', []];
        }
        $params = [];
        $placeholders = [];
        foreach ($items as $i => $item) {
            $key = $prefix . $i;
            $params[$key] = $item;
            $placeholders[] = ':' . $key;
        }
        return ['IN (' . implode(',', $placeholders) . ')', $params];
    }

    private function matches(object $record, array $conditions): bool {
        foreach ($conditions as $field => $value) {
            if (!isset($record->$field) || $record->$field != $value) {
                return false;
            }
        }
        return true;
    }

    private function get_record_key(object $record, string $table): string {
        return (string)($record->id ?? spl_object_id($record));
    }
}
