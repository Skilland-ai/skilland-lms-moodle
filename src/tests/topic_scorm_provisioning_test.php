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

use mod_skilland\local\testing\fixture_api_client;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/skilland_testcase.php');

/**
 * Provisioning and rebuilding the topic SCORM against real mod_scorm, through the fixture API.
 *
 * @package    mod_skilland
 * @category   test
 * @copyright  2026 SkilLand
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers ::skilland_provision_topic_scorm
 * @covers ::skilland_update_topic_scorm
 * @covers ::skilland_build_topic_scorm
 * @covers ::skilland_link_topic_scorm
 * @covers ::skilland_download_topic_scorm_package
 * @covers \mod_skilland\local\package_signature
 */
final class topic_scorm_provisioning_test extends skilland_testcase {
    public function test_provision_creates_a_stealth_scorm_and_maps_every_lesson(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);

        $cmid = $this->provision($skilland);

        $this->assertSame([$cmid], $this->scorm_cmids($course->id));
        $cm = get_coursemodule_from_id('scorm', $cmid, $course->id, false, MUST_EXIST);
        $this->assertSame(1, (int) $cm->visible);
        $this->assertSame(0, (int) $cm->visibleoncoursepage);
        $this->assertSame('skilland_topic_' . $skilland->id, $cm->idnumber);

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertSame($cmid, (int) $record->scormcmid);
        $this->assertSame('hash-v1', $record->snapshotid);
        $this->assertNotEmpty($record->scorm_provisioned);
        $this->assertSame(
            ['lesson-1' => 'sco-lesson-1', 'lesson-2' => 'sco-lesson-2'],
            json_decode($record->scomappings, true)
        );

        $scoes = $DB->get_records_menu('scorm_scoes', ['scorm' => $cm->instance], '', 'identifier, id');
        foreach ($this->lessons($skilland->id) as $lessonid => $lesson) {
            $this->assertSame('sco-' . $lessonid, $lesson->sco_identifier);
            $this->assertEquals($scoes['sco-' . $lessonid], $lesson->scoid);
        }

        $this->assertSame(1, $this->client->count_calls('GetTopicScorm'));
        $this->assertSame(1, $this->client->count_calls('TopicScormHash'));
        // Both lookups went through the REST routes, not the legacy GraphQL queries.
        $paths = array_column($this->client->calls, 'path');
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression('#^/api/moodle/topics/[^/]+/scorm(-hash)?$#', $path);
        }
        $this->assertCount(1, $this->client->downloads);
        $this->assertSame('https://packages.skilland.test/topic-1.zip', $this->client->downloads[0]['url']);
    }

    public function test_provision_is_idempotent(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);

        $first = $this->provision($skilland);
        $second = $this->provision($skilland);

        $this->assertSame($first, $second);
        $this->assertSame([$first], $this->scorm_cmids($course->id));
        $this->assertCount(1, $this->client->downloads);
    }

    public function test_provision_rebuilds_when_the_linked_scorm_is_gone(): void {
        global $DB;
        // The course_module_deleted observer is external: it only runs outside the test transaction.
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $first = $this->provision($skilland);

        // Delete behind the plugin's back: the observer unlinks, and a new provision builds again.
        course_delete_module($first);
        $this->take_debugging();
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));

        $second = $this->provision($skilland);

        $this->assertNotSame($first, $second);
        $this->assertSame([$second], $this->scorm_cmids($course->id));
    }

    public function test_provision_rolls_back_the_module_when_a_mapped_sco_is_missing(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['mappings' => [
            ['lessonId' => 'lesson-1', 'scoId' => 'sco-lesson-1'],
            ['lessonId' => 'lesson-2', 'scoId' => 'sco-not-in-package'],
        ]]);

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail when the package lacks a mapped SCO');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_parse_failed', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNull($record->scormcmid);
        $this->assertNull($record->snapshotid);
        foreach ($this->lessons($skilland->id) as $lesson) {
            $this->assertNull($lesson->scoid);
        }
    }

    public function test_provision_creates_nothing_when_the_download_fails(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->fail_download(new \moodle_exception('error_scorm_download_failed', 'mod_skilland'));

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail when the download fails');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_download_failed', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
        $this->assertNull($DB->get_field('skilland', 'scormcmid', ['id' => $skilland->id]));
    }

    public function test_provision_rejects_a_package_whose_hash_does_not_match(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['packageHash' => 'sha256:' . str_repeat('0', 64)]);

        try {
            $this->provision($skilland);
            $this->fail('Provisioning must fail on a hash mismatch');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_scorm_hash_mismatch', $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids($course->id));
    }

    /**
     * Assert that provisioning $skilland fails with $errorcode and imports nothing.
     *
     * @param \stdClass $skilland
     * @param string $errorcode
     */
    private function assert_provision_refused(\stdClass $skilland, string $errorcode): void {
        global $DB;

        $filesbefore = $DB->count_records('files', ['component' => 'mod_scorm']);
        try {
            $this->provision($skilland);
            $this->fail('Provisioning must refuse the package with ' . $errorcode);
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([], $this->scorm_cmids((int) $skilland->course));
        $this->assertSame(
            $filesbefore,
            $DB->count_records('files', ['component' => 'mod_scorm']),
            'The package never reached the file API or the SCORM parser'
        );
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNull($record->scormcmid);
    }

    public function test_the_fixture_package_is_signed_and_verified(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);

        $info = mod_skilland_fetch_topic_scorm('topic-1');

        $this->assertSame(fixture_api_client::FIXTURE_KEY_ID, $info['keyId']);
        $this->assertSame($this->client->package_hash(), $info['packageHash']);
        $this->assertSame(64, strlen(base64_decode($info['signature'], true)));
        $this->assertGreaterThan(0, $this->provision($skilland));
    }

    public function test_a_tampered_package_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->tamper_download(function (string $bytes): string {
            $bytes[40] = chr(ord($bytes[40]) ^ 0x01);
            return $bytes;
        });

        $this->assert_provision_refused($skilland, 'error_scorm_hash_mismatch');
    }

    public function test_a_tampered_package_announced_with_its_own_hash_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $tamper = function (string $bytes): string {
            return $bytes . 'appended';
        };
        $this->client->tamper_download($tamper);
        $path = fixture_api_client::build_package(fixture_api_client::fixtures_dir() . '/scorm');
        $tamperedhash = hash('sha256', $tamper((string) file_get_contents($path)));
        @unlink($path);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['packageHash' => $tamperedhash]);

        $this->assert_provision_refused($skilland, 'error_scorm_signature_invalid');
    }

    public function test_a_package_signed_by_an_unknown_key_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->sign_with('someone-else', sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()));

        $this->assert_provision_refused($skilland, 'error_scorm_signature_unknown_key');
    }

    public function test_a_package_signed_by_another_key_under_the_trusted_id_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->sign_with(
            fixture_api_client::FIXTURE_KEY_ID,
            sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())
        );

        $this->assert_provision_refused($skilland, 'error_scorm_signature_invalid');
    }

    public function test_a_key_trusted_through_the_admin_setting_verifies(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $keypair = sodium_crypto_sign_keypair();
        set_config(
            'signingkeys',
            'rotated-2026:' . base64_encode(sodium_crypto_sign_publickey($keypair)),
            'mod_skilland'
        );
        $this->client->sign_with('rotated-2026', sodium_crypto_sign_secretkey($keypair));

        $this->assertGreaterThan(0, $this->provision($skilland));
    }

    public function test_an_unsigned_package_is_refused_before_downloading(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->sign_with(null);

        $this->assert_provision_refused($skilland, 'error_scorm_signature_missing');
        $this->assertSame([], $this->client->downloads);
    }

    public function test_a_malformed_signature_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->client->merge_response('GetTopicScorm', 'topicScorm', ['signature' => 'not*base64',
            'keyId' => fixture_api_client::FIXTURE_KEY_ID]);

        $this->assert_provision_refused($skilland, 'error_scorm_signature_malformed');
    }

    public function test_a_signature_replayed_from_another_topic_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course, ['skilland_topicid' => 'topic-2']);
        $client = $this->client;
        $client->set_response('GetTopicScorm', function () use ($client): array {
            $body = fixture_api_client::default_responses()['GetTopicScorm']['topicScorm'];
            // A genuine signature, but for topic-1's package.
            return ['topicScorm' => fixture_api_client::sign_scorm(
                'topic-1',
                $body,
                $client->package_hash(),
                fixture_api_client::FIXTURE_KEY_ID,
                sodium_crypto_sign_secretkey(fixture_api_client::fixture_keypair())
            )];
        });

        $this->assert_provision_refused($skilland, 'error_scorm_signature_invalid');
    }

    public function test_a_package_reached_through_the_graphql_fallback_is_refused(): void {
        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        // The REST route is gone (404), so the legacy GraphQL topicScorm answers: it is never signed.
        $client = new class extends fixture_api_client {
            /**
             * Fail the scorm route with a 404, answer the others from the fixtures.
             *
             * @param string $path Route path.
             * @return array
             */
            public function rest_get(string $path): array {
                if (str_ends_with($path, '/scorm')) {
                    $this->calls[] = ['operation' => 'rest-scorm', 'variables' => [], 'path' => $path];
                    throw new rest_exception('error_scorm_fetch_failed', 404);
                }
                return parent::rest_get($path);
            }
        };
        \core\di::set(local\api_client::class, $client);
        $this->client = $client;

        $this->assert_provision_refused($skilland, 'error_scorm_signature_missing');
        $this->assertSame(1, $client->count_calls('GetTopicScorm'), 'The GraphQL fallback answered');
        $this->assertSame([], $client->downloads);
    }

    public function test_a_successful_update_clears_the_update_notice(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $DB->set_field('skilland', 'updateavailable', 'hash-v2', ['id' => $skilland->id]);
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $this->update($skilland);

        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertNull($record->updateavailable);
        $this->assertSame('hash-v2', $record->snapshotid);
    }

    public function test_update_builds_the_new_scorm_before_deleting_the_old_one(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $oldcmid = $this->provision($skilland);
        $oldscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $oldcmid]);
        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);

        $sink = $this->redirectEvents();
        $newcmid = $this->update($skilland);
        $events = $sink->get_events();
        $sink->close();

        $this->assertNotSame($oldcmid, $newcmid);
        $order = [];
        foreach ($events as $event) {
            if ($event instanceof \core\event\course_module_created && (int) $event->objectid === $newcmid) {
                $order[] = 'created new';
            }
            if ($event instanceof \core\event\course_module_deleted && (int) $event->objectid === $oldcmid) {
                $order[] = 'deleted old';
            }
        }
        $this->assertSame(['created new', 'deleted old'], $order);

        $this->assertSame([$newcmid], $this->scorm_cmids($course->id));
        $this->assertFalse($DB->record_exists('scorm', ['id' => $oldscormid]));
        $record = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $this->assertSame($newcmid, (int) $record->scormcmid);
        $this->assertSame('hash-v2', $record->snapshotid);

        $newscormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $newcmid]);
        foreach ($this->lessons($skilland->id) as $lesson) {
            $this->assertTrue($DB->record_exists('scorm_scoes', ['id' => $lesson->scoid, 'scorm' => $newscormid]));
        }
    }

    public function test_update_stamps_each_lesson_with_the_api_version(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $skilland = $this->create_activity($course);
        $this->provision($skilland);
        $DB->set_field('skilland_lesson', 'updatedat', 1, ['skillandid' => $skilland->id]);

        $this->update($skilland);

        $lessons = $this->lessons($skilland->id);
        $this->assertEquals(strtotime('2026-01-01T10:00:00Z'), $lessons['lesson-1']->updatedat);
        $this->assertEquals(strtotime('2026-01-02T10:00:00Z'), $lessons['lesson-2']->updatedat);
    }

    /**
     * Failures of a rebuild that must leave the installed SCORM as it was.
     *
     * @return array
     */
    public static function failed_update_provider(): array {
        return [
            'download fails' => ['download', 'error_scorm_download_failed'],
            'package lacks a mapped SCO' => ['mapping', 'error_scorm_parse_failed'],
            'package info unavailable' => ['info', 'error_scorm_not_available'],
            'package tampered with' => ['tamper', 'error_scorm_hash_mismatch'],
            'package unsigned' => ['unsigned', 'error_scorm_signature_missing'],
        ];
    }

    /**
     * A failed update leaves the old SCORM package and its attempts untouched.
     *
     * @dataProvider failed_update_provider
     * @param string $failure Which step fails.
     * @param string $errorcode Expected error code.
     */
    public function test_failed_update_keeps_the_old_scorm_and_its_attempts(string $failure, string $errorcode): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $course = $this->getDataGenerator()->create_course();
        $student = $this->enrol($course, 'student');
        $skilland = $this->create_activity($course);
        $oldcmid = $this->provision($skilland);
        $before = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        $lessonsbefore = $this->lessons($skilland->id);

        $scormid = (int) $DB->get_field('course_modules', 'instance', ['id' => $oldcmid]);
        scorm_insert_track(
            $student->id,
            $scormid,
            $lessonsbefore['lesson-1']->scoid,
            1,
            'cmi.core.lesson_status',
            'incomplete'
        );
        $this->take_debugging();

        $this->client->merge_response('TopicScormHash', 'topicScormHash', ['contentHash' => 'hash-v2']);
        if ($failure === 'download') {
            $this->client->fail_download(new \moodle_exception('error_scorm_download_failed', 'mod_skilland'));
        } else if ($failure === 'tamper') {
            $this->client->tamper_download(fn(string $bytes): string => $bytes . 'x');
        } else if ($failure === 'unsigned') {
            $this->client->sign_with(null);
        } else if ($failure === 'mapping') {
            $this->client->merge_response('GetTopicScorm', 'topicScorm', ['mappings' => [
                ['lessonId' => 'lesson-1', 'scoId' => 'sco-gone'],
            ]]);
        } else {
            $this->client->set_response('GetTopicScorm', ['topicScorm' => null]);
        }

        try {
            $this->update($skilland);
            $this->fail('The update must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->take_debugging();

        $this->assertSame([$oldcmid], $this->scorm_cmids($course->id));
        $after = $DB->get_record('skilland', ['id' => $skilland->id], '*', MUST_EXIST);
        foreach (['scormcmid', 'snapshotid', 'scomappings', 'scorm_provisioned'] as $field) {
            $this->assertEquals($before->$field, $after->$field, $field);
        }
        foreach ($this->lessons($skilland->id) as $lessonid => $lesson) {
            $this->assertEquals($lessonsbefore[$lessonid]->scoid, $lesson->scoid);
            $this->assertEquals($lessonsbefore[$lessonid]->updatedat, $lesson->updatedat);
        }
        $this->assertTrue($DB->record_exists('scorm_attempt', ['scormid' => $scormid, 'userid' => $student->id]));
    }
}
