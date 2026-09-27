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

/**
 * The provisioning prompt renderable and its template, rendered by the real Moodle output stack.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_skilland\output\provision
 * @covers     \mod_skilland\output\renderer
 */
final class provision_test extends \advanced_testcase {

    /**
     * The mod_skilland renderer, on a page with a context.
     *
     * @return renderer
     */
    private function renderer(): renderer {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        return $PAGE->get_renderer('mod_skilland');
    }

    public function test_export_for_template_casts_the_ids(): void {
        $this->resetAfterTest();

        $provision = new provision((object) ['id' => '7'], (object) ['id' => '42']);

        $this->assertSame(['skillandid' => 7, 'cmid' => 42], $provision->export_for_template($this->renderer()));
    }

    public function test_renderer_renders_the_ids_the_provisioning_script_reads(): void {
        $this->resetAfterTest();

        $html = $this->renderer()->render(new provision((object) ['id' => 7], (object) ['id' => 42]));

        $this->assertStringContainsString('id="skilland-provision-container" data-skillandid="7" data-cmid="42"', $html);
        $this->assertStringContainsString('id="skilland-provision-btn" data-skillandid="7" data-cmid="42"', $html);
        $this->assertStringContainsString('id="skilland-provision-loading" role="status" aria-live="polite"', $html);
        $this->assertStringContainsString('id="skilland-provision-error" role="alert"', $html);
        $this->assertStringContainsString(get_string('content_not_provisioned', 'mod_skilland'), $html);
        $this->assertStringContainsString(get_string('provision_topic', 'mod_skilland'), $html);
    }
}
