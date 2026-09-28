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

/**
 * Only accounts that may sign in get an SSO token, judged from the real user table (SKL-647).
 *
 * The refusal runs before the JWT library is loaded, so a refused account is exercised end to end
 * through skilland_generate_sso_token(). The signed token and its claims, sub included, are covered
 * by the standalone suite (tests/phpunit/sso_token_claims_test.php), which has the JWT library.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::skilland_sso_user_refusal_reason
 * @covers     ::skilland_generate_sso_token
 */
final class sso_token_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('sso_secret', 'real-moodle-fixture-sso-secret-0123456789abcdef', 'mod_skilland');
        set_config('frontend_url', 'https://app.skilland.test', 'mod_skilland');
        logger::reset_cache();
    }

    /**
     * Assert that the token is refused for the user, with the reason in the log.
     *
     * @param \stdClass $user The user as the session holds it.
     * @param string $reason The reason the refusal logs.
     */
    private function assert_refused(\stdClass $user, string $reason): void {
        try {
            skilland_generate_sso_token($user, 'org-fixture');
            $this->fail('Expected the SSO token to be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_sso_user_not_allowed', $e->errorcode);
            $this->assertSame(get_string('error_sso_user_not_allowed', 'mod_skilland'), $e->getMessage());
        }
        $this->assertDebuggingCalled(
            '[Skilland] [SSO] WARNING: Refused token for user id ' . $user->id . ': ' . $reason,
            DEBUG_NORMAL
        );
    }

    public function test_active_confirmed_accounts_may_sign_in(): void {
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(skilland_sso_user_refusal_reason((int) $user->id));
        $this->assertNull(skilland_sso_user_refusal_reason((int) get_admin()->id));
    }

    public function test_guest_is_refused(): void {
        $this->assertSame('guest', skilland_sso_user_refusal_reason((int) guest_user()->id));
        $this->assert_refused(guest_user(), 'guest');
    }

    public function test_suspended_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);

        $this->assert_refused($user, 'suspended');
    }

    public function test_deleted_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user();
        delete_user($user);

        $this->assert_refused($user, 'deleted');
    }

    public function test_unconfirmed_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['confirmed' => 0]);

        $this->assert_refused($user, 'unconfirmed');
    }

    public function test_nologin_account_is_refused(): void {
        $user = $this->getDataGenerator()->create_user(['auth' => 'nologin']);

        $this->assert_refused($user, 'nologin');
    }

    public function test_account_suspended_after_sign_in_is_refused_despite_the_session_copy(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);

        $this->assertSame(0, (int) $user->suspended);
        $this->assert_refused($user, 'suspended');
    }

    public function test_unknown_user_id_is_refused(): void {
        global $DB;
        $unknownid = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}') + 1;

        $this->assertSame('missing', skilland_sso_user_refusal_reason($unknownid));
        $this->assertSame('notloggedin', skilland_sso_user_refusal_reason(0));
    }
}
