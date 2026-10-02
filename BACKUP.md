# Skilland Moodle Plugin - Backup and Restore Logic

The Skilland Moodle plugin declares `FEATURE_BACKUP_MOODLE2` in `skilland_supports()`, so Moodle includes its activities in backups and offers them for restore, import, course copy and duplicate:
- **Course Backup/Restore**: Full course backups including Skilland activities and course-level mappings.
- **Course Copy**: Duplicating courses within the same site.
- **Import**: Importing activities from one course to another.
- **Duplicate**: Duplicating a single activity inside its course.

## How it Works

The backup implementation is located in `src/backup/moodle2/` and follows standard Moodle 2.0 backup architecture.

### Data Structures Backed Up
1.  **Activity Instance (`skilland` table)**:
    - All settings (Name, Intro, Auto-update, etc.).
    - SCORM association (`scormcmid`).
    - Skilland Topic ID and its position in the skill (`topic_orderindex`), which lesson numbering (`T4.1`, `T4.2`, …) is built from.
    - The package's lesson -> SCO identifier map (`scomappings`).
    - Snapshot metadata.

2.  **Course Mapping (`skilland_course_id` course custom field)**:
    - When backing up an activity, we also include the value of the *current course's* `skilland_course_id` custom field in the XML as `skilland_courseid` (`skilland_orgid` stays in the format, always empty). This ensures that if the activity is restored into a new course that hasn't been linked to Skilland yet, the link is established automatically.
    - The legacy `skilland_course` table was migrated into the custom field and dropped in 0.9.52-beta (SKL-689).

3.  **Selected Lessons (`skilland_lesson` table)**:
    - The list of lessons selected for the topic.
    - Visibility state and ordering.
    - SCO identifiers for SCORM navigation deep-linking.
    - The legacy per-lesson `scormcmid` column was dropped in 0.9.4-beta (SKL-661); restoring an older backup ignores it.

### Restore Process & Caveats

#### SCORM Association
The Skilland activity links a hidden SCORM activity (`scormcmid`) and each lesson links one of its SCOs (`skilland_lesson.scoid`). Both are ids of the source site, so they are mapped to the restored SCORM, and that mapping waits until every activity of the restore exists:

1. The structure step (`restore_skilland_stepslib.php`) stores `scormcmid` and every lesson's `scoid` exactly as they are in the backup. It resolves nothing, because the SCORM activity may come *after* the Skilland activity in the section and is then not restored yet.
2. `restore_skilland_activity_task::after_restore()`, which Moodle runs once all activities are restored, maps `scormcmid` through the restore's `course_module` mapping and checks that the course module exists in the target course. Each lesson's `scoid` is mapped through the SCORM restore's `scorm_sco` mapping, falling back to the SCO with the lesson's `sco_identifier` in the restored package; a lesson whose SCO cannot be found gets `scoid = null`.
3. Only when the SCORM is **not part of this restore** (the mapping finds nothing) is a new SCORM provisioned from the Skilland API into the activity's section, provided the activity has visible lessons. A restored SCORM is never replaced, so its learner attempts stay linked.

| Operation | SCORM in the backup | Result |
|-----------|---------------------|--------|
| Course backup/restore, course copy | Yes | Linked to the restored SCORM, whatever the section order |
| Import of both activities | Yes | Linked to the imported SCORM |
| Import of the Skilland activity alone | No | A new SCORM is provisioned from the API |
| Duplicate | No | A new SCORM is provisioned from the API; the original keeps its own |

If provisioning fails (API unreachable, no visible lessons), the restore still completes and the activity has no SCORM; open it and re-provision from its settings.

#### Course Mapping
During restore, the plugin checks whether the target course's `skilland_course_id` custom field already holds a value.
- **If YES:** It keeps the existing mapping (assuming you are adding content to an already linked course); a different value in the backup only raises a developer debugging message.
- **If NO:** It writes the backup's `skilland_courseid` into the custom field, mapping the course.

This allows effortless "Course Copy" operations where the new copy becomes a valid Skilland-linked course immediately.

#### User Data
The Skilland activity itself acts primarily as a wrapper/launcher for SCORM content.
- Student attempts and grades are stored within the **SCORM activity**, not the Skilland activity, so "Include user data" restores them with the SCORM module.
- The Skilland activity's backup is the same with or without user data. Because the restored activity links the restored SCORM (above), the restored attempts stay attached to its lessons.
- The per-learner `skilland_progress` summary is not backed up; it is refilled from the restored SCORM tracks (`skilland_refresh_progress()`).

## Troubleshooting

If deep-linking to specific lessons fails after a restore:
1.  Check if the SCORM package was successfully restored.
2.  `scorm_scoes` ids always change on restore. The plugin maps them through the SCORM restore and falls back to `sco_identifier` (string-based) to find the SCO in the restored package.
