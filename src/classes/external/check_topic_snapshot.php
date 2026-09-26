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


namespace mod_skilland\external;

use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_skilland\logger;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../locallib.php');

/**
 * Web service mod_skilland_check_topic_snapshot: tell whether a topic's content changed in Skilland since the last sync.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_topic_snapshot extends base {
    /**
     * Parameters for check_topic_snapshot.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'skillandid' => new external_value(PARAM_INT, 'Skilland activity record ID', VALUE_REQUIRED),
        ]);
    }

    /**
     * Check if topic content has changed in Skilland.
     *
     * @param int $skillandid Skilland activity record ID.
     * @return array { isstale: bool, contenthash: string, error: string|null }
     */
    public static function execute(int $skillandid) {
        global $DB;

        self::require_enabled();

        $params = self::validate_parameters(self::execute_parameters(), [
            'skillandid' => $skillandid,
        ]);

        $skillandid = $params['skillandid'];

        // Get the skilland record.
        $skilland = $DB->get_record('skilland', ['id' => $skillandid], '*', MUST_EXIST);

        // Get the course module for context validation.
        $cm = get_coursemodule_from_instance('skilland', $skilland->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        // Validate the module context, then check the same capability that shows the update checker in view.php.
        self::validate_context($context);
        require_capability('mod/skilland:provision', $context);

        if (empty($skilland->skilland_topicid)) {
            return [
                'isstale' => false,
                'contenthash' => '',
                'error' => 'No topic ID configured',
            ];
        }

        try {
            $hashinfo = mod_skilland_check_topic_snapshot($skilland->skilland_topicid);

            if ($hashinfo === null) {
                return [
                    'isstale' => false,
                    'contenthash' => '',
                    'error' => 'Could not reach Skilland API',
                ];
            }

            $currentHash = $skilland->snapshotid ?? '';
            $remoteHash = $hashinfo['contentHash'] ?? '';
            $isstale = !empty($remoteHash) && $currentHash !== $remoteHash;

            return [
                'isstale' => $isstale,
                'contenthash' => $remoteHash,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'isstale' => false,
                'contenthash' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Return type for check_topic_snapshot.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'isstale' => new external_value(PARAM_BOOL, 'Whether content has changed since last sync'),
            'contenthash' => new external_value(PARAM_TEXT, 'Current content hash from Skilland'),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_OPTIONAL),
        ]);
    }
}
