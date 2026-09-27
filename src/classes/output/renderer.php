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

use plugin_renderer_base;

/**
 * The mod_skilland renderer: every view.php widget goes through its Mustache template here.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the lesson list.
     *
     * @param lesson_list $list The lesson list.
     * @return string HTML.
     */
    public function render_lesson_list(lesson_list $list): string {
        return $this->render_from_template('mod_skilland/lesson_list', $list->export_for_template($this));
    }

    /**
     * Render the fullscreen player (or its not-ready notice).
     *
     * @param player $player The player.
     * @return string HTML.
     */
    public function render_player(player $player): string {
        return $this->render_from_template('mod_skilland/player', $player->export_for_template($this));
    }

    /**
     * Render the previous / next lesson links; nothing when the current lesson is not among the lessons.
     *
     * @param lesson_navigation $navigation The navigation.
     * @return string HTML.
     */
    public function render_lesson_navigation(lesson_navigation $navigation): string {
        if (!$navigation->has_current()) {
            return '';
        }
        $template = $navigation->get_style() === lesson_navigation::STYLE_PLAYER
            ? 'mod_skilland/player_navigation'
            : 'mod_skilland/fullscreen_navigation';
        return $this->render_from_template($template, $navigation->export_for_template($this));
    }

    /**
     * Render the provisioning prompt.
     *
     * @param provision $provision The prompt.
     * @return string HTML.
     */
    public function render_provision(provision $provision): string {
        return $this->render_from_template('mod_skilland/provision', $provision->export_for_template($this));
    }
}
