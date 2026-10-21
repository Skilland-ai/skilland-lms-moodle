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

namespace mod_skilland\output;

use context_module;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');
require_once(__DIR__ . '/../../locallib.php');

/**
 * The Moodle App view of a Skilland activity (db/mobile.php, CoreCourseModuleDelegate).
 *
 * The same lesson list as view.php, with the lessons linking to the app's native SCORM player.
 * Like view.php it refreshes the viewer's progress from their SCORM tracks (and with it their
 * completion and grade) on every call, but it never logs the view: the viewed event and view
 * completion come from the mod_skilland_view_skilland web service, which the returned
 * JavaScript calls through the app's CoreCourseLogHelper (queued when offline), like core
 * modules do.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {
    /** @var string The web service the app calls to log the view. */
    const VIEW_SERVICE = 'mod_skilland_view_skilland';

    /**
     * The activity page of the app.
     *
     * @param array $args The app's arguments: cmid, courseid, userid, appid, appversionname,
     *        appversioncode, applang, appcustomurlscheme, appisdesktop, appismobile, appiswide.
     * @return array templates, javascript, otherdata and files, as the app expects.
     */
    public static function mobile_course_view(array $args): array {
        global $DB, $OUTPUT, $USER;

        $cm = get_coursemodule_from_id('skilland', (int) ($args['cmid'] ?? 0), 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $skilland = $DB->get_record('skilland', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        // No redirect from a web service: a user outside the course gets a require_login_exception.
        require_login($course, false, $cm, true, true);
        require_capability('mod/skilland:view', $context);

        // A deleted linked SCORM makes the activity unprovisioned for this request (never written here).
        skilland_detect_missing_scorm($skilland);

        $data = self::view_data($skilland, $cm, (int) $USER->id);

        return [
            'templates' => [
                [
                    'id' => 'main',
                    'html' => $OUTPUT->render_from_template('mod_skilland/mobile_view', $data),
                ],
            ],
            'javascript' => self::view_javascript(),
            'otherdata' => ['skillandid' => (int) $skilland->id],
            'files' => [],
        ];
    }

    /**
     * The context of mod_skilland/mobile_view.
     *
     * @param stdClass $skilland The skilland activity record, after skilland_detect_missing_scorm().
     * @param stdClass $cm The course module record.
     * @param int $userid The viewer.
     * @return array The template context.
     */
    protected static function view_data(stdClass $skilland, stdClass $cm, int $userid): array {
        global $DB, $OUTPUT;

        $data = [
            'cmid' => (int) $cm->id,
            'notprovisioned' => empty($skilland->scormcmid),
            // The app compiles the template as Angular code: no interpolation from the intro.
            'intro' => !empty($skilland->intro)
                ? skilland_app_template_text(format_module_intro('skilland', $skilland, $cm->id))
                : '',
            'lastupdated' => '',
            'haslessons' => false,
            'hassummary' => false,
            'lessons' => [],
        ];
        if ($data['notprovisioned']) {
            return $data;
        }

        // Like view.php: when this content was last (re)built, which resets every attempt.
        if (!empty($skilland->snapshotcreatedat)) {
            $data['lastupdated'] = get_string(
                'content_last_updated',
                'mod_skilland',
                userdate($skilland->snapshotcreatedat, get_string('strftimedatetimeshort'))
            );
        }

        $lessons = $DB->get_records('skilland_lesson', [
            'skillandid' => $skilland->id,
            'visible' => 1,
        ], 'orderindex ASC');
        $topicorderindex = (int) ($skilland->topic_orderindex ?? 1);
        $progress = skilland_viewer_progress($skilland, $lessons, $userid);

        $list = new lesson_list($skilland, $lessons, $cm, $topicorderindex, $progress, false, true);
        return array_merge($data, $list->export_for_template($OUTPUT));
    }

    /**
     * The JavaScript the app runs once the template is shown: log the view through the
     * mod_skilland_view_skilland web service (queued when offline).
     *
     * @return string
     */
    protected static function view_javascript(): string {
        return "this.CoreCourseLogHelperProvider.log(\n" .
            "    '" . self::VIEW_SERVICE . "',\n" .
            "    {skillandid: this.CONTENT_OTHERDATA.skillandid},\n" .
            "    'mod_skilland',\n" .
            "    this.module.id\n" .
            ").catch(function() {\n" .
            "    // A failed log must not break the view; the app retries queued logs on sync.\n" .
            "});\n";
    }
}
