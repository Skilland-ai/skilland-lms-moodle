<?php
// Minimal Moodle 2 backup/restore API: just enough of backup/util and the activity task and
// structure step base classes to run the plugin's backup and restore classes against FakeDatabase.
// Not loaded by bootstrap.php; require it from the test that exercises backup/restore.
//
// Mappings (backup_ids_temp) live in restore_dbops::$mappings, keyed by restore id, item name and
// old id, and are shared by every task and step of a restore, as in Moodle.

if (!class_exists('backup')) {
    class backup {
        const VAR_ACTIVITYID = -2;
        const VAR_PARENTID = -3;
    }
}

if (!class_exists('backup_nested_element')) {
    class backup_nested_element {
        public $name;
        public $attributes;
        public $finalelements;
        public $children = [];
        public $sourcesql = null;
        public $sourcetable = null;
        public $annotations = [];
        public $fileannotations = [];

        public function __construct($name, $attributes = null, $finalelements = null) {
            $this->name = $name;
            $this->attributes = $attributes ?? [];
            $this->finalelements = $finalelements ?? [];
        }

        public function add_child($element) {
            $this->children[$element->name] = $element;
        }

        public function set_source_sql($sql, $params) {
            $this->sourcesql = [$sql, $params];
        }

        public function set_source_table($table, $params, $sortfield = '') {
            $this->sourcetable = [$table, $params];
        }

        public function annotate_ids($itemname, $elementname) {
            $this->annotations[$elementname] = $itemname;
        }

        public function annotate_files($component, $filearea, $elementname, $filesctxid = null) {
            $this->fileannotations[] = [$component, $filearea, $elementname];
        }
    }
}

if (!class_exists('backup_activity_task')) {
    abstract class backup_activity_task {
        protected $steps = [];

        public function add_step($step) {
            $this->steps[] = $step;
        }

        public function get_steps(): array {
            return $this->steps;
        }
    }
}

if (!class_exists('backup_activity_structure_step')) {
    abstract class backup_activity_structure_step {
        protected $name;
        protected $filename;
        protected $settings = [];

        public function __construct($name, $filename = null, $settings = []) {
            $this->name = $name;
            $this->filename = $filename;
            $this->settings = $settings;
        }

        protected function get_setting_value($name) {
            return $this->settings[$name] ?? false;
        }

        protected function prepare_activity_structure($activitystructure) {
            return $activitystructure;
        }

        /** Test entry point: the element tree define_structure() builds. */
        public function build_structure() {
            return $this->define_structure();
        }

        abstract protected function define_structure();
    }
}

if (!class_exists('restore_dbops')) {
    class restore_dbops {
        /** @var array restoreid => itemname => oldid => newid */
        public static $mappings = [];

        public static function set_backup_ids_record($restoreid, $itemname, $itemid, $newitemid = 0) {
            self::$mappings[$restoreid][$itemname][(string) $itemid] = (int) $newitemid;
        }

        public static function get_backup_ids_record($restoreid, $itemname, $itemid) {
            if (!isset(self::$mappings[$restoreid][$itemname][(string) $itemid])) {
                return false;
            }
            return (object) [
                'itemname' => $itemname,
                'itemid' => $itemid,
                'newitemid' => self::$mappings[$restoreid][$itemname][(string) $itemid],
            ];
        }
    }
}

if (!class_exists('restore_path_element')) {
    class restore_path_element {
        public $name;
        public $path;

        public function __construct($name, $path) {
            $this->name = $name;
            $this->path = $path;
        }
    }
}

if (!class_exists('restore_decode_content')) {
    class restore_decode_content {
        public function __construct($tablename, $fields, $mapping = null) {
        }
    }
}

if (!class_exists('restore_decode_rule')) {
    class restore_decode_rule {
        /** @var string */
        public $linkname;
        /** @var string */
        public $urltemplate;
        /** @var mixed */
        public $mappings;

        public function __construct($linkname, $urltemplate, $mappings) {
            $this->linkname = $linkname;
            $this->urltemplate = $urltemplate;
            $this->mappings = $mappings;
        }
    }
}

if (!class_exists('restore_log_rule')) {
    class restore_log_rule {
        public function __construct($module, $action, $urlread, $inforead) {
        }
    }
}

if (!class_exists('restore_activity_task')) {
    abstract class restore_activity_task {
        protected $restoreid;
        protected $courseid;
        protected $oldactivityid;
        protected $activityid;
        protected $settings;
        protected $steps = [];

        /**
         * @param string $restoreid Restore id shared by every task of one restore.
         * @param int $courseid Target course.
         * @param int $oldactivityid The activity instance id in the backup.
         * @param array $settings Restore settings (userinfo).
         */
        public function __construct($restoreid, $courseid, $oldactivityid, array $settings = []) {
            $this->restoreid = $restoreid;
            $this->courseid = $courseid;
            $this->oldactivityid = $oldactivityid;
            $this->settings = $settings;
        }

        public function build() {
            $this->define_my_settings();
            $this->define_my_steps();
        }

        public function add_step($step) {
            $step->set_task($this);
            $this->steps[] = $step;
        }

        public function get_steps(): array {
            return $this->steps;
        }

        public function get_restoreid() {
            return $this->restoreid;
        }

        public function get_courseid() {
            return $this->courseid;
        }

        public function get_old_activityid() {
            return $this->oldactivityid;
        }

        public function get_activityid() {
            return $this->activityid;
        }

        public function set_activityid($activityid) {
            $this->activityid = $activityid;
        }

        public function get_setting_value($name) {
            return $this->settings[$name] ?? false;
        }

        public function after_restore() {
        }

        abstract protected function define_my_settings();

        abstract protected function define_my_steps();
    }
}

if (!class_exists('restore_activity_structure_step')) {
    abstract class restore_activity_structure_step {
        /** @var restore_activity_task */
        protected $task;
        protected $name;
        protected $filename;
        /** @var array itemname => oldid => newid, as in the real parent id stack */
        protected $parentids = [];

        public function __construct($name, $filename = null) {
            $this->name = $name;
            $this->filename = $filename;
        }

        public function set_task($task) {
            $this->task = $task;
        }

        protected function get_setting_value($name) {
            return $this->task->get_setting_value($name);
        }

        protected function get_courseid() {
            return $this->task->get_courseid();
        }

        protected function apply_date_offset($value) {
            return $value ? $value + ($GLOBALS['_test_restore_date_offset'] ?? 0) : $value;
        }

        protected function set_mapping($itemname, $oldid, $newid, $restorefiles = false) {
            restore_dbops::set_backup_ids_record($this->task->get_restoreid(), $itemname, $oldid, $newid);
            $this->parentids[$itemname] = $newid;
        }

        protected function get_mappingid($itemname, $oldid, $ifnotfound = false) {
            $record = restore_dbops::get_backup_ids_record($this->task->get_restoreid(), $itemname, $oldid);
            return $record ? $record->newitemid : $ifnotfound;
        }

        protected function get_new_parentid($itemname) {
            return $this->parentids[$itemname] ?? 0;
        }

        protected function apply_activity_instance($newitemid) {
            $this->set_mapping('skilland', $this->task->get_old_activityid(), $newitemid, true);
            $this->task->set_activityid($newitemid);
        }

        protected function prepare_activity_structure($paths) {
            return $paths;
        }

        protected function add_related_files($component, $filearea, $mappingitemname) {
        }

        /** Test entry point: the restore_path_element list define_structure() declares. */
        public function build_structure() {
            return $this->define_structure();
        }

        /** Test entry point: dispatch one parsed XML element to its process_<name>() method. */
        public function process_element(string $name, array $data) {
            $method = 'process_' . $name;
            $this->$method($data);
        }

        /** Test entry point: run after_execute() once every element is processed. */
        public function finish() {
            $this->after_execute();
        }

        protected function after_execute() {
        }

        abstract protected function define_structure();
    }
}
