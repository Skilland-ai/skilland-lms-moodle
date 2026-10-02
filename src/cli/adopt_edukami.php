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
 * CLI script that turns mod_edukami activities into mod_skilland activities (SKL-997).
 *
 * Dry run by default; --apply writes. See \mod_skilland\local\edukami_adopter.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'apply' => false,
        'course' => null,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOT
Turn mod_edukami activities into mod_skilland activities.

Each Edukami activity whose topic the Edukami migration brought into Skilland gets a Skilland
activity right before it, in the same section, that keeps the Edukami topic SCORM, its attempts,
its snapshot hash and its lesson->SCO mapping. The Edukami activity is then hidden (never
deleted) and the SCORM is left untouched. A course with no Skilland skill is mapped to the
migrated skill; a course mapped to another skill is skipped, and so is an activity whose topic
Skilland does not list. Running it again changes nothing.

Without --apply it only reads (Moodle and the Skilland API) and prints what it would do.

Options:
  --apply          Write the changes. Without it this is a dry run.
  --course=ID      Only adopt the activities of this Moodle course id.
  -h, --help       Print this help.

Example:
  php mod/skilland/cli/adopt_edukami.php
  php mod/skilland/cli/adopt_edukami.php --apply
  php mod/skilland/cli/adopt_edukami.php --course=42 --apply

Exits 1 when an activity failed (each failure is rolled back on its own), else 0.

EOT;
    echo $help;
    exit(0);
}

$courseid = null;
if ($options['course'] !== null && $options['course'] !== '') {
    if (!ctype_digit((string) $options['course'])) {
        cli_error('--course must be a Moodle course id');
    }
    $courseid = (int) $options['course'];
}
$apply = !empty($options['apply']);

// Creating activities needs the capabilities of an administrator.
\core\session\manager::set_user(get_admin());

if (!$apply) {
    cli_writeln("=== DRY RUN - nothing is written; pass --apply to adopt ===\n");
}

try {
    $reports = (new \mod_skilland\local\edukami_adopter($apply, $courseid))->run();
} catch (\moodle_exception $e) {
    cli_error($e->getMessage());
}

if (!$reports) {
    cli_writeln('No mod_edukami activities found' . ($courseid !== null ? " in course {$courseid}" : '') . '.');
    exit(0);
}

foreach (\mod_skilland\local\edukami_adopter::format($reports, $apply) as $line) {
    cli_writeln($line);
}

$failed = 0;
foreach ($reports as $report) {
    $failed += $report['counts'][\mod_skilland\local\edukami_adopter::FAILED];
}
exit($failed > 0 ? 1 : 0);
