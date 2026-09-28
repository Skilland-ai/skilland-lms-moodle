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
        $response = $this->fetch_sso_redirect(['courseid' => $this->course_id($course)]);
        $html = $response['body'];

        $hasform = strpos($html, 'id="skilland-sso"') !== false;
        if (!$hasform || !preg_match('/<input type="hidden" name="token" value="([^"]+)">/', $html, $matches)) {
            throw new ExpectationException(
                'sso_redirect.php did not answer with the SkilLand handoff form: ' . $this->describe_response($response),
                $this->getSession()
            );
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
     * @return array{status: int, url: string, body: string} The response status, final URL and body
     */
    private function fetch_sso_redirect(array $params): array {
        $params['sesskey'] = $this->get_sesskey();
        $query = json_encode(http_build_query($params, '', '&'));
        $script = 'return fetch(M.cfg.wwwroot + "/mod/skilland/sso_redirect.php?" + ' . $query .
            ', {credentials: "same-origin"}).then(function(response) { return response.text().then(function(body) {' .
            ' return JSON.stringify({status: response.status, url: response.url, body: body}); }); });';
        $response = json_decode((string) $this->evaluate_script($script), true);
        return [
            'status' => (int) ($response['status'] ?? 0),
            'url' => (string) ($response['url'] ?? ''),
            'body' => (string) ($response['body'] ?? ''),
        ];
    }

    /**
     * A short, secret-free description of a response, for failure messages.
     *
     * The status, the path it ended on, the page title and the start of the error box (or of the
     * page text), with the SSO secret, any JWT and the sesskey redacted.
     *
     * @param array $response The response from fetch_sso_redirect()
     * @return string
     */
    private function describe_response(array $response): string {
        $html = $response['body'];
        $title = preg_match('#<title>(.*?)</title>#si', $html, $m) ? trim($m[1]) : '';
        $start = strpos($html, 'data-rel="fatalerror"');
        $fragment = $start === false ? $html : substr($html, $start);
        $fragment = preg_replace('#<(script|style)\b.*?</\1>#si', ' ', $fragment);
        $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags('<' . $fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $path = (string) parse_url($response['url'], PHP_URL_PATH);
        $summary = 'HTTP ' . $response['status'] . ' at ' . $path . ', title "' . $title . '", ' . substr($text, 0, 400);

        $secret = (string) get_config('mod_skilland', 'sso_secret');
        if ($secret !== '') {
            $summary = str_replace($secret, '[secret]', $summary);
        }
        $summary = preg_replace('/eyJ[\w-]+\.[\w-]+\.[\w-]*/', '[jwt]', $summary);
        return preg_replace('/sesskey=\w+/', 'sesskey=[redacted]', $summary);
    }

    /**
     * Assert that sso_redirect.php answers with an error page carrying a message instead of the handoff.
     *
     * @param array $params Query parameters besides the sesskey
     * @param string $message Text the error page must contain
     */
    private function assert_sso_redirect_refused(array $params, string $message): void {
        $response = $this->fetch_sso_redirect($params);
        $html = $response['body'];

        if (strpos($html, 'id="skilland-sso"') !== false) {
            throw new ExpectationException('sso_redirect.php answered with the SkilLand handoff form', $this->getSession());
        }
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strpos($text, $message) === false) {
            throw new ExpectationException(
                'sso_redirect.php did not answer with "' . $message . '": ' . $this->describe_response($response),
                $this->getSession()
            );
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
