# Skilland Moodle Plugin - Backup and Restore Logic

The Skilland Moodle plugin supports standard Moodle backup and restore operations, including:
- **Course Backup/Restore**: Full course backups including Skilland activities and course-level mappings.
- **Course Copy**: Duplicating courses within the same site.
- **Import**: Importing activities from one course to another.

## How it Works

The backup implementation is located in `src/backup/moodle2/` and follows standard Moodle 2.0 backup architecture.

### Data Structures Backed Up
1.  **Activity Instance (`skilland` table)**:
    - All settings (Name, Intro, Auto-update, etc.).
    - SCORM association (`scormcmid`).
    - Skilland Topic ID.
    - Snapshot metadata.

2.  **Course Mapping (`skilland_course` table)**:
    - When backing up an activity, we also include the `skilland_courseid` and `skilland_orgid` of the *current course* in the XML. This ensures that if the activity is restored into a new course that hasn't been linked to Skilland yet, the link is established automatically.

3.  **Selected Lessons (`skilland_lesson` table)**:
    - The list of lessons selected for the topic.
    - Visibility state and ordering.
    - SCO identifiers for SCORM navigation deep-linking.

### Restore Process & Caveats

#### SCORM Association
The plugin relies on the standard Moodle `course_module` mapping to restore the link to the associated SCORM package (`scormcmid`).

> [!WARNING]
> **Important Caveat:** If you are restoring a course where the Skilland activity is restored *before* the associated SCORM activity, Moodle may not yet know the new ID for the SCORM package.
>
> The restoration logic attempts to map the ID:
> ```php
> $data->scormcmid = $this->get_mappingid('course_module', $data->scormcmid);
> ```
> If this mapping returns 0 (not found), the link will be broken. In a standard full course restore, Moodle usually handles dependencies or restores in order, but circular dependencies or specific selective restores can cause issues.
>
> **Fallback:** If the link is broken, you may need to re-provision the SCORM package via the Skilland block or settings.

#### Course Mapping
During restore, the plugin checks if the target course already has an entry in the `skilland_course` table.
- **If YES:** It uses the existing mapping (assuming you are adding content to an already linked course).
- **If NO:** It uses the `skilland_courseid` and `skilland_orgid` from the backup XML to create a new mapping for this course.

This allows effortless "Course Copy" operations where the new copy becomes a valid Skilland-linked course immediately.

#### User Data
The Skilland activity itself acts primarily as a wrapper/launcher for SCORM content.
- Student progress and grades are stored within the **SCORM activity**, not the Skilland activity.
- Therefore, when backing up with "Include user data", the critical user data is in the SCORM module backup.
- The Skilland plugin restores the *structure* key to launching that content.

## Troubleshooting

If deep-linking to specific lessons fails after a restore:
1.  Check if the SCORM package was successfully restored.
2.  Verify the `scorm_scoes` table IDs might have changed. The plugin uses `sco_identifier` (string-based) as a robust fallback to find the correct SCO in the new package if the integer ID mapping fails.
