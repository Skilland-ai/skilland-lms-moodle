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

use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The previous / next lesson links around the lesson being played.
 *
 * Rendered by mod_skilland/fullscreen_navigation (the fullscreen player's bottom bar) or
 * mod_skilland/player_navigation (a plain row), picked by the style.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_navigation implements renderable, templatable {

    /** The fullscreen player's bottom bar, mod_skilland/fullscreen_navigation. */
    public const STYLE_FULLSCREEN = 'fullscreen';

    /** A plain previous / next row, mod_skilland/player_navigation. */
    public const STYLE_PLAYER = 'player';

    /** @var stdClass[] The lessons of the activity, re-indexed from 0. */
    protected array $lessons;

    /** @var int|null The 0-based position of the current lesson, null when it is not among the lessons. */
    protected ?int $position;

    /**
     * Constructor.
     *
     * @param stdClass $currentlesson The lesson being played.
     * @param array $alllessons The visible lessons of the activity, in order.
     * @param stdClass $cm The course module record.
     * @param int $topicorderindex The topic order index (T1, T2, etc.).
     * @param stdClass $skilland The skilland activity record.
     * @param string $style One of the STYLE_ constants.
     */
    public function __construct(
        stdClass $currentlesson,
        array $alllessons,
        /** @var stdClass The course module record. */
        protected stdClass $cm,
        /** @var int The topic order index. */
        protected int $topicorderindex,
        /** @var stdClass The skilland activity record. */
        protected stdClass $skilland,
        /** @var string One of the STYLE_ constants. */
        protected string $style = self::STYLE_FULLSCREEN
    ) {
        $this->lessons = array_values($alllessons);
        $this->position = self::position_of($currentlesson, $alllessons);
    }

    /**
     * The 0-based position of a lesson among the lessons of the activity.
     *
     * @param stdClass $lesson The lesson.
     * @param array $alllessons The lessons of the activity, in order.
     * @return int|null Its position, null when it is not among them.
     */
    public static function position_of(stdClass $lesson, array $alllessons): ?int {
        foreach (array_values($alllessons) as $i => $candidate) {
            if ($candidate->id == $lesson->id) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Whether the current lesson is among the lessons; without it there is nothing to render.
     *
     * @return bool
     */
    public function has_current(): bool {
        return $this->position !== null;
    }

    /**
     * The navigation style, one of the STYLE_ constants.
     *
     * @return string
     */
    public function get_style(): string {
        return $this->style;
    }

    /**
     * Export the previous and next links.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context: prev and next, each a link or false.
     */
    public function export_for_template(renderer_base $output): array {
        if ($this->position === null) {
            return ['prev' => false, 'next' => false];
        }
        return [
            'prev' => $this->export_link($this->position - 1, 'aria_previous_lesson'),
            'next' => $this->export_link($this->position + 1, 'aria_next_lesson'),
        ];
    }

    /**
     * Export the link to a neighbouring lesson.
     *
     * @param int $index The 0-based position of the neighbour.
     * @param string $arialabelid The mod_skilland string of the link's accessible name.
     * @return array|false url, text and arialabel; false when there is no playable lesson there.
     */
    protected function export_link(int $index, string $arialabelid) {
        $lesson = $this->lessons[$index] ?? null;
        // Only a lesson with a SCO in the installed package can be linked.
        if (!$lesson || empty($lesson->scoid) || empty($this->skilland->scormcmid)) {
            return false;
        }
        $label = 'L' . $this->topicorderindex . '.' . ($index + 1);
        $text = empty($this->skilland->hidelabels)
            ? $label . ' - ' . format_string($lesson->title)
            : format_string($lesson->title);
        return [
            'url' => (new moodle_url('/mod/skilland/view.php', ['id' => $this->cm->id, 'play' => $lesson->id]))->out(false),
            'text' => $text,
            'arialabel' => get_string($arialabelid, 'mod_skilland', $text),
        ];
    }
}
