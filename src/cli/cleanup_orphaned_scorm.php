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
 * CLI script to clean up orphaned SCORM references in Skilland tables.
 *
 * This script finds and removes references to SCORM course modules that no longer exist.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// CLI options.
list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'course' => null,
    ],
    [
        'h' => 'help',
        'd' => 'dry-run',
        'c' => 'course',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOT
Clean up orphaned SCORM references in Skilland tables.

This script finds skilland and skilland_lesson records that reference SCORM course modules
that no longer exist, and clears those references so content can be re-provisioned.

Options:
 -h, --help        Print this help
 -d, --dry-run     Show what would be cleaned without making changes
 -c, --course=ID   Only clean up for a specific Moodle course ID

Example:
 php cleanup_orphaned_scorm.php --dry-run
 php cleanup_orphaned_scorm.php
 php cleanup_orphaned_scorm.php --course=2

EOT;
    echo $help;
    exit(0);
}

$dryrun = $options['dry-run'];
$courseid = $options['course'];

if ($dryrun) {
    cli_writeln("=== DRY RUN MODE - No changes will be made ===\n");
}

// Find orphaned skilland records (scormcmid points to non-existent course_modules).
cli_writeln("Checking for orphaned SCORM references in skilland table...");

$sql = "SELECT e.id, e.name, e.course, e.scormcmid
        FROM {skilland} e
        WHERE e.scormcmid IS NOT NULL
          AND e.scormcmid > 0
          AND NOT EXISTS (
              SELECT 1 FROM {course_modules} cm WHERE cm.id = e.scormcmid
          )";
$params = [];

if ($courseid) {
    $sql .= " AND e.course = :courseid";
    $params['courseid'] = $courseid;
}

$orphanedactivities = $DB->get_records_sql($sql, $params);

if (empty($orphanedactivities)) {
    cli_writeln("  No orphaned SCORM references found in skilland table.");
} else {
    cli_writeln("  Found " . count($orphanedactivities) . " skilland record(s) with orphaned SCORM references:");

    foreach ($orphanedactivities as $activity) {
        cli_writeln("    - ID: {$activity->id}, Name: '{$activity->name}', Course: {$activity->course}, " .
            "Orphaned scormcmid: {$activity->scormcmid}");

        if (!$dryrun) {
            $DB->set_field('skilland', 'scormcmid', null, ['id' => $activity->id]);
            $DB->set_field('skilland', 'scorm_provisioned', null, ['id' => $activity->id]);
            cli_writeln("      -> Cleared scormcmid and scorm_provisioned");
        }
    }
}

// Find orphaned skilland_lesson records (scormcmid points to non-existent course_modules).
cli_writeln("\nChecking for orphaned SCORM references in skilland_lesson table...");

$sql = "SELECT el.id, el.title, el.skillandid, el.scormcmid, el.scoid, el.sco_identifier, e.course
        FROM {skilland_lesson} el
        JOIN {skilland} e ON e.id = el.skillandid
        WHERE (el.scormcmid IS NOT NULL AND el.scormcmid > 0)
           OR el.scoid IS NOT NULL
           OR el.sco_identifier IS NOT NULL";
$params = [];

if ($courseid) {
    $sql .= " AND e.course = :courseid";
    $params['courseid'] = $courseid;
}

$lessons = $DB->get_records_sql($sql, $params);

// Check each lesson to see if its parent skilland's scormcmid is orphaned.
$orphanedlessons = [];
foreach ($lessons as $lesson) {
    // Get the parent skilland record.
    $skilland = $DB->get_record('skilland', ['id' => $lesson->skillandid]);

    if ($skilland && $skilland->scormcmid) {
        // Check if the parent's scormcmid exists.
        $cmexists = $DB->record_exists('course_modules', ['id' => $skilland->scormcmid]);
        if (!$cmexists) {
            $orphanedlessons[] = $lesson;
        }
    } else if ($lesson->scormcmid) {
        // Legacy per-lesson SCORM - check if it exists.
        $cmexists = $DB->record_exists('course_modules', ['id' => $lesson->scormcmid]);
        if (!$cmexists) {
            $orphanedlessons[] = $lesson;
        }
    } else if ($lesson->scoid || $lesson->sco_identifier) {
        // Has SCO data but parent skilland has no scormcmid - orphaned.
        if (!$skilland || !$skilland->scormcmid) {
            $orphanedlessons[] = $lesson;
        }
    }
}

if (empty($orphanedlessons)) {
    cli_writeln("  No orphaned SCORM references found in skilland_lesson table.");
} else {
    cli_writeln("  Found " . count($orphanedlessons) . " skilland_lesson record(s) with orphaned SCORM references:");

    foreach ($orphanedlessons as $lesson) {
        $details = [];
        if ($lesson->scormcmid) {
            $details[] = "scormcmid={$lesson->scormcmid}";
        }
        if ($lesson->scoid) {
            $details[] = "scoid={$lesson->scoid}";
        }
        if ($lesson->sco_identifier) {
            $details[] = "sco_identifier={$lesson->sco_identifier}";
        }
        cli_writeln("    - ID: {$lesson->id}, Title: '{$lesson->title}', Orphaned: " . implode(', ', $details));

        if (!$dryrun) {
            $DB->set_field('skilland_lesson', 'scormcmid', null, ['id' => $lesson->id]);
            $DB->set_field('skilland_lesson', 'scoid', null, ['id' => $lesson->id]);
            $DB->set_field('skilland_lesson', 'sco_identifier', null, ['id' => $lesson->id]);
            cli_writeln("      -> Cleared scormcmid, scoid, and sco_identifier");
        }
    }
}

cli_writeln("");

if ($dryrun) {
    cli_writeln("=== DRY RUN COMPLETE - Run without --dry-run to apply changes ===");
} else {
    cli_writeln("=== CLEANUP COMPLETE ===");
    cli_writeln("You can now re-provision SCORM packages for the affected Skilland activities.");
}

exit(0);

