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
 * Exception raised for an error returned in a GraphQL response body.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_skilland;

defined('MOODLE_INTERNAL') || die();

/**
 * A moodle_exception that also carries the API's extensions.code.
 *
 * Callers branch on $graphqlcode instead of searching the translated message for it.
 */
class graphql_exception extends \moodle_exception {

    /** @var string The GraphQL extensions.code of the error, '' when the API sent none. Read-only: set by the constructor. */
    public $graphqlcode;

    /**
     * Constructor.
     *
     * @param string $errorcode Language string identifier in mod_skilland.
     * @param string $graphqlcode The GraphQL extensions.code, '' when absent.
     * @param mixed $a Extra data for the language string.
     * @param string|null $debuginfo Optional debugging information.
     */
    public function __construct(string $errorcode, string $graphqlcode = '', $a = null, ?string $debuginfo = null) {
        $this->graphqlcode = $graphqlcode;
        parent::__construct($errorcode, 'mod_skilland', '', $a, $debuginfo);
    }
}
