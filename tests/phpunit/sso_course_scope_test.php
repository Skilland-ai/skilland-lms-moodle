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
 * SKL-645, beyond sso_role_test: the role follows mod/skilland:addinstance alone, the minter
 * checks the role before anything else, a missing or unknown course never reaches the minter,
 * and no AMD module or template builds its own handoff URL without the course.
 *
 * @package    mod_skilland
 * @copyright  2026 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sso_course_scope_test extends TestCase {
    /** @var string HS256 key the fixture plugin config signs with. */
    private const FIXTURE_SECRET = 'fixture-sso-signing-secret-not-a-real-one-0123456789';

    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        // The stub \context the role helper is typed against.
        require_once(__DIR__ . '/stubs/privacy_stub.php');
    }

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [
            'mod_skilland' => (object) ['sso_secret' => self::FIXTURE_SECRET],
        ];
        $GLOBALS['_test_enrolled_courses'] = [];
        $GLOBALS['_test_denied_capabilities'] = [];
        \mod_skilland\logger::reset_cache();
        $GLOBALS['DB']->seed('user', [
            (object) ['id' => 7, 'auth' => 'manual', 'confirmed' => 1, 'deleted' => 0, 'suspended' => 0],
        ]);
    }

    protected function tearDown(): void {
        unset($GLOBALS['_test_enrolled_courses'], $GLOBALS['_test_denied_capabilities']);
        $GLOBALS['DB']->seed('user', []);
        parent::tearDown();
    }

    /**
     * A session user.
     *
     * @param int $id The user id
     * @return \stdClass
     */
    private function user(int $id = 7): \stdClass {
        return (object) ['id' => $id, 'email' => 'teacher@school.com', 'firstname' => 'Jane', 'lastname' => 'Doe'];
    }

    /**
     * A course context.
     *
     * @param int $courseid The course id
     * @return \context
     */
    private function course_context(int $courseid = 5): \context {
        return new \context(1000 + $courseid, CONTEXT_COURSE, $courseid);
    }

    /**
     * A plugin source file.
     *
     * @param string $relative Path under src/
     * @return string
     */
    private function src(string $relative): string {
        return file_get_contents(__DIR__ . '/../../src/' . $relative);
    }

    /**
     * Offset of a needle in the source, failing when it is absent.
     *
     * @param string $source The source
     * @param string $needle The text to find
     * @return int
     */
    private function offset(string $source, string $needle): int {
        $position = strpos($source, $needle);
        $this->assertNotFalse($position, 'sso_redirect.php contains ' . $needle);
        return $position;
    }


    public function test_teacher_with_addinstance_is_an_expert(): void {
        $this->assertSame('Expert', skilland_sso_role_for_context($this->course_context()));
    }

    public function test_accessstudio_without_addinstance_is_a_learner(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:addinstance'];

        $this->assertSame('Learner', skilland_sso_role_for_context($this->course_context()));
    }

    public function test_the_role_does_not_follow_accessstudio(): void {
        // The accessstudio capability gates the handoff itself; the role only ever reads addinstance.
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:accessstudio', 'moodle/course:update'];

        $this->assertSame('Expert', skilland_sso_role_for_context($this->course_context()));
    }

    public function test_a_learner_role_mints_a_learner_token(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:addinstance'];
        $role = skilland_sso_role_for_context($this->course_context());

        $token = skilland_generate_sso_token($this->user(), 'org-9', $role);

        $this->assertSame('Learner', JWT::decode($token, new Key(self::FIXTURE_SECRET, 'HS256'))->role);
    }


    /**
     * Roles that look like an allowed one but are not.
     *
     * @return array<string, array{0: string}>
     */
    public static function lookalike_roles(): array {
        return [
            'leading space' => [' Expert'],
            'trailing space' => ['Expert '],
            'upper case' => ['EXPERT'],
            'lower-case learner' => ['learner'],
            'inspector' => ['Inspector'],
            'super admin' => ['SuperAdmin'],
        ];
    }

    /**
     * A role that only looks like Learner or Expert mints nothing.
     *
     * @dataProvider lookalike_roles
     * @param string $role The role passed to the minter
     */
    public function test_a_lookalike_role_is_refused(string $role): void {
        try {
            skilland_generate_sso_token($this->user(), 'org-9', $role);
            $this->fail('Expected a coding_exception for role ' . var_export($role, true));
        } catch (\coding_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
        }
    }

    public function test_an_invalid_role_is_refused_before_the_secret_is_read(): void {
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) ['sso_secret' => ''];

        try {
            skilland_generate_sso_token($this->user(), 'org-9', 'Admin');
            $this->fail('Expected a coding_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
            $this->assertStringNotContainsString('SSO Shared Secret', (string) $e->debuginfo);
        }
    }

    public function test_an_invalid_role_is_refused_before_the_account_is_checked(): void {
        try {
            // User 99 is not in the user table, so the account check would refuse it too.
            skilland_generate_sso_token($this->user(99), 'org-9', 'Owner');
            $this->fail('Expected a coding_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('codingerror', $e->errorcode);
        }
        $messages = implode("\n", array_column($GLOBALS['_test_debug_messages'], 'message'));
        $this->assertStringNotContainsString('Refused token for user id', $messages);
    }

    public function test_a_null_role_is_a_type_error(): void {
        $this->expectException(\TypeError::class);

        skilland_generate_sso_token($this->user(), 'org-9', null);
    }


    public function test_the_course_id_is_cleaned_as_an_integer(): void {
        $source = $this->src('sso_redirect.php');

        // A non-numeric course id cleans to 0, which get_course() cannot find.
        $this->assertStringContainsString("\$courseid = required_param('courseid', PARAM_INT);", $source);
        $this->assertSame(1, substr_count($source, "'courseid'"));
    }

    public function test_the_course_is_loaded_and_checked_before_the_minter_is_reached(): void {
        $source = $this->src('sso_redirect.php');
        $try = $this->offset($source, "\ntry {");

        // Every course step runs outside the try, so a missing course (dml_missing_record_exception)
        // or a refused capability leaves the page before any token exists.
        $steps = [
            "required_param('courseid'",
            '$course  = get_course($courseid);',
            '$context = context_course::instance($courseid);',
            'require_login($course);',
            'isguestuser()',
            "require_capability('mod/skilland:accessstudio', \$context);",
            '$role = skilland_sso_role_for_context($context);',
        ];
        $previous = -1;
        foreach ($steps as $step) {
            $position = $this->offset($source, $step);
            $this->assertGreaterThan($previous, $position, $step . ' runs in order');
            $this->assertLessThan($try, $position, $step . ' runs before the try block');
            $previous = $position;
        }
        $this->assertGreaterThan($try, $this->offset($source, 'skilland_generate_sso_token('));
        $this->assertSame(1, substr_count($source, 'skilland_generate_sso_token('));
    }

    public function test_the_sesskey_is_checked_before_the_course_is_loaded(): void {
        $source = $this->src('sso_redirect.php');

        $this->assertLessThan($this->offset($source, 'require_sesskey();'), $this->offset($source, 'require_login();'));
        $this->assertLessThan($this->offset($source, 'get_course($courseid)'), $this->offset($source, 'require_sesskey();'));
    }

    public function test_the_role_comes_only_from_the_course_context(): void {
        $source = $this->src('sso_redirect.php');

        // Nothing but the course context decides the role: no request parameter, no literal.
        $this->assertSame(1, preg_match_all('/\$role\s*=/', $source));
        $this->assertStringNotContainsString("'role'", $source);
        $this->assertStringNotContainsString("skilland_generate_sso_token(\$USER, \$orgid, 'Expert')", $source);
    }


    public function test_the_activity_form_hands_its_course_to_the_edit_link(): void {
        $php = $this->src('mod_form.php');
        $this->assertStringContainsString("'moodlecourseid' => (int) \$this->get_course()->id,", $php);

        $js = $this->src('amd/src/mod_form.js');
        $this->assertStringContainsString('var moodleCourseId = config.moodlecourseid;', $js);
        $this->assertStringContainsString('var ssoRedirectUrl = config.ssourl;', $js);
        $this->assertStringContainsString('params.courseid = moodleCourseId;', $js);

        $build = file_get_contents(__DIR__ . '/../../src/amd/build/mod_form.min.js');
        $this->assertStringContainsString('courseid', $build);
    }

    public function test_the_course_mapping_link_is_the_url_built_by_the_hook(): void {
        $js = $this->src('amd/src/course_mapping.js');

        $this->assertStringContainsString('insertGoToSkillandButton(fieldInput, config.ssourl, strings.goToSkilland);', $js);
        $this->assertStringContainsString('link.href = ssoUrl;', $js);
        $this->assertMatchesRegularExpression(
            "#new \\\\moodle_url\('/mod/skilland/sso_redirect\.php', \[\s*'courseid' => \\\$courseid,#",
            $this->src('classes/hooks.php')
        );
    }

    public function test_no_amd_module_or_template_builds_its_own_handoff_url(): void {
        $src = realpath(__DIR__ . '/../../src');
        $files = array_merge(glob("$src/amd/src/*.js"), glob("$src/templates/*.mustache"));
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            // Comments may name the page, but code must take the URL from the PHP that built it.
            $code = preg_replace('#/\*.*?\*/|//[^\n]*|\{\{!.*?\}\}#s', '', file_get_contents($file));
            $this->assertStringNotContainsString('sso_redirect', $code, basename($file) . ' builds a handoff URL');
        }
    }
}
