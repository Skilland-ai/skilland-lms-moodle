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
 * Lists all Skilland content activities in a course.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);

$course = get_course($id);

require_course_login($course);

$context = context_course::instance($course->id);

$event = \mod_skilland\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url(new moodle_url('/mod/skilland/index.php', ['id' => $id]));
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

$skillands = get_all_instances_in_course('skilland', $course);

echo $OUTPUT->header();

if (empty($skillands)) {
    $strplural = get_string('modulenameplural', 'mod_skilland');
    notice(get_string('thereareno', 'moodle', $strplural), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);

$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';

if ($usesections) {
    $strsectionname = get_string('sectionname', 'format_' . $course->format);
    $table->head = [$strsectionname, get_string('name')];
    $table->align = ['center', 'left'];
} else {
    $table->head = [get_string('name')];
    $table->align = ['left'];
}

$currentsection = '';

foreach ($skillands as $skilland) {
    $printsection = '';
    if ($usesections) {
        if ($skilland->section !== $currentsection) {
            if ($skilland->section) {
                $printsection = get_section_name($course, $skilland->section);
            }
            if ($currentsection !== '') {
                $table->data[] = 'hr';
            }
            $currentsection = $skilland->section;
        }
    }

    $link = html_writer::link(
        new moodle_url('/mod/skilland/view.php', ['id' => $skilland->coursemodule]),
        format_string($skilland->name),
        $skilland->visible ? [] : ['class' => 'dimmed']
    );

    if ($usesections) {
        $table->data[] = [$printsection, $link];
    } else {
        $table->data[] = [$link];
    }
}

echo $OUTPUT->heading(get_string('modulenameplural', 'mod_skilland'), 2);
echo html_writer::table($table);

echo $OUTPUT->footer();
