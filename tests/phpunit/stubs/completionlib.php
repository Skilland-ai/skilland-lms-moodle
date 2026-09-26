<?php
// Minimal completion_info stub for standalone testing.
// set_module_viewed() appends the course module to $GLOBALS['_test_completion_viewed']
// and the course it was constructed with to $GLOBALS['_test_completion_courses'].
// update_state() appends ['cm' => ..., 'state' => ..., 'userid' => ...] to
// $GLOBALS['_test_completion_updates']; is_enabled() returns $GLOBALS['_test_completion_enabled']
// (default COMPLETION_TRACKING_AUTOMATIC).

foreach ([
    'COMPLETION_TRACKING_NONE' => 0,
    'COMPLETION_TRACKING_MANUAL' => 1,
    'COMPLETION_TRACKING_AUTOMATIC' => 2,
    'COMPLETION_UNKNOWN' => -1,
    'COMPLETION_INCOMPLETE' => 0,
    'COMPLETION_COMPLETE' => 1,
    'COMPLETION_COMPLETE_PASS' => 2,
    'COMPLETION_COMPLETE_FAIL' => 3,
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

if (!class_exists('completion_info')) {
    class completion_info {
        /** @var stdClass */
        public $course;

        public function __construct($course) {
            $this->course = $course;
        }

        public function set_module_viewed($cm, $userid = 0) {
            $GLOBALS['_test_completion_viewed'][] = $cm;
            $GLOBALS['_test_completion_courses'][] = $this->course;
        }

        public function is_enabled($cm = null) {
            return $GLOBALS['_test_completion_enabled'] ?? COMPLETION_TRACKING_AUTOMATIC;
        }

        public function update_state($cm, $possibleresult = COMPLETION_UNKNOWN, $userid = 0, $override = false) {
            $GLOBALS['_test_completion_updates'][] = ['cm' => $cm, 'state' => $possibleresult, 'userid' => $userid];
        }
    }
}
