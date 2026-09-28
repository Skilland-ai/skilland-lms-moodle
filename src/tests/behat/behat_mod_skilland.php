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
 * Step definitions for the SkilLand activity.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * SkilLand activity step definitions.
 *
 * The SSO handoff answers with a form that submits itself to SkilLand, so these steps fetch
 * sso_redirect.php in the background, with the browser session and its sesskey, and read the
 * answer instead of navigating to it: the browser never leaves Moodle and an expected error page
 * never becomes the current page.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_skilland extends behat_base {
    /**
     * Checks that the SSO handoff started from a course signs the current user in with a role.
     *
     * @Then the SkilLand SSO handoff for course :course should sign me in as :role
     * @param string $course The course shortname, fullname or idnumber
     * @param string $role The SkilLand role the token must carry
     */
    public function the_skilland_sso_handoff_for_course_should_sign_me_in_as(string $course, string $role): void {
        $html = $this->fetch_sso_redirect(['courseid' => $this->course_id($course)]);

        $hasform = strpos($html, 'id="skilland-sso"') !== false;
        if (!$hasform || !preg_match('/<input type="hidden" name="token" value="([^"]+)">/', $html, $matches)) {
            throw new ExpectationException('sso_redirect.php did not answer with the SkilLand handoff form', $this->getSession());
        }
        $parts = explode('.', html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));
        $claims = count($parts) === 3 ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
        $actual = is_array($claims) ? ($claims['role'] ?? null) : null;
        if ($actual !== $role) {
            throw new ExpectationException(
                'The SkilLand SSO token carries the role ' . var_export($actual, true) . ', expected ' . $role,
                $this->getSession()
            );
        }
    }

    /**
     * Checks that the SSO handoff started from a course is refused with a message, and mints no token.
     *
     * @Then the SkilLand SSO handoff for course :course should be refused with :message
     * @param string $course The course shortname, fullname or idnumber
     * @param string $message Text the error page must contain
     */
    public function the_skilland_sso_handoff_for_course_should_be_refused_with(string $course, string $message): void {
        $this->assert_sso_redirect_refused(['courseid' => $this->course_id($course)], $message);
    }

    /**
     * Checks that the SSO handoff without a course is refused with a message, and mints no token.
     *
     * @Then the SkilLand SSO handoff without a course should be refused with :message
     * @param string $message Text the error page must contain
     */
    public function the_skilland_sso_handoff_without_a_course_should_be_refused_with(string $message): void {
        $this->assert_sso_redirect_refused([], $message);
    }

    /**
     * Fetch sso_redirect.php with the browser's session and a valid sesskey.
     *
     * @param array $params Query parameters besides the sesskey
     * @return string The response body
     */
    private function fetch_sso_redirect(array $params): string {
        $params['sesskey'] = $this->get_sesskey();
        $query = json_encode(http_build_query($params, '', '&'));
        $script = 'return fetch(M.cfg.wwwroot + "/mod/skilland/sso_redirect.php?" + ' . $query .
            ', {credentials: "same-origin"}).then(function(response) { return response.text(); });';
        return (string) $this->evaluate_script($script);
    }

    /**
     * Assert that sso_redirect.php answers with an error page carrying a message instead of the handoff.
     *
     * @param array $params Query parameters besides the sesskey
     * @param string $message Text the error page must contain
     */
    private function assert_sso_redirect_refused(array $params, string $message): void {
        $html = $this->fetch_sso_redirect($params);

        if (strpos($html, 'id="skilland-sso"') !== false) {
            throw new ExpectationException('sso_redirect.php answered with the SkilLand handoff form', $this->getSession());
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($text, $message) === false) {
            throw new ExpectationException('sso_redirect.php did not answer with "' . $message . '"', $this->getSession());
        }
    }

    /**
     * The id of a course.
     *
     * @param string $course The course shortname, fullname or idnumber
     * @return int
     */
    private function course_id(string $course): int {
        $courseid = $this->get_course_id($course);
        if (!$courseid) {
            throw new ExpectationException('Unknown course "' . $course . '"', $this->getSession());
        }
        return $courseid;
    }
}
