<?php
// Minimal \core_completion\activity_custom_completion base for standalone testing
// (namespaced, so it lives in its own file).

namespace core_completion;

abstract class activity_custom_completion {
    /** @var \cm_info */
    protected $cm;

    /** @var int */
    protected $userid;

    /** @var array|null */
    protected $completionstate;

    public function __construct(\cm_info $cm, int $userid, ?array $completionstate = null) {
        $this->cm = $cm;
        $this->userid = $userid;
        $this->completionstate = $completionstate;
    }

    protected function validate_rule(string $rule): void {
        if (!in_array($rule, static::get_defined_custom_rules(), true)) {
            throw new \coding_exception("Undefined custom completion rule '$rule'");
        }
    }

    public function is_defined(string $rule): bool {
        return in_array($rule, static::get_defined_custom_rules(), true);
    }

    public function get_available_custom_rules(): array {
        $rules = $this->cm->customdata['customcompletionrules'] ?? [];
        return array_values(array_filter(static::get_defined_custom_rules(), fn($rule) => !empty($rules[$rule])));
    }

    abstract public function get_state(string $rule): int;

    abstract public static function get_defined_custom_rules(): array;

    abstract public function get_custom_rule_descriptions(): array;

    abstract public function get_sort_order(): array;
}
