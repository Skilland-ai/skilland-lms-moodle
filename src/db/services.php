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
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    // Each function lives in classes/external/<name>.php and calls validate_context() on its course or
    // module context, then checks a plugin capability in that context, so teachers with course-level
    // permissions can use these APIs without system-level capabilities.
    'mod_skilland_fetch_courses_ajax' => [
        'classname' => 'mod_skilland\external\fetch_courses',
        'methodname' => 'execute',
        'description' => 'Fetch courses from Skilland',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_create_course_ajax' => [
        'classname' => 'mod_skilland\external\create_course',
        'methodname' => 'execute',
        'description' => 'Create a new course in Skilland and map it to this Moodle course',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_fetch_topics_ajax' => [
        'classname' => 'mod_skilland\external\fetch_topics',
        'methodname' => 'execute',
        'description' => 'Fetch topics from Skilland for a given course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_fetch_lessons_ajax' => [
        'classname' => 'mod_skilland\external\fetch_lessons',
        'methodname' => 'execute',
        'description' => 'Fetch lessons from Skilland for a given topic',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_provision_topic_scorm_ajax' => [
        'classname' => 'mod_skilland\external\provision_topic_scorm',
        'methodname' => 'execute',
        'description' => 'Provision a topic-level SCORM package containing all lessons as SCOs',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_update_topic_scorm_ajax' => [
        'classname' => 'mod_skilland\external\update_topic_scorm',
        'methodname' => 'execute',
        'description' => 'Update a topic-level SCORM package with the latest content from Skilland (deletes student progress)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_skilland_check_topic_snapshot' => [
        'classname' => 'mod_skilland\external\check_topic_snapshot',
        'methodname' => 'execute',
        'description' => 'Check if topic content has changed in Skilland (lightweight hash comparison)',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
