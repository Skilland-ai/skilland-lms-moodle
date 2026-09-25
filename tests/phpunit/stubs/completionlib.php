<?php
// Minimal completion_info stub for standalone testing.
// set_module_viewed() appends the course module to $GLOBALS['_test_completion_viewed']
// and the course it was constructed with to $GLOBALS['_test_completion_courses'].

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
    }
}
