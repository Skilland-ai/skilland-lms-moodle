<?php

namespace mod_skilland\tests;

use PHPUnit\Framework\TestCase;

/**
 * The SSO handoff opens the skill through /skills-studio/, which resolves Edukami ObjectIds
 * as well as UUIDs (SKL-998).
 */
class studio_redirect_path_test extends TestCase {

    public function test_uuid_goes_through_the_studio_route(): void {
        $uuid = '3f0c2a64-5b8e-4c1a-9d57-0a1b2c3d4e5f';
        $this->assertSame('/skills-studio/' . $uuid, skilland_studio_redirect_path($uuid));
    }

    public function test_edukami_objectid_goes_through_the_studio_route(): void {
        $this->assertSame(
            '/skills-studio/507f1f77bcf86cd799439011',
            skilland_studio_redirect_path('507f1f77bcf86cd799439011')
        );
    }

    public function test_topic_is_kept(): void {
        $this->assertSame(
            '/skills-studio/507f1f77bcf86cd799439011/topics/507f191e810c19729de860ea',
            skilland_studio_redirect_path('507f1f77bcf86cd799439011', '507f191e810c19729de860ea')
        );
    }

    public function test_ids_are_url_encoded(): void {
        $this->assertSame('/skills-studio/a%2Fb/topics/c%3Fd', skilland_studio_redirect_path('a/b', 'c?d'));
    }

    public function test_no_mapped_skill_lands_on_the_list(): void {
        $this->assertSame('/skills', skilland_studio_redirect_path(''));
        $this->assertSame('/skills', skilland_studio_redirect_path(false, 'x'));
    }
}
