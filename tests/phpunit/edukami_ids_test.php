<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;
use mod_skilland\local\edukami_ids;

/**
 * The PHP port of Skilland's edukami-ids.ts must give the ids the migration gave (SKL-997).
 *
 * The expected values were computed with packages/db/src/edukami-ids.ts.
 */
class edukami_ids_test extends TestCase {

    private const SKILL = '64a1f0c2b3d4e5f601234567';
    private const TOPIC = '64a1f0c2b3d4e5f601234568';
    private const LESSON = '64a1f0c2b3d4e5f601234569';
    private const LESSON2 = '64a1f0c2b3d4e5f60123456a';

    public function test_skill_id_matches_the_migration(): void {
        $this->assertSame('235635ec-561e-574b-a94f-67227d828e7d', edukami_ids::skill_id(self::SKILL));
        $this->assertSame('235635ec-561e-574b-a94f-67227d828e7d', edukami_ids::skill_id(strtoupper(self::SKILL)));
    }

    public function test_topic_candidates_are_scoped_first_then_unscoped(): void {
        $this->assertSame(
            ['3f4cb633-7b80-59d7-8200-63eba014e64a', '7f7b6b3a-3ea7-5662-9d57-59c65b1acabc'],
            edukami_ids::topic_id_candidates(self::TOPIC, strtoupper(self::SKILL))
        );
        $this->assertSame(['7f7b6b3a-3ea7-5662-9d57-59c65b1acabc'], edukami_ids::topic_id_candidates(self::TOPIC));
    }

    public function test_content_candidates_are_scoped_first_then_unscoped(): void {
        $this->assertSame(
            ['ee8d7696-f1b1-50a4-a4ce-25ac633ce659', '19bfe8cf-b5f3-5a6f-9ae8-2f25ee930dce'],
            edukami_ids::content_id_candidates(self::LESSON, self::SKILL)
        );
        $this->assertSame(
            ['38af3472-cec4-5533-ae6f-396927834284', 'f24ba4dc-fd8e-540c-b26e-31f46e0d5f36'],
            edukami_ids::content_id_candidates(strtoupper(self::LESSON2), self::SKILL)
        );
        $this->assertSame(['f24ba4dc-fd8e-540c-b26e-31f46e0d5f36'], edukami_ids::content_id_candidates(self::LESSON2, ''));
    }

    public function test_mongo_uuid_has_the_version_5_layout(): void {
        $uuid = edukami_ids::mongo_uuid('topics', self::TOPIC);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
    }

    public function test_is_object_id(): void {
        $this->assertTrue(edukami_ids::is_object_id(self::SKILL));
        $this->assertTrue(edukami_ids::is_object_id(strtoupper(self::SKILL)));
        $this->assertFalse(edukami_ids::is_object_id('235635ec-561e-574b-a94f-67227d828e7d'));
        $this->assertFalse(edukami_ids::is_object_id(self::SKILL . '0'));
        $this->assertFalse(edukami_ids::is_object_id(''));
    }
}
