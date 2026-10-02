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

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/skilland/locallib.php');
require_once($CFG->libdir . '/upgradelib.php');

/**
 * The upgrade step that clears a Frontend URL holding the old default (SKL-991).
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::xmldb_skilland_upgrade
 * @covers     ::skilland_get_frontend_url
 */
final class upgrade_frontend_url_test extends \advanced_testcase {
    /**
     * Run the upgrade from just below the SKL-991 step and return the stored Frontend URL.
     *
     * @param string $frontendurl The Frontend URL before the upgrade.
     * @return string
     */
    private function upgrade_with(string $frontendurl): string {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/skilland/db/upgrade.php');

        set_config('version', 2026100202, 'mod_skilland');
        set_config('frontend_url', $frontendurl, 'mod_skilland');
        $this->assertTrue(xmldb_skilland_upgrade(2026100202));
        return (string) get_config('mod_skilland', 'frontend_url');
    }

    /**
     * The old default is cleared.
     */
    public function test_clears_the_old_default(): void {
        $this->assertSame('', $this->upgrade_with('https://app.skilland.ai'));
    }

    /**
     * A custom value stays.
     */
    public function test_keeps_a_custom_value(): void {
        $this->assertSame('https://custom.example.org', $this->upgrade_with('https://custom.example.org'));
    }

    /**
     * An empty value stays empty.
     */
    public function test_keeps_an_empty_value(): void {
        $this->assertSame('', $this->upgrade_with(''));
    }

    /**
     * An empty Frontend URL falls back to the Skilland URL.
     */
    public function test_frontend_url_falls_back_to_the_skilland_url(): void {
        $this->resetAfterTest();
        set_config('frontend_url', '', 'mod_skilland');
        set_config('graphql_endpoint', 'https://api.skilland.test/', 'mod_skilland');
        $this->assertSame('https://api.skilland.test', skilland_get_frontend_url());
    }
}
