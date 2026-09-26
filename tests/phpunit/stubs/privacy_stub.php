<?php
// Minimal core_privacy API stubs (Moodle 4.2+) for standalone testing of classes/privacy/provider.php.
// Namespaced, so it cannot sit inside class_exists guards: load it once with require_once.
//
// - \context: id, contextlevel and instanceid; context::instance_by_id() reads the fake $DB's
//   'context' table.
// - metadata\collection records every add_* call; get_collection() returns them.
// - contextlist::add_from_sql() runs the SQL through $DB->get_records_sql() and keeps each row's id,
//   so a test answers it with FakeDatabase::set_records_sql_handler().
// - writer::with_context($context)->export_data() appends
//   ['contextid' => ..., 'subcontext' => ..., 'data' => ...] to $GLOBALS['_test_privacy_exports'].
// - transform::datetime() returns userdate().

namespace {
    if (!defined('CONTEXT_SYSTEM')) {
        define('CONTEXT_SYSTEM', 10);
    }
    if (!defined('CONTEXT_USER')) {
        define('CONTEXT_USER', 30);
    }
    if (!defined('CONTEXT_COURSECAT')) {
        define('CONTEXT_COURSECAT', 40);
    }
    if (!defined('CONTEXT_COURSE')) {
        define('CONTEXT_COURSE', 50);
    }
    if (!defined('CONTEXT_MODULE')) {
        define('CONTEXT_MODULE', 70);
    }

    if (!class_exists('context')) {
        class context {
            public $id;
            public $contextlevel;
            public $instanceid;

            public function __construct(int $id, int $contextlevel, int $instanceid) {
                $this->id = $id;
                $this->contextlevel = $contextlevel;
                $this->instanceid = $instanceid;
            }

            public static function instance_by_id($id, $strictness = MUST_EXIST) {
                $row = $GLOBALS['DB']->get_record('context', ['id' => $id]);
                if (!$row) {
                    if ($strictness === MUST_EXIST) {
                        throw new \dml_missing_record_exception('context');
                    }
                    return false;
                }
                return new self((int) $row->id, (int) $row->contextlevel, (int) $row->instanceid);
            }
        }
    }

    if (!function_exists('format_string')) {
        function format_string($string) {
            return $string;
        }
    }
}

namespace core_privacy\local\metadata {

    interface provider {
        public static function get_metadata(collection $collection): collection;
    }

    class collection {
        private $component;
        private $items = [];

        public function __construct(string $component) {
            $this->component = $component;
        }

        public function get_component(): string {
            return $this->component;
        }

        public function add_database_table($name, array $privacyfields, $summary = '') {
            $this->items[] = ['type' => 'database_table', 'name' => $name, 'fields' => $privacyfields,
                'summary' => $summary];
            return $this;
        }

        public function add_external_location_link($name, array $privacyfields, $summary = '') {
            $this->items[] = ['type' => 'external_location', 'name' => $name, 'fields' => $privacyfields,
                'summary' => $summary];
            return $this;
        }

        public function add_subsystem_link($name, array $privacyfields = [], $summary = '') {
            $this->items[] = ['type' => 'subsystem_link', 'name' => $name, 'fields' => $privacyfields,
                'summary' => $summary];
            return $this;
        }

        public function get_collection(): array {
            return $this->items;
        }
    }
}

namespace core_privacy\local\request {

    interface data_provider {
    }

    interface core_data_provider extends data_provider {
    }

    interface core_user_data_provider extends core_data_provider {
        public static function get_contexts_for_userid(int $userid): contextlist;

        public static function export_user_data(approved_contextlist $contextlist);

        public static function delete_data_for_all_users_in_context(\context $context);

        public static function delete_data_for_user(approved_contextlist $contextlist);
    }

    interface userlist_provider {
        public static function get_users_in_context(userlist $userlist);

        public static function delete_data_for_users(approved_userlist $userlist);
    }

    interface core_userlist_provider extends userlist_provider {
    }

    abstract class contextlist_base implements \IteratorAggregate, \Countable {
        protected $contextids = [];
        protected $component = '';

        public function get_contextids(): array {
            return array_values(array_unique($this->contextids));
        }

        public function get_contexts(): array {
            return array_map(fn($id) => \context::instance_by_id($id), $this->get_contextids());
        }

        public function get_component(): string {
            return $this->component;
        }

        public function getIterator(): \Iterator {
            return new \ArrayIterator($this->get_contexts());
        }

        public function count(): int {
            return count($this->get_contextids());
        }
    }

    class contextlist extends contextlist_base {
        public function add_from_sql(string $sql, array $params): contextlist {
            foreach ($GLOBALS['DB']->get_records_sql($sql, $params) as $row) {
                $this->contextids[] = (int) $row->id;
            }
            return $this;
        }

        public function set_component($component) {
            $this->component = $component;
        }
    }

    class approved_contextlist extends contextlist_base {
        protected $user;

        public function __construct(\stdClass $user, string $component, array $contextids) {
            $this->user = $user;
            $this->component = $component;
            $this->contextids = $contextids;
        }

        public function get_user(): \stdClass {
            return $this->user;
        }
    }

    abstract class userlist_base implements \IteratorAggregate, \Countable {
        protected $context;
        protected $component;
        protected $userids = [];

        public function __construct(\context $context, string $component) {
            $this->context = $context;
            $this->component = $component;
        }

        public function get_context(): \context {
            return $this->context;
        }

        public function get_component(): string {
            return $this->component;
        }

        public function get_userids(): array {
            return array_values(array_unique($this->userids));
        }

        public function getIterator(): \Iterator {
            return new \ArrayIterator($this->get_userids());
        }

        public function count(): int {
            return count($this->get_userids());
        }
    }

    class userlist extends userlist_base {
        public function add_user(int $userid): userlist {
            $this->userids[] = $userid;
            return $this;
        }

        public function add_users(array $userids): userlist {
            foreach ($userids as $userid) {
                $this->add_user((int) $userid);
            }
            return $this;
        }

        public function add_from_sql(string $fieldname, string $sql, array $params): userlist {
            foreach ($GLOBALS['DB']->get_records_sql($sql, $params) as $row) {
                $this->add_user((int) $row->$fieldname);
            }
            return $this;
        }
    }

    class approved_userlist extends userlist_base {
        public function __construct(\context $context, string $component, array $userids) {
            parent::__construct($context, $component);
            $this->userids = array_map('intval', $userids);
        }
    }

    class content_writer {
        private $context;

        public function __construct(\context $context) {
            $this->context = $context;
        }

        public function export_data(array $subcontext, \stdClass $data): content_writer {
            $GLOBALS['_test_privacy_exports'][] = [
                'contextid' => $this->context->id,
                'subcontext' => $subcontext,
                'data' => $data,
            ];
            return $this;
        }
    }

    class writer {
        public static function with_context(\context $context): content_writer {
            return new content_writer($context);
        }
    }

    class transform {
        public static function datetime($datetime) {
            return \userdate($datetime);
        }
    }
}

namespace core_privacy\local\request\plugin {

    interface provider extends \core_privacy\local\request\core_user_data_provider {
    }
}
