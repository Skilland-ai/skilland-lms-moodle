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
 * The lesson list of a Skilland activity, each lesson with the viewer's progress.
 *
 * Rendered by mod_skilland/lesson_list.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lesson_list implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $skilland The skilland activity record.
     * @param array $lessons The visible lessons, in order.
     * @param stdClass $cm The course module record.
     * @param int $topicorderindex The topic order index (T1, T2, etc.).
     * @param array $progress The viewer's progress, keyed by lesson id: ['status' => ..., 'score' => ...].
     * @param bool $showscowarnings Whether to flag lessons missing from the installed package (teachers).
     */
    public function __construct(
        /** @var stdClass The skilland activity record. */
        protected stdClass $skilland,
        /** @var array The visible lessons, in order. */
        protected array $lessons,
        /** @var stdClass The course module record. */
        protected stdClass $cm,
        /** @var int The topic order index. */
        protected int $topicorderindex = 1,
        /** @var array The viewer's progress, keyed by lesson id. */
        protected array $progress = [],
        /** @var bool Whether to flag lessons missing from the installed package. */
        protected bool $showscowarnings = false
    ) {
    }

    /**
     * Export the lessons for mod_skilland/lesson_list.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $summary = new progress_summary($this->skilland, $this->lessons, $this->progress);
        $target = $summary->target();
        $targetid = $target ? (int) $target['lesson']->id : null;

        $lessons = [];
        $lessonindex = 1;
        foreach ($this->lessons as $lesson) {
            $card = $this->export_lesson($lesson, $lessonindex);
            $card['istarget'] = $targetid !== null && (int) $lesson->id === $targetid;
            $lessons[] = $card;
            $lessonindex++;
        }
        return [
            'haslessons' => !empty($lessons),
            'lessons' => $lessons,
        ] + $this->export_summary($summary, $target);
    }

    /**
     * Export the progress summary and the Continue button.
     *
     * @param progress_summary $summary The learner's progress.
     * @param array $target The summary's target, empty without playable lessons.
     * @return array The summary's part of the template context; just hassummary false without lessons.
     */
    protected function export_summary(progress_summary $summary, array $target): array {
        $total = $summary->total();
        if ($total === 0 || !$target) {
            return ['hassummary' => false];
        }
        $completed = $summary->completed();
        $counts = (object) ['completed' => $completed, 'total' => $total];
        $buttonstring = [
            progress_summary::MODE_START => 'start_lesson',
            progress_summary::MODE_CONTINUE => 'continue_lesson',
            progress_summary::MODE_REVIEW => 'review_lessons',
        ][$target['mode']];

        return [
            'hassummary' => true,
            'completedcount' => $completed,
            'totalcount' => $total,
            'percent' => (int) round(100 * $completed / $total),
            'summarytext' => get_string('lessons_progress', 'mod_skilland', $counts),
            'progressbarlabel' => get_string('progress_bar_label', 'mod_skilland', $counts),
            'continuemode' => $target['mode'],
            'continuetext' => get_string($buttonstring, 'mod_skilland'),
            'continueurl' => (new moodle_url('/mod/skilland/view.php', [
                'id' => $this->cm->id,
                'play' => $target['lesson']->id,
            ]))->out(false),
        ];
    }

    /**
     * Export one lesson card.
     *
     * @param stdClass $lesson The lesson record.
     * @param int $lessonindex The 1-based place of the lesson in the list (the label's fallback).
     * @return array The card's context.
     */
    protected function export_lesson(stdClass $lesson, int $lessonindex): array {
        // A lesson can be played once it has a SCO in the installed package.
        $canplay = !empty($lesson->scoid) && !empty($this->skilland->scormcmid);
        $url = $canplay
            ? (new moodle_url('/mod/skilland/view.php', ['id' => $this->cm->id, 'play' => $lesson->id]))->out(false)
            : '';

        $lessonprogress = $this->progress[$lesson->id] ?? null;
        $status = $lessonprogress['status'] ?? 'not_started';
        $score = $lessonprogress['score'] ?? null;

        $meta = [];
        if (!empty($lesson->updatedat)) {
            $meta[] = get_string('updated', 'mod_skilland') . ': ' .
                userdate($lesson->updatedat, get_string('strftimedateshort'));
        }
        if ($score !== null && $score !== '') {
            $scorevalue = (float) $score;
            $meta[] = get_string('score', 'mod_skilland') . ': ' . format_float($scorevalue, 0) .
                (($scorevalue >= 0 && $scorevalue <= 100) ? '%' : '');
        }

        return array_merge(self::status_display($status, $canplay), [
            'playable' => $canplay,
            'url' => $url,
            'showlabel' => empty($this->skilland->hidelabels),
            'label' => skilland_lesson_label($this->topicorderindex, $lesson, $lessonindex),
            'title' => format_string($lesson->title),
            'meta' => implode(' · ', $meta),
            'scomissing' => $this->showscowarnings && empty($lesson->scoid),
        ]);
    }

    /**
     * The card class, icon and label of a progress status.
     *
     * @param string $status The stored progress status.
     * @param bool $canplay Whether the lesson can be played.
     * @return array completionclass, icon and statustext.
     */
    protected static function status_display(string $status, bool $canplay): array {
        switch ($status) {
            case 'completed':
            case 'passed':
                return self::status('skilland-lesson-completed', 'fa-check-circle', 'completed');
            case 'incomplete':
            case 'browsed':
                return self::status('skilland-lesson-in-progress', 'fa-clock-o', 'in_progress');
            case 'failed':
                return self::status('skilland-lesson-failed', 'fa-times-circle', 'failed');
            default:
                return $canplay
                    ? self::status('skilland-lesson-available', 'fa-play-circle', 'ready_to_start')
                    : array_merge(
                        self::status('skilland-lesson-pending', 'fa-circle-o', 'not_yet_available'),
                        ['helptext' => get_string('not_yet_available_help', 'mod_skilland')]
                    );
        }
    }

    /**
     * Build one status display.
     *
     * @param string $class The card's progress class.
     * @param string $icon The Font Awesome icon.
     * @param string $stringid The mod_skilland string of the status label.
     * @return array completionclass, icon and statustext.
     */
    private static function status(string $class, string $icon, string $stringid): array {
        return [
            'completionclass' => $class,
            'icon' => $icon,
            'statustext' => get_string($stringid, 'mod_skilland'),
        ];
    }
}
