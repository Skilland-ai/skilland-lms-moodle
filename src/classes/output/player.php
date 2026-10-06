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

use mod_skilland\local\progress_summary;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * The fullscreen SCORM player of one lesson, or the notice that it cannot be played.
 *
 * Rendered by mod_skilland/player.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class player implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $skilland The skilland activity record.
     * @param stdClass $lesson The lesson to play.
     * @param stdClass $cm The course module record.
     * @param array $alllessons The visible lessons of the activity, in order, for the navigation.
     * @param int $topicorderindex The topic order index (T1, T2, etc.).
     * @param moodle_url|null $playerurl The mod_scorm player of the lesson's SCO; null when it cannot be played.
     * @param array $progress The viewer's progress, keyed by lesson id.
     */
    public function __construct(
        /** @var stdClass The skilland activity record. */
        protected stdClass $skilland,
        /** @var stdClass The lesson to play. */
        protected stdClass $lesson,
        /** @var stdClass The course module record. */
        protected stdClass $cm,
        /** @var array The visible lessons of the activity. */
        protected array $alllessons,
        /** @var int The topic order index. */
        protected int $topicorderindex = 1,
        /** @var moodle_url|null The mod_scorm player of the lesson's SCO. */
        protected ?moodle_url $playerurl = null,
        /** @var array The viewer's progress, keyed by lesson id. */
        protected array $progress = []
    ) {
    }

    /**
     * The lesson list the player leads back to.
     *
     * @return moodle_url
     */
    public function get_back_url(): moodle_url {
        return new moodle_url('/mod/skilland/view.php', ['id' => $this->cm->id]);
    }

    /**
     * Export the player for mod_skilland/player.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $backurl = $this->get_back_url()->out(false);
        if ($this->playerurl === null) {
            return ['notready' => true, 'backurl' => $backurl];
        }

        $position = lesson_navigation::position_of($this->lesson, $this->alllessons);
        $label = skilland_lesson_label(
            $this->topicorderindex,
            $this->lesson,
            ($position ?? count($this->alllessons)) + 1
        );
        $title = format_string($this->lesson->title);
        $navigation = new lesson_navigation(
            $this->lesson,
            $this->alllessons,
            $this->cm,
            $this->topicorderindex,
            $this->skilland,
            lesson_navigation::STYLE_FULLSCREEN,
            $this->progress
        );
        $summary = new progress_summary($this->skilland, $this->alllessons, $this->progress);
        $lessonnumber = $summary->position_of($this->lesson);

        return [
            'notready' => false,
            'backurl' => $backurl,
            'lessontitle' => empty($this->skilland->hidelabels) ? $label . ' - ' . $title : $title,
            'iframetitle' => $title,
            'hasposition' => $lessonnumber !== null,
            'position' => $lessonnumber === null ? '' : get_string('lesson_position', 'mod_skilland', (object) [
                'number' => $lessonnumber,
                'total' => $summary->total(),
            ]),
            'playerurl' => $this->playerurl->out(false),
            'navigation' => $navigation->has_current() ? $navigation->export_for_template($output) : false,
        ];
    }
}
