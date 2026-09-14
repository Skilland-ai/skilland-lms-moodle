<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web service definitions for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Note: Capability checks are performed internally within each method using course context,
    // allowing teachers with course-level permissions to use these APIs without needing system-level capabilities.
    'mod_skilland_fetch_courses_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'fetch_courses_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Fetch courses from Skilland via GraphQL',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_create_course_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'create_course_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Create a new course in Skilland and map it to this Moodle course',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_fetch_topics_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'fetch_topics_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Fetch topics from Skilland via GraphQL for a given course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_fetch_lessons_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'fetch_lessons_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Fetch lessons from Skilland via GraphQL for a given topic',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_provision_lesson_scorm_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'provision_lesson_scorm_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'DEPRECATED: Provision a SCORM activity for a single lesson',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_provision_topic_scorm_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'provision_topic_scorm_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Provision a topic-level SCORM package containing all lessons as SCOs',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_update_topic_scorm_ajax' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'update_topic_scorm_ajax',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Update a topic-level SCORM package with the latest content from Skilland (deletes student progress)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_check_topic_snapshot' => [
        'classname' => 'mod_skilland_external',
        'methodname' => 'check_topic_snapshot',
        'classpath' => 'mod/skilland/classes/external.php',
        'description' => 'Check if topic content has changed in Skilland (lightweight hash comparison)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
