<?php

namespace mod_skilland\tests;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use mod_skilland\privacy\provider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/privacy_stub.php';

/**
 * The Privacy API provider (SKL-660): metadata, the guards that keep it in step with install.xml
 * and the data sent to Skilland, and export/delete of skilland_progress.
 */
class privacy_provider_test extends TestCase {

    private const USER_A = 10;
    private const USER_B = 20;
    private const USER_NONE = 30;

    private const CTX_ACTIVITY_1 = 100;
    private const CTX_ACTIVITY_2 = 101;
    private const CTX_COURSE = 200;

    /** Fields of the data sent to Skilland that carry no personal data. */
    private const NON_PERSONAL_PAYLOAD_KEYS = ['orgId', 'nonce', 'iat', 'exp', 'aud', 'iss', 'source'];

    /** SSO payload key => field declared on the 'skilland' external location. */
    private const PAYLOAD_FIELD_MAP = [
        'sub' => 'userid',
        'email' => 'email',
        'name' => 'fullname',
        'role' => 'role',
        'courseAccess' => 'courseaccess',
    ];

    /** @var \FakeDatabase */
    private $db;

    /** @var array Fixture rows, also answered by the fake get_records_sql(). */
    private $contexts = [];
    private $cms = [];
    private $modules = [];

    private const GLOBALS_TO_RESET = ['_test_cm_from_db', '_test_get_coursemodule_from_id', '_test_privacy_exports'];

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_cm_from_db'] = true;
        $GLOBALS['_test_privacy_exports'] = [];

        $this->modules = [
            (object) ['id' => 1, 'name' => 'skilland'],
            (object) ['id' => 2, 'name' => 'scorm'],
        ];
        $this->cms = [
            (object) ['id' => 40, 'course' => 3, 'module' => 1, 'instance' => 7],
            (object) ['id' => 41, 'course' => 3, 'module' => 1, 'instance' => 8],
        ];
        $this->contexts = [
            (object) ['id' => self::CTX_ACTIVITY_1, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => 40],
            (object) ['id' => self::CTX_ACTIVITY_2, 'contextlevel' => CONTEXT_MODULE, 'instanceid' => 41],
            (object) ['id' => self::CTX_COURSE, 'contextlevel' => CONTEXT_COURSE, 'instanceid' => 3],
        ];
        $this->db->seed('modules', $this->modules);
        $this->db->seed('course_modules', $this->cms);
        $this->db->seed('context', $this->contexts);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'title' => 'Lesson one'],
            (object) ['id' => 2, 'skillandid' => 7, 'title' => 'Lesson two'],
            (object) ['id' => 3, 'skillandid' => 8, 'title' => 'Lesson three'],
        ]);
        $this->db->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => 7, 'lessonid' => 1, 'userid' => self::USER_A,
                'status' => 'passed', 'score' => 90.0, 'timemodified' => 1758800000],
            (object) ['id' => 2, 'skillandid' => 7, 'lessonid' => 2, 'userid' => self::USER_A,
                'status' => 'incomplete', 'score' => null, 'timemodified' => 1758900000],
            (object) ['id' => 3, 'skillandid' => 7, 'lessonid' => 1, 'userid' => self::USER_B,
                'status' => 'failed', 'score' => 20.0, 'timemodified' => 1758700000],
            (object) ['id' => 4, 'skillandid' => 8, 'lessonid' => 3, 'userid' => self::USER_B,
                'status' => 'completed', 'score' => null, 'timemodified' => 1758600000],
        ]);

        // Answers the get_contexts_for_userid() query: context ⋈ course_modules ⋈ modules ⋈ skilland_progress.
        $this->db->set_records_sql_handler(function (string $sql, array $params): array {
            $rows = [];
            foreach ($this->contexts as $ctx) {
                if ($ctx->contextlevel != $params['contextlevel']) {
                    continue;
                }
                foreach ($this->cms as $cm) {
                    if ($cm->id != $ctx->instanceid) {
                        continue;
                    }
                    foreach ($this->modules as $module) {
                        if ($module->id != $cm->module || $module->name !== $params['modname']) {
                            continue;
                        }
                        foreach ($this->db->get_records('skilland_progress', ['skillandid' => $cm->instance]) as $row) {
                            if ($row->userid == $params['userid']) {
                                $rows[$ctx->id] = (object) ['id' => $ctx->id];
                            }
                        }
                    }
                }
            }
            return $rows;
        });
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Metadata
    // ---------------------------------------------------------------

    private function metadata(): array {
        $items = provider::get_metadata(new collection('mod_skilland'))->get_collection();
        $byname = [];
        foreach ($items as $item) {
            $byname[$item['type'] . ':' . $item['name']] = $item;
        }
        return $byname;
    }

    private static function lang(string $lang): array {
        $string = [];
        require __DIR__ . "/../../src/lang/$lang/skilland.php";
        return $string;
    }

    public function test_provider_implements_the_three_interfaces(): void {
        $interfaces = class_implements(provider::class);
        $this->assertContains(\core_privacy\local\metadata\provider::class, $interfaces);
        $this->assertContains(\core_privacy\local\request\plugin\provider::class, $interfaces);
        $this->assertContains(\core_privacy\local\request\core_userlist_provider::class, $interfaces);
    }

    public function test_metadata_declares_the_progress_table_and_the_skilland_location(): void {
        $items = provider::get_metadata(new collection('mod_skilland'))->get_collection();
        $this->assertCount(2, $items);

        $metadata = $this->metadata();
        $this->assertArrayHasKey('database_table:skilland_progress', $metadata);
        $this->assertSame(['userid', 'lessonid', 'status', 'score', 'timemodified'],
            array_keys($metadata['database_table:skilland_progress']['fields']));
        $this->assertSame('privacy:metadata:skilland_progress', $metadata['database_table:skilland_progress']['summary']);

        $this->assertArrayHasKey('external_location:skilland', $metadata);
        $this->assertSame(['userid', 'email', 'fullname', 'role', 'courseaccess'],
            array_keys($metadata['external_location:skilland']['fields']));
        $this->assertSame('privacy:metadata:skilland', $metadata['external_location:skilland']['summary']);
    }

    public function test_every_metadata_string_exists_in_en_and_es(): void {
        $keys = ['privacy:path:progress'];
        foreach ($this->metadata() as $item) {
            $keys[] = $item['summary'];
            foreach ($item['fields'] as $key) {
                $keys[] = $key;
            }
        }
        foreach (['en', 'es'] as $lang) {
            $strings = self::lang($lang);
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings, "$lang is missing $key");
                $this->assertNotSame('', trim($strings[$key]), "$lang $key is empty");
            }
        }
    }

    public function test_skilland_summary_names_the_three_flows(): void {
        $summary = self::lang('en')['privacy:metadata:skilland'];
        $this->assertStringContainsString('Skilland Studio', $summary);
        $this->assertStringContainsString('list the Skilland courses', $summary);
        $this->assertStringContainsString('create a Skilland course', $summary);
        $this->assertStringContainsString('Skilland administrator', $summary);
    }

    // ---------------------------------------------------------------
    // Guards: new userid tables and new outbound fields must be declared
    // ---------------------------------------------------------------

    public function test_every_install_xml_table_with_a_userid_is_declared(): void {
        $metadata = $this->metadata();
        $xml = simplexml_load_file(__DIR__ . '/../../src/db/install.xml');
        $withuserid = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string) $table['NAME'];
            $fields = [];
            foreach ($table->FIELDS->FIELD as $field) {
                $fields[] = (string) $field['NAME'];
            }
            if (in_array('userid', $fields, true)) {
                $withuserid[] = $name;
                $this->assertArrayHasKey("database_table:$name", $metadata,
                    "install.xml table $name has a userid field; declare it in classes/privacy/provider.php");
                foreach (array_keys($metadata["database_table:$name"]['fields']) as $declared) {
                    $this->assertContains($declared, $fields, "$name.$declared is declared but not in install.xml");
                }
            }
        }
        $this->assertSame(['skilland_progress'], $withuserid);
    }

    public function test_activity_course_and_lesson_tables_hold_no_userid(): void {
        $xml = simplexml_load_file(__DIR__ . '/../../src/db/install.xml');
        $seen = [];
        foreach ($xml->TABLES->TABLE as $table) {
            $name = (string) $table['NAME'];
            if (!in_array($name, ['skilland', 'skilland_lesson'], true)) {
                continue;
            }
            $seen[] = $name;
            foreach ($table->FIELDS->FIELD as $field) {
                $this->assertNotSame('userid', (string) $field['NAME'], "$name must not hold a userid");
            }
        }
        $this->assertCount(2, $seen);
    }

    public function test_every_personal_sso_payload_key_is_declared(): void {
        $source = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertSame(1, preg_match('/function skilland_generate_sso_token\b.*?\$payload\s*=\s*\[(.*?)\n\s*\];/s',
            $source, $m), 'Could not find the $payload literal in skilland_generate_sso_token()');
        preg_match_all("/^\s*'(\w+)'\s*=>/m", $m[1], $keys);
        $this->assertNotEmpty($keys[1]);

        $declared = array_keys($this->metadata()['external_location:skilland']['fields']);
        foreach ($keys[1] as $key) {
            if (in_array($key, self::NON_PERSONAL_PAYLOAD_KEYS, true)) {
                continue;
            }
            $this->assertArrayHasKey($key, self::PAYLOAD_FIELD_MAP,
                "SSO payload key '$key' is new: declare it in classes/privacy/provider.php and map it here");
            $this->assertContains(self::PAYLOAD_FIELD_MAP[$key], $declared);
        }
    }

    public function test_every_rest_user_email_field_is_declared_as_email(): void {
        $src = realpath(__DIR__ . '/../../src');
        $files = array_merge(glob("$src/*.php"), glob("$src/classes/*.php"), glob("$src/classes/*/*.php"));
        $found = 0;
        foreach ($files as $file) {
            $found += preg_match_all("/'userEmail'\s*=>/", file_get_contents($file));
        }
        $this->assertGreaterThan(0, $found, 'Expected the userEmail REST field to be sent');
        $this->assertContains('email', array_keys($this->metadata()['external_location:skilland']['fields']));
    }

    public function test_the_moodle_user_id_sent_on_course_creation_is_declared_as_userid(): void {
        $source = file_get_contents(__DIR__ . '/../../src/locallib.php');
        $this->assertMatchesRegularExpression("/\\\$body\\['moodleUserId'\\]\\s*=/", $source,
            'Expected mod_skilland_create_course() to send moodleUserId');
        $this->assertContains('userid', array_keys($this->metadata()['external_location:skilland']['fields']));
    }

    // ---------------------------------------------------------------
    // Contexts and users
    // ---------------------------------------------------------------

    public function test_get_contexts_for_userid_returns_the_activities_with_the_users_rows(): void {
        $this->assertSame([self::CTX_ACTIVITY_1], provider::get_contexts_for_userid(self::USER_A)->get_contextids());

        $contextids = provider::get_contexts_for_userid(self::USER_B)->get_contextids();
        sort($contextids);
        $this->assertSame([self::CTX_ACTIVITY_1, self::CTX_ACTIVITY_2], $contextids);

        $this->assertSame([], provider::get_contexts_for_userid(self::USER_NONE)->get_contextids());
    }

    public function test_get_contexts_for_userid_joins_module_contexts_of_skilland_activities(): void {
        provider::get_contexts_for_userid(self::USER_A);
        $call = $this->db->get_calls_for('get_records_sql')[0];
        $this->assertStringContainsString('{skilland_progress}', $call['sql']);
        $this->assertStringContainsString('{modules}', $call['sql']);
        $this->assertSame(CONTEXT_MODULE, $call['params']['contextlevel']);
        $this->assertSame('skilland', $call['params']['modname']);
        $this->assertSame(self::USER_A, $call['params']['userid']);
    }

    public function test_get_users_in_context_returns_the_activitys_users(): void {
        $userlist = new userlist(\context::instance_by_id(self::CTX_ACTIVITY_1), 'mod_skilland');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        sort($userids);
        $this->assertSame([self::USER_A, self::USER_B], $userids);

        $userlist = new userlist(\context::instance_by_id(self::CTX_ACTIVITY_2), 'mod_skilland');
        provider::get_users_in_context($userlist);
        $this->assertSame([self::USER_B], $userlist->get_userids());
    }

    public function test_get_users_in_context_ignores_a_non_module_context(): void {
        $userlist = new userlist(\context::instance_by_id(self::CTX_COURSE), 'mod_skilland');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());
    }

    // ---------------------------------------------------------------
    // Export
    // ---------------------------------------------------------------

    public function test_export_user_data_exports_only_the_users_lessons_of_the_approved_activity(): void {
        provider::export_user_data(new approved_contextlist((object) ['id' => self::USER_A], 'mod_skilland',
            [self::CTX_ACTIVITY_1]));

        $exports = $GLOBALS['_test_privacy_exports'];
        $this->assertCount(1, $exports);
        $this->assertSame(self::CTX_ACTIVITY_1, $exports[0]['contextid']);
        $this->assertSame(['privacy:path:progress'], $exports[0]['subcontext']);

        $lessons = $exports[0]['data']->lessons;
        $this->assertCount(2, $lessons);
        $bytitle = [];
        foreach ($lessons as $lesson) {
            $bytitle[$lesson->lesson] = $lesson;
        }
        $this->assertSame(['Lesson one', 'Lesson two'], array_keys($bytitle));
        $this->assertSame('passed', $bytitle['Lesson one']->status);
        $this->assertSame(90.0, $bytitle['Lesson one']->score);
        $this->assertSame(userdate(1758800000), $bytitle['Lesson one']->timemodified);
        $this->assertSame('incomplete', $bytitle['Lesson two']->status);
        $this->assertNull($bytitle['Lesson two']->score);
    }

    public function test_export_user_data_writes_nothing_for_a_course_context(): void {
        provider::export_user_data(new approved_contextlist((object) ['id' => self::USER_A], 'mod_skilland',
            [self::CTX_COURSE]));
        $this->assertSame([], $GLOBALS['_test_privacy_exports']);
    }

    // ---------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------

    private function progress_ids(): array {
        $ids = array_map('intval', array_keys($this->db->get_records('skilland_progress')));
        sort($ids);
        return $ids;
    }

    public function test_delete_data_for_all_users_in_context_removes_only_that_activitys_rows(): void {
        provider::delete_data_for_all_users_in_context(\context::instance_by_id(self::CTX_ACTIVITY_1));
        $this->assertSame([4], $this->progress_ids());
    }

    public function test_delete_data_for_all_users_in_context_ignores_a_course_context(): void {
        provider::delete_data_for_all_users_in_context(\context::instance_by_id(self::CTX_COURSE));
        $this->assertSame([1, 2, 3, 4], $this->progress_ids());
    }

    public function test_delete_data_for_user_removes_only_the_users_rows_in_approved_contexts(): void {
        provider::delete_data_for_user(new approved_contextlist((object) ['id' => self::USER_B], 'mod_skilland',
            [self::CTX_ACTIVITY_2, self::CTX_COURSE]));
        $this->assertSame([1, 2, 3], $this->progress_ids());

        provider::delete_data_for_user(new approved_contextlist((object) ['id' => self::USER_A], 'mod_skilland',
            [self::CTX_ACTIVITY_1]));
        $this->assertSame([3], $this->progress_ids());
    }

    public function test_delete_data_for_users_removes_only_the_listed_users(): void {
        provider::delete_data_for_users(new approved_userlist(\context::instance_by_id(self::CTX_ACTIVITY_1),
            'mod_skilland', [self::USER_A]));
        $this->assertSame([3, 4], $this->progress_ids());
    }

    public function test_delete_data_for_users_ignores_a_course_context_and_an_empty_list(): void {
        provider::delete_data_for_users(new approved_userlist(\context::instance_by_id(self::CTX_COURSE),
            'mod_skilland', [self::USER_A, self::USER_B]));
        provider::delete_data_for_users(new approved_userlist(\context::instance_by_id(self::CTX_ACTIVITY_1),
            'mod_skilland', []));
        $this->assertSame([1, 2, 3, 4], $this->progress_ids());
    }
}
