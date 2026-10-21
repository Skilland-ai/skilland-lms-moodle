<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * SKL-699: the Moodle App view (db/mobile.php → output\mobile::mobile_course_view()) renders the
 * same lesson list as view.php, with playable lessons linking to the app's native SCORM player
 * and the rest shown as unavailable, and logs the view through mod_skilland_view_skilland.
 */
class mobile_view_test extends TestCase {

    private const COURSE_ID = 3;
    private const CM_ID = 2;
    private const SCORM_CM_ID = 40;
    private const SKILLAND_ID = 7;

    private const GLOBALS_TO_RESET = [
        '_test_call_order', '_test_login_denied_ids', '_test_denied_capabilities', '_test_get_coursemodule_from_id',
        '_test_get_coursemodule_from_instance', '_test_cm_from_db', '_test_events', '_test_completion_viewed',
        '_test_format_module_intro', '_test_completion_updates', '_test_grade_updates',
    ];

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $this->db = new \FakeDatabase();
        $this->db->seed('skilland', [
            (object) ['id' => self::SKILLAND_ID, 'course' => self::COURSE_ID, 'name' => 'T1 - Testing',
                'intro' => '<p>Why test</p>', 'introformat' => 1, 'scormcmid' => self::SCORM_CM_ID,
                'topic_orderindex' => 1, 'hidelabels' => 0, 'snapshotcreatedat' => 0, 'grade' => 0],
        ]);
        $this->db->seed('course_modules', [
            (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID, 'course' => self::COURSE_ID,
                'section' => 12, 'modname' => 'skilland'],
            (object) ['id' => self::SCORM_CM_ID, 'instance' => 5, 'course' => self::COURSE_ID, 'section' => 12,
                'modname' => 'scorm'],
        ]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 10, 'skillandid' => self::SKILLAND_ID, 'title' => 'First', 'scoid' => 110,
                'orderindex' => 1, 'visible' => 1, 'updatedat' => 0],
            (object) ['id' => 11, 'skillandid' => self::SKILLAND_ID, 'title' => 'Second', 'scoid' => null,
                'orderindex' => 2, 'visible' => 1, 'updatedat' => 0],
            (object) ['id' => 12, 'skillandid' => self::SKILLAND_ID, 'title' => 'Hidden', 'scoid' => 112,
                'orderindex' => 3, 'visible' => 0, 'updatedat' => 0],
        ]);
        $GLOBALS['DB'] = $this->db;
        // Both course modules (the activity and its SCORM) resolve against the fake course_modules rows.
        $GLOBALS['_test_cm_from_db'] = true;
        $GLOBALS['USER'] = (object) ['id' => 50];
        $GLOBALS['PAGE'] = new \test_moodle_page();
        $GLOBALS['OUTPUT'] = $GLOBALS['PAGE']->get_renderer('mod_skilland');
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_plugin_config'] = [];
        $GLOBALS['_test_denied_capabilities'] = [];
        $GLOBALS['_test_call_order'] = [];
        \mod_skilland\logger::reset_cache();
    }

    protected function tearDown(): void {
        foreach (self::GLOBALS_TO_RESET as $name) {
            unset($GLOBALS[$name]);
        }
        $GLOBALS['OUTPUT'] = new \stdClass();
        parent::tearDown();
    }

    private function view(): array {
        return \mod_skilland\output\mobile::mobile_course_view(['cmid' => self::CM_ID, 'courseid' => self::COURSE_ID,
            'userid' => 50]);
    }

    private function html(array $result): string {
        $this->assertCount(1, $result['templates']);
        $this->assertSame('main', $result['templates'][0]['id']);
        return $result['templates'][0]['html'];
    }

    // ---------------------------------------------------------------
    // The lesson list
    // ---------------------------------------------------------------

    public function test_lists_the_visible_lessons_with_app_player_links(): void {
        $html = $this->html($this->view());

        $this->assertStringContainsString('<h2>First</h2>', $html);
        $this->assertStringContainsString('<h2>Second</h2>', $html);
        $this->assertStringNotContainsString('Hidden', $html);
        // The playable lesson opens in the app's SCORM player: id=<SCORM cmid>, never cm= (SKL-699).
        $this->assertStringContainsString('<ion-item button="true" detail="true" class="ion-text-wrap" core-link capture="true" ' .
            'href="/mod/scorm/player.php?id=40&amp;scoid=110&amp;mode=normal&amp;newattempt=off&amp;courseid=3">', $html);
        $this->assertStringNotContainsString('cm=', $html);
        $this->assertStringNotContainsString('display=popup', $html);
        $this->assertStringNotContainsString('view.php?id=2&amp;play=', $html);
        $this->assertStringContainsString('<ion-badge slot="start" color="light">L1.1</ion-badge>', $html);
        $this->assertStringContainsString('ready_to_start', $html);
        $this->assertStringContainsString('<ion-label>lessons</ion-label>', $html);
    }

    public function test_a_lesson_without_a_sco_is_disabled_with_the_web_strings(): void {
        $html = $this->html($this->view());

        $this->assertSame(1, substr_count($html, '<ion-item class="ion-text-wrap" disabled="true">'));
        $disabled = substr($html, strpos($html, 'disabled="true"'));
        $this->assertStringContainsString('<h2>Second</h2>', $disabled);
        $this->assertStringContainsString('<p>not_yet_available</p>', $disabled);
        $this->assertStringContainsString('<p>not_yet_available_help</p>', $disabled);
        $this->assertStringNotContainsString('href=', $disabled);
    }

    public function test_the_summary_and_the_continue_button_use_app_links(): void {
        $this->db->seed('skilland_progress', [
            (object) ['id' => 1, 'skillandid' => self::SKILLAND_ID, 'lessonid' => 10, 'userid' => 50,
                'status' => 'completed', 'score' => '80.00000'],
        ]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('<core-progress-bar progress="100" a11yText="progress_bar_label">', $html);
        $this->assertStringContainsString('<ion-button expand="block" core-link capture="true" ' .
            'href="/mod/scorm/player.php?id=40&amp;scoid=110&amp;mode=normal&amp;newattempt=off&amp;courseid=3" ' .
            'data-mode="review">review_lessons</ion-button>', $html);
        $this->assertStringContainsString('<p>score: 80%</p>', $html);
        $this->assertStringContainsString('<strong>completed</strong>', $html, 'The target lesson is marked');
    }

    public function test_hidden_labels_drop_the_lesson_code_badges(): void {
        $this->db->set_field('skilland', 'hidelabels', 1, ['id' => self::SKILLAND_ID]);

        $html = $this->html($this->view());

        $this->assertStringNotContainsString('ion-badge', $html);
        $this->assertDoesNotMatchRegularExpression('/L\d+\.\d+/', $html);
    }

    public function test_shows_the_intro_and_the_last_updated_notice(): void {
        $this->db->set_field('skilland', 'snapshotcreatedat', 1700000000, ['id' => self::SKILLAND_ID]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('<core-format-text text="&lt;div class=&quot;intro&quot;&gt;&lt;p&gt;Why test&lt;/p&gt;' .
            '&lt;/div&gt;" component="mod_skilland" componentId="2"></core-format-text>', $html);
        $this->assertSame([['module' => 'skilland', 'cmid' => self::CM_ID, 'filter' => true]],
            $GLOBALS['_test_format_module_intro']);
        $this->assertStringContainsString('<ion-card-content>content_last_updated</ion-card-content>', $html);
    }

    public function test_titles_and_the_intro_cannot_carry_angular_syntax(): void {
        // The app compiles the template as Angular code: a title or intro must never interpolate.
        $this->db->set_field('skilland_lesson', 'title', '{{ 1 + 1 }}', ['id' => 10]);
        $this->db->set_field('skilland_lesson', 'title', '@let t = CoreSitesProvider.getCurrentSite().token;', ['id' => 11]);
        $this->db->set_field('skilland', 'intro', '<p>{{ x }} {count, plural, =1 {one}}</p>', ['id' => self::SKILLAND_ID]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('<h2>&#123;&#123; 1 + 1 &#125;&#125;</h2>', $html);
        $this->assertStringContainsString('<h2>&#64;let t = CoreSitesProvider.getCurrentSite().token;</h2>', $html);
        $this->assertStringContainsString('&lt;p&gt;&amp;#123;&amp;#123; x &amp;#125;&amp;#125; &amp;#123;count', $html);
        // Only the template's own markup is left: no brace or @ from user text.
        $this->assertStringNotContainsString('{', $html);
        $this->assertStringNotContainsString('}', $html);
        $this->assertStringNotContainsString('@', $html);
    }

    public function test_no_visible_lessons_shows_the_notice(): void {
        $this->db->delete_records('skilland_lesson', ['skillandid' => self::SKILLAND_ID]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('no_lessons_configured', $html);
        $this->assertStringNotContainsString('<ion-list>', $html);
        $this->assertStringNotContainsString('core-progress-bar', $html);
    }

    // ---------------------------------------------------------------
    // Unprovisioned activities
    // ---------------------------------------------------------------

    public function test_an_unprovisioned_activity_is_being_prepared(): void {
        $this->db->set_field('skilland', 'scormcmid', null, ['id' => self::SKILLAND_ID]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('<ion-card-content>content_being_prepared</ion-card-content>', $html);
        $this->assertStringNotContainsString('<ion-list>', $html);
        $this->assertStringNotContainsString('player.php', $html);
        $this->assertStringNotContainsString('no_lessons_configured', $html);
        $this->assertEmpty($this->db->get_calls_for('get_records'), 'No lessons or progress are read');
    }

    public function test_a_deleted_topic_scorm_makes_the_activity_unprovisioned_without_writing(): void {
        $this->db->delete_records('course_modules', ['id' => self::SCORM_CM_ID]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('content_being_prepared', $html);
        $this->assertStringNotContainsString('player.php', $html);
        $this->assertSame(self::SCORM_CM_ID, (int) $this->db->get_field('skilland', 'scormcmid', ['id' => self::SKILLAND_ID]));
        $this->assertEmpty($this->db->get_calls_for('update_record'));
        $this->assertEmpty($this->db->get_calls_for('set_field'));
    }

    // ---------------------------------------------------------------
    // The app contract: otherdata, the view log and no viewed event here
    // ---------------------------------------------------------------

    public function test_logs_the_view_through_the_web_service_from_javascript_not_here(): void {
        $result = $this->view();

        $this->assertSame(['skillandid' => self::SKILLAND_ID], $result['otherdata']);
        $this->assertSame([], $result['files']);
        $this->assertStringContainsString("this.CoreCourseLogHelperProvider.log(", $result['javascript']);
        $this->assertStringContainsString("'mod_skilland_view_skilland'", $result['javascript']);
        $this->assertStringContainsString('{skillandid: this.CONTENT_OTHERDATA.skillandid}', $result['javascript']);
        $this->assertStringContainsString("'mod_skilland'", $result['javascript']);
        $this->assertStringContainsString('this.module.id', $result['javascript']);
        // The view is a pure read the app may cache: the event comes from the web service only.
        $this->assertEmpty($GLOBALS['_test_events'] ?? []);
        $this->assertEmpty($GLOBALS['_test_completion_viewed'] ?? []);
    }

    public function test_the_progress_is_refreshed_from_the_tracks_like_the_web_view(): void {
        $this->db->get_manager()->set_table_exists('scorm_scoes_value', true);
        $this->db->get_manager()->set_table_exists('scorm_attempt', true);
        $GLOBALS['_test_get_coursemodule_from_instance'] = (object) ['id' => self::CM_ID, 'instance' => self::SKILLAND_ID,
            'course' => self::COURSE_ID];
        $this->db->set_records_sql_handler(fn() => [
            1 => (object) ['id' => 1, 'userid' => 50, 'attempt' => 1, 'scoid' => 110,
                'element' => 'cmi.core.lesson_status', 'value' => 'passed'],
        ]);

        $html = $this->html($this->view());

        $this->assertStringContainsString('<strong>completed</strong>', $html);
        $this->assertSame('passed', skilland_get_user_progress(self::SKILLAND_ID, 50)[10]['status']);
        $this->assertCount(1, $GLOBALS['_test_completion_updates'] ?? [], 'A changed store recomputes completion');
    }

    // ---------------------------------------------------------------
    // Access
    // ---------------------------------------------------------------

    public function test_requires_login_in_the_course_before_the_capability(): void {
        $GLOBALS['_test_login_denied_ids'] = [self::CM_ID];

        try {
            $this->view();
            $this->fail('require_login_exception expected');
        } catch (\require_login_exception $e) {
            $this->assertInstanceOf(\require_login_exception::class, $e);
        }

        $this->assertSame([['require_login', self::COURSE_ID, self::CM_ID]], $GLOBALS['_test_call_order']);
    }

    public function test_requires_the_view_capability(): void {
        $GLOBALS['_test_denied_capabilities'] = ['mod/skilland:view'];

        $this->expectException(\required_capability_exception::class);

        $this->view();
    }

    public function test_an_unknown_course_module_is_refused(): void {
        $this->expectException(\dml_missing_record_exception::class);

        \mod_skilland\output\mobile::mobile_course_view(['cmid' => 999, 'courseid' => self::COURSE_ID]);
    }
}
