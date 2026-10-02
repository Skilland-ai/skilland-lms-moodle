<?php

namespace mod_skilland\tests;

use mod_skilland\task\backfill_lesson_positions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/stubs/test_doubles.php';

/**
 * The ad-hoc task the 2026100209 upgrade queues (SKL-694) stores each lesson's Skilland position
 * from the topic's lesson list, and an activity whose fetch fails keeps 0.
 */
class task_backfill_lesson_positions_test extends TestCase {

    /** @var \FakeDatabase */
    private $db;

    protected function setUp(): void {
        parent::setUp();
        \core\di::reset_container();
        $this->db = new \FakeDatabase();
        $GLOBALS['DB'] = $this->db;
        $GLOBALS['_test_debug_messages'] = [];
        $GLOBALS['_test_mtrace'] = [];
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [
            'orgid' => 'org1',
            'apikey' => 'key1',
            'graphql_endpoint' => 'https://localhost:8000',
        ];
        \mod_skilland\logger::reset_cache();
        \core\task\manager::$queued = [];
    }

    protected function tearDown(): void {
        \core\di::reset_container();
        $GLOBALS['_test_plugin_config'] = [];
        parent::tearDown();
    }

    private function seed_activity(): void {
        $this->db->seed('skilland', [(object) ['id' => 7, 'skilland_topicid' => 'topic-1']]);
        $this->db->seed('skilland_lesson', [
            (object) ['id' => 1, 'skillandid' => 7, 'skilland_lessonid' => 'l1', 'orderindex' => 1, 'skillandposition' => 0],
            (object) ['id' => 2, 'skillandid' => 7, 'skilland_lessonid' => 'l3', 'orderindex' => 2, 'skillandposition' => 0],
            (object) ['id' => 3, 'skillandid' => 7, 'skilland_lessonid' => 'l4', 'orderindex' => 3, 'skillandposition' => 0],
        ]);
    }

    private function contents(): array {
        $contents = [];
        foreach (['l1', 'l2', 'l3', 'l4'] as $i => $id) {
            $contents[] = ['id' => $id, 'name' => 'Lesson ' . ($i + 1), 'type' => 'lesson', 'content' => '<p>x</p>'];
        }
        return ['contents' => $contents];
    }

    private function positions(): array {
        $positions = [];
        foreach ($this->db->get_records('skilland_lesson', ['skillandid' => 7]) as $lesson) {
            $positions[$lesson->skilland_lessonid] = $lesson->skillandposition;
        }
        ksort($positions);
        return $positions;
    }

    public function test_get_name_is_a_language_string(): void {
        $this->assertSame('task_backfill_lesson_positions', (new backfill_lesson_positions())->get_name());
    }

    public function test_sets_positions_from_the_topic_lessons(): void {
        $this->seed_activity();
        $fake = \fake_api_client::install();
        $fake->respond_rest('contents', $this->contents());

        (new backfill_lesson_positions())->execute();

        $this->assertSame(['l1' => 1, 'l3' => 3, 'l4' => 4], $this->positions());
        $this->assertSame(['/topics/topic-1/contents'], array_map(
            fn($p) => substr($p, strrpos($p, '/topics/')), $fake->restcalls));
    }

    public function test_an_api_error_leaves_zero_and_logs_the_activity_id_only(): void {
        $this->seed_activity();
        \fake_api_client::install()->respond_rest('contents',
            new \mod_skilland\rest_exception('boom teacher@example.com', 500));

        (new backfill_lesson_positions())->execute();

        $this->assertSame(['l1' => 0, 'l3' => 0, 'l4' => 0], $this->positions());
        $log = implode("\n", $GLOBALS['_test_mtrace']);
        $this->assertStringContainsString('activity 7', $log);
        $this->assertStringNotContainsString('@', $log);
        $this->assertStringNotContainsString('boom', $log);
    }

    public function test_does_nothing_when_the_plugin_is_not_configured(): void {
        $this->seed_activity();
        $GLOBALS['_test_plugin_config']['mod_skilland'] = (object) [];
        $fake = \fake_api_client::install();

        (new backfill_lesson_positions())->execute();

        $this->assertSame([], $fake->restcalls);
        $this->assertSame(['l1' => 0, 'l3' => 0, 'l4' => 0], $this->positions());
    }

    public function test_upgrade_step_adds_the_field_and_queues_the_task(): void {
        $source = file_get_contents(__DIR__ . '/../../src/db/upgrade.php');

        $this->assertMatchesRegularExpression(
            "/new xmldb_field\('skillandposition', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0'/",
            $source
        );
        $this->assertStringContainsString(
            '\core\task\manager::queue_adhoc_task(new \mod_skilland\task\backfill_lesson_positions(), true);',
            $source
        );
    }
}
