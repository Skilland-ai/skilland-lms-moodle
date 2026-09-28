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

namespace mod_skilland\tests;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

/**
 * SKL-645: the SSO handoff needs a course the user may open SkilLand Studio from, refuses guests,
 * and the token's role follows mod/skilland:addinstance in that course instead of always being Expert.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sso_role_test extends TestCase {

    private const FIXTURE_SECRET = 'fixture-sso-signing-secret-not-a-real-one-0123456789';

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [
            'mod_skilland' => (object) ['sso_secret' => self::FIXTURE_SECRET],
        ];
        $GLOBALS['_test_enrolled_courses'] = [];
        \mod_skilland\logger::reset_cache();
        $GLOBALS['DB']->seed('user', [
            (object) ['id' => 7, 'auth' => 'manual', 'confirmed' => 1, 'deleted' => 0, 'suspended' => 0],
        ]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_enrolled_courses']);
        $GLOBALS['DB']->seed('user', []);
        parent::tearDown();
    }

    private function user(): \stdClass {
        return (object) ['id' => 7, 'email' => 'student@school.com', 'firstname' => 'Sam', 'lastname' => 'Doe'];
    }

    private function src(string $relative): string {
        return file_get_contents(__DIR__ . '/../../src/' . $relative);
    }

    // ---------------------------------------------------------------
    // skilland_determine_sso_role()
    // ---------------------------------------------------------------

    public function test_teacher_who_can_add_the_activity_is_an_expert(): void {
        $this->assertSame('Expert', skilland_determine_sso_role(true));
    }

    public function test_everyone_else_is_a_learner(): void {
        $this->assertSame('Learner', skilland_determine_sso_role(false));
    }

    public function test_role_for_context_reads_addinstance(): void {
        $source = $this->src('locallib.php');
        $this->assertSame(1, preg_match('/function skilland_sso_role_for_context\(context \$context\): string \{(.*?)\n}\n/s',
            $source, $m));
        $this->assertStringContainsString(
            "skilland_determine_sso_role(has_capability('mod/skilland:addinstance', \$context))",
            $m[1]
        );
    }

    // ---------------------------------------------------------------
    // skilland_generate_sso_token() — role claim
    // ---------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function allowed_roles(): array {
        return [
            'learner' => ['Learner'],
            'expert' => ['Expert'],
        ];
    }

    /**
     * @dataProvider allowed_roles
     */
    public function test_token_carries_the_role_it_is_given(string $role): void {
        $token = skilland_generate_sso_token($this->user(), 'org-9', $role);

        $claims = JWT::decode($token, new Key(self::FIXTURE_SECRET, 'HS256'));
        $this->assertSame($role, $claims->role);
    }

    /** @return array<string, array{0: string}> */
    public static function refused_roles(): array {
        return [
            'owner' => ['Owner'],
            'admin' => ['Admin'],
            'empty' => [''],
            'lower-case expert' => ['expert'],
        ];
    }

    /**
     * @dataProvider refused_roles
     */
    public function test_token_is_refused_for_any_other_role(string $role): void {
        try {
            skilland_generate_sso_token($this->user(), 'org-9', $role);
            $this->fail('Expected a coding_exception for role ' . var_export($role, true));
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
        }
        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringNotContainsString('Generated token', $messages);
    }

    public function test_role_is_a_required_argument(): void {
        $parameter = (new \ReflectionFunction('skilland_generate_sso_token'))->getParameters()[2];

        $this->assertSame('role', $parameter->getName());
        $this->assertFalse($parameter->isOptional());
        $this->assertSame('string', (string) $parameter->getType());
    }

    // ---------------------------------------------------------------
    // sso_redirect.php — source guards
    // ---------------------------------------------------------------

    public function test_sso_redirect_requires_a_course_id(): void {
        $source = $this->src('sso_redirect.php');

        $this->assertStringContainsString("required_param('courseid'", $source);
        $this->assertStringNotContainsString("optional_param('courseid'", $source);
        $this->assertStringNotContainsString('!empty($courseid)', $source);
        // The sesskey error still comes first for a request with neither parameter.
        $this->assertLessThan(strpos($source, "required_param('courseid'"), strpos($source, 'require_sesskey();'));
    }

    public function test_sso_redirect_refuses_guests_before_the_capability_check(): void {
        $source = $this->src('sso_redirect.php');

        $this->assertStringContainsString('isguestuser()', $source);
        $this->assertStringContainsString('throw new require_login_exception(', $source);
        $this->assertLessThan(strpos($source, 'isguestuser()'), strpos($source, 'require_login($course);'));
        $this->assertLessThan(strpos($source, "require_capability('mod/skilland:accessstudio'"),
            strpos($source, 'isguestuser()'));
    }

    public function test_sso_redirect_mints_the_role_resolved_in_the_course_context(): void {
        $source = $this->src('sso_redirect.php');

        $this->assertStringContainsString('$role = skilland_sso_role_for_context($context);', $source);
        $this->assertStringContainsString('skilland_generate_sso_token($USER, $orgid, $role)', $source);
        $this->assertLessThan(strpos($source, '$role = skilland_sso_role_for_context('),
            strpos($source, "require_capability('mod/skilland:accessstudio'"));
        $this->assertLessThan(strpos($source, 'skilland_generate_sso_token('),
            strpos($source, '$role = skilland_sso_role_for_context('));
    }

    public function test_every_sso_redirect_link_carries_a_course_id(): void {
        $src = realpath(__DIR__ . '/../../src');
        $files = array_merge(glob("$src/*.php"), glob("$src/classes/*.php"), glob("$src/classes/*/*.php"));
        $found = 0;
        foreach ($files as $file) {
            preg_match_all("#moodle_url\('/mod/skilland/sso_redirect\.php'(.*?)\)#s", file_get_contents($file), $m);
            foreach ($m[1] as $args) {
                $found++;
                if (trim($args) === '') {
                    // The mod_form AMD module appends courseid to the bare URL itself.
                    $this->assertSame('mod_form.php', basename($file));
                    continue;
                }
                $this->assertStringContainsString("'courseid' =>", $args, basename($file) . ' links to sso_redirect.php');
            }
        }
        $this->assertGreaterThanOrEqual(4, $found);
    }
}
