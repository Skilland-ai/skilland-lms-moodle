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

use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The teacher's prompt to build the SCORM package of a topic that has none yet.
 *
 * Rendered by mod_skilland/provision; mod_skilland/provision_scorm drives it.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provision implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param stdClass $skilland The skilland activity record.
     * @param stdClass $cm The course module record.
     */
    public function __construct(
        /** @var stdClass The skilland activity record. */
        protected stdClass $skilland,
        /** @var stdClass The course module record. */
        protected stdClass $cm
    ) {
    }

    /**
     * Export the ids the provisioning script reads.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        return [
            'skillandid' => (int) $this->skilland->id,
            'cmid' => (int) $this->cm->id,
        ];
    }
}
