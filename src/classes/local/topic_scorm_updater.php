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
 * Injectable wrapper around skilland_update_topic_scorm().
 *
 * @package    mod_skilland
 * @copyright  2024 Skilland <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland\local;

/**
 * Rebuilds a topic's SCORM package. Callers resolve it with \core\di::get() so tests can
 * replace the rebuild with \core\di::set(topic_scorm_updater::class, $fake).
 */
class topic_scorm_updater {
    /**
     * Load skilland_update_topic_scorm() from locallib.php.
     */
    public function __construct() {
        require_once(__DIR__ . '/../../locallib.php');
    }

    /**
     * Update the topic SCORM package; see skilland_update_topic_scorm().
     *
     * @param \stdClass $skilland The skilland activity record.
     * @param \stdClass $course The Moodle course record.
     * @param int|string $sectionnum The section number for the SCORM.
     * @param string|null $contenthash Pre-fetched content hash, fetched when null.
     * @return int The new SCORM course module ID.
     * @throws \moodle_exception If the update fails or provisioning is already in progress.
     */
    public function update($skilland, $course, $sectionnum = 0, ?string $contenthash = null) {
        return skilland_update_topic_scorm($skilland, $course, $sectionnum, $contenthash);
    }
}
