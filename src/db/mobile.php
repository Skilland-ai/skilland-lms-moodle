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
 * Moodle App handlers for mod_skilland.
 *
 * The activity opens natively in the app: the course module handler calls
 * mod_skilland\output\mobile::mobile_course_view(), which renders templates/mobile_view.mustache.
 * No offline functions are declared: the lesson list is live progress and the topic SCORM is a
 * stealth module the app cannot download from the course page.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'mod_skilland' => [
        'handlers' => [
            'skillandview' => [
                'displaydata' => [
                    'icon' => $CFG->wwwroot . '/mod/skilland/pix/icon.png',
                    'class' => '',
                ],
                'delegate' => 'CoreCourseModuleDelegate',
                'method' => 'mobile_course_view',
                'displayrefresh' => true,
            ],
        ],
        'lang' => [],
    ],
];
