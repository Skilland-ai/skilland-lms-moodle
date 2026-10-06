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

namespace mod_skilland\local;

use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * What a learner has done in a Skilland activity: how many lessons are complete and where to go next.
 *
 * Only visible lessons with a SCO in the installed package count. "Complete" is the same
 * definition as the activity's custom completion rule (completed or passed).
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class progress_summary {
    /** The learner has not started any lesson. */
    public const MODE_START = 'start';

    /** The learner is part way through. */
    public const MODE_CONTINUE = 'continue';

    /** Every lesson is complete. */
    public const MODE_REVIEW = 'review';

    /** @var stdClass[] The playable lessons, in display order. */
    private array $playable;

    /** @var array The learner's progress by lesson id. */
    private array $progress;

    /**
     * Constructor.
     *
     * @param stdClass $skilland The skilland activity record.
     * @param array $lessons The visible lessons, in order.
     * @param array $progress The learner's progress, keyed by lesson id: ['status' => ..., 'score' => ...].
     */
    public function __construct(stdClass $skilland, array $lessons, array $progress) {
        $this->playable = [];
        if (!empty($skilland->scormcmid)) {
            foreach ($lessons as $lesson) {
                if (!empty($lesson->scoid)) {
                    $this->playable[] = $lesson;
                }
            }
        }
        $this->progress = $progress;
    }

    /**
     * The status of a lesson.
     *
     * @param stdClass $lesson The lesson.
     * @return string
     */
    private function status_of(stdClass $lesson): string {
        return (string) ($this->progress[$lesson->id]['status'] ?? 'not_started');
    }

    /**
     * Whether a lesson is complete.
     *
     * @param stdClass $lesson The lesson.
     * @return bool
     */
    private function is_complete(stdClass $lesson): bool {
        return skilland_progress_status_rank($this->status_of($lesson)) === 3;
    }

    /**
     * The number of playable lessons.
     *
     * @return int
     */
    public function total(): int {
        return count($this->playable);
    }

    /**
     * The number of complete playable lessons.
     *
     * @return int
     */
    public function completed(): int {
        return count(array_filter($this->playable, fn($lesson) => $this->is_complete($lesson)));
    }

    /**
     * Whether there is at least one lesson and all of them are complete.
     *
     * @return bool
     */
    public function all_complete(): bool {
        return $this->total() > 0 && $this->completed() === $this->total();
    }

    /**
     * The 1-based place of a lesson among the playable lessons.
     *
     * @param stdClass $lesson The lesson.
     * @return int|null Null when it is not a playable lesson of the activity.
     */
    public function position_of(stdClass $lesson): ?int {
        foreach ($this->playable as $i => $candidate) {
            if ($candidate->id == $lesson->id) {
                return $i + 1;
            }
        }
        return null;
    }

    /**
     * The lesson the Continue button opens and what the button says.
     *
     * The first lesson in progress, else the first not started (failed counts as in progress);
     * with every lesson complete, the first lesson to review.
     *
     * @return array ['lesson' => stdClass, 'mode' => one of the MODE_ constants]; empty without lessons.
     */
    public function target(): array {
        if (!$this->playable) {
            return [];
        }
        if ($this->all_complete()) {
            return ['lesson' => $this->playable[0], 'mode' => self::MODE_REVIEW];
        }
        foreach ($this->playable as $lesson) {
            if (in_array($this->status_of($lesson), ['incomplete', 'browsed', 'failed'], true)) {
                return ['lesson' => $lesson, 'mode' => self::MODE_CONTINUE];
            }
        }
        $started = $this->completed() > 0;
        foreach ($this->playable as $lesson) {
            if (!$this->is_complete($lesson)) {
                return ['lesson' => $lesson, 'mode' => $started ? self::MODE_CONTINUE : self::MODE_START];
            }
        }
        return [];
    }
}
