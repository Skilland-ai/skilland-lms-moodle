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

    /** @var callable|null fn(string $sql, array $params): array, answering get_records_sql() */
    private $records_sql_handler = null;

    public function set_records_sql_handler(?callable $handler): void {
        $this->records_sql_handler = $handler;
    }

    public function get_records_sql(string $sql, array $params = []) {
        $this->calls[] = ['method' => 'get_records_sql', 'sql' => $sql, 'params' => $params];
        if ($this->records_sql_handler !== null) {
            return ($this->records_sql_handler)($sql, $params);
        }
        return [];
    }

    /**
     * Answers the "SELECT DISTINCT userid FROM {table} WHERE scormid = :scormid" read of
     * skilland_learner_attempt_userids() from the seeded rows (the fake does not parse other SQL).
     */
    public function get_fieldset_sql(string $sql, array $params = []): array {
        $this->calls[] = ['method' => 'get_fieldset_sql', 'sql' => $sql, 'params' => $params];
        if (!preg_match('/SELECT DISTINCT (\w+) FROM \{(\w+)\}/', $sql, $m)) {
            return [];
        }
        [, $field, $table] = $m;
        $values = [];
        foreach (($this->tables[$table] ?? []) as $record) {
            if (isset($params['scormid']) && (int) ($record->scormid ?? 0) !== (int) $params['scormid']) {
                continue;
            }
            if (isset($record->$field)) {
                $values[(int) $record->$field] = (int) $record->$field;
            }
        }
        ksort($values);
        return array_values($values);
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

    /**
     * Supports a select that is an AND of `field = :param`, `field IN (:a,:b,...)` and
     * `field = NULL` (the empty get_in_or_equal()), which is all the plugin's callers build.
     */
    public function delete_records_select(string $table, string $select, array $params = []): bool {
        $this->calls[] = ['method' => 'delete_records_select', 'table' => $table, 'select' => $select, 'params' => $params];
        $predicates = [];
        foreach (preg_split('/\s+AND\s+/i', trim($select)) as $clause) {
            if (preg_match('/^(\w+)\s*=\s*:(\w+)$/', $clause, $m)) {
                $predicates[] = fn($r) => isset($r->{$m[1]}) && $r->{$m[1]} == $params[$m[2]];
            } else if (preg_match('/^(\w+)\s+IN\s*\(([^)]*)\)$/i', $clause, $m)) {
                $values = array_map(fn($p) => $params[ltrim(trim($p), ':')], explode(',', $m[2]));
                $predicates[] = fn($r) => isset($r->{$m[1]}) && in_array($r->{$m[1]}, $values);
            } else if (preg_match('/^(\w+)\s*=\s*NULL$/i', $clause, $m)) {
                $predicates[] = fn($r) => false;
            } else {
                throw new \coding_exception('FakeDatabase::delete_records_select cannot parse: ' . $clause);
            }
        }
        foreach (($this->tables[$table] ?? []) as $key => $record) {
            $all = true;
            foreach ($predicates as $predicate) {
                if (!$predicate($record)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                unset($this->tables[$table][$key]);
            }
        }
        return true;
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
