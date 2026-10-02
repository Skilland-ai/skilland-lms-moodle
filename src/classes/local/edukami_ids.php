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

namespace mod_skilland\local;

/**
 * The ids the Edukami migration gave skills, topics and lessons, computed from their Edukami ObjectIds.
 *
 * A port of Skilland's packages/db/src/edukami-ids.ts: the migration derives every id from a SHA-1 of
 * "edukami:<collection>:<ObjectId>", laid out as a version 5 UUID. A topic or lesson shared by several
 * skills keeps the unscoped id in the first skill and gets a scoped one ("<skillOid>:<oid>") in the
 * others, so both are candidates and the caller keeps the one Skilland lists.
 *
 * @package    mod_skilland
 * @copyright  2026 Skilland
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edukami_ids {
    /**
     * Deterministic UUID (version 5 layout) derived from a Mongo collection and id.
     *
     * @param string $collection Mongo collection: skills, topics or contents.
     * @param string $id The id hashed, as given.
     * @return string The UUID.
     */
    public static function mongo_uuid(string $collection, string $id): string {
        $hash = sha1('edukami:' . $collection . ':' . $id);
        return implode('-', [
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            '5' . substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3f) | 0x80) . substr($hash, 18, 2),
            substr($hash, 20, 12),
        ]);
    }

    /**
     * Whether the whole string is a 24-hex Mongo ObjectId.
     *
     * @param string $value
     * @return bool
     */
    public static function is_object_id(string $value): bool {
        return preg_match('/^[0-9a-f]{24}$/i', $value) === 1;
    }

    /**
     * Id the migration gave the skill with this Edukami ObjectId.
     *
     * @param string $skilloid Edukami skill ObjectId.
     * @return string
     */
    public static function skill_id(string $skilloid): string {
        return self::mongo_uuid('skills', strtolower($skilloid));
    }

    /**
     * Ids the migration may have given a topic: scoped first when the skill is known, then unscoped.
     *
     * @param string $topicoid Edukami topic ObjectId.
     * @param string|null $skilloid Edukami ObjectId of the skill the topic is read in.
     * @return string[]
     */
    public static function topic_id_candidates(string $topicoid, ?string $skilloid = null): array {
        return self::candidates('topics', $topicoid, $skilloid);
    }

    /**
     * Ids the migration may have given a lesson (or any content): same rule as topics.
     *
     * @param string $contentoid Edukami content ObjectId.
     * @param string|null $skilloid Edukami ObjectId of the skill the content is read in.
     * @return string[]
     */
    public static function content_id_candidates(string $contentoid, ?string $skilloid = null): array {
        return self::candidates('contents', $contentoid, $skilloid);
    }

    /**
     * Scoped and unscoped candidate ids of a topic or content.
     *
     * @param string $collection Mongo collection.
     * @param string $oid Edukami ObjectId.
     * @param string|null $skilloid Edukami skill ObjectId, or null when unknown.
     * @return string[]
     */
    private static function candidates(string $collection, string $oid, ?string $skilloid): array {
        $oid = strtolower($oid);
        $unscoped = self::mongo_uuid($collection, $oid);
        if ($skilloid === null || $skilloid === '') {
            return [$unscoped];
        }
        return [self::mongo_uuid($collection, strtolower($skilloid) . ':' . $oid), $unscoped];
    }
}
