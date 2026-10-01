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

/**
 * English language strings for mod_skilland.
 *
 * @package    mod_skilland
 * @copyright  2024 SkilLand <https://skilland.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['aria_next_lesson'] = 'Next lesson: {$a}';
$string['aria_previous_lesson'] = 'Previous lesson: {$a}';
$string['autoupdate'] = 'Notify me of content updates';
$string['autoupdate_desc'] = 'When a new version is published in Skilland, the teachers of this course are notified and this activity offers to apply it. Nothing is replaced until a teacher applies the update, which deletes all student attempts and grades for this activity — there is no way to recover them.';
$string['autoupdate_warning'] = 'Update notices are on and "Lock after first access" is off: teachers are told about every content change in Skilland, and applying one deletes and recreates this activity, erasing every student\'s attempts and grades. Turn on "Lock after first access" to stop the notices once students have started.';
$string['back_to_lessons'] = 'Back to lessons';
$string['behaviour'] = 'Behaviour Settings';
$string['completed'] = 'Completed';
$string['completiondetail:lessons'] = 'Complete all lessons';
$string['completionlessons'] = 'Complete all lessons';
$string['completionlessons_desc'] = 'Learners must complete or pass every visible lesson';
$string['completionlessons_help'] = 'If enabled, the activity is marked complete once the learner has completed or passed every visible lesson. Hidden lessons do not count, and an activity with no visible lesson is never complete. Progress is kept when the SCORM package is rebuilt.';
$string['configure_plugin_settings'] = 'Configure plugin settings';
$string['configure_skilland'] = 'Configure Skilland';
$string['configure_skilland_desc'] = 'Set up Skilland course mapping for this course';
$string['content_being_prepared'] = 'This lesson or topic content is being prepared. Please check back later.';
$string['content_coming_soon'] = 'Content Coming Soon';
$string['content_last_updated'] = 'Content last updated: {$a}. Any update to this activity resets progress on it.';
$string['content_not_provisioned'] = 'Content Not Yet Available';
$string['course_unknown'] = 'Unknown course (ID {$a})';
$string['course_unknown_warning'] = 'The linked SkilLand course is no longer available to this site. Choose another course or clear the selection.';
$string['coursemapping_exists'] = 'This course is mapped to Skilland course: {$a}';
$string['coursemapping_info'] = 'Select the Skilland course for this Moodle course. This Skilland course will be used for all Skilland activities in this Moodle course.';
$string['coursemapping_info_help'] = 'The Skilland course mapping is set once per Moodle course and shared across all Skilland activities.';
$string['coursemapping_select'] = 'Skilland Course ID';
$string['create_course_confirm_body'] = 'Create “{$a}” in SkilLand? The SkilLand course is created now and linked to this Moodle course, even if you cancel this form.';
$string['create_course_confirm_replace'] = 'This Moodle course is already linked to a SkilLand course: the new course replaces that link.';
$string['create_course_confirm_title'] = 'Create a SkilLand course';
$string['create_course_confirm_yes'] = 'Create';
$string['create_in_skilland'] = 'Create in SkilLand';
$string['creating_course'] = 'Creating course in Skilland...';
$string['current_topic_unavailable'] = 'Current topic (SkilLand unavailable)';
$string['customfield_exists'] = 'Custom field exists';
$string['customfield_missing'] = 'Custom field not found. It is recreated on the next plugin upgrade or the first time a course is mapped to a SkilLand course.';
$string['customfield_skilland_course_id'] = 'Skilland Course ID';
$string['customfield_skilland_course_id_desc'] = 'The Skilland Course ID associated with this Moodle course. This is used to link all Skilland activities in the Moodle course to the correct Skilland course.';
$string['customfield_status'] = 'Course Custom Field Status';
$string['deselect_all'] = 'Deselect All';
$string['destructive_confirm_action'] = 'Replace content and delete progress';
$string['edit_course_settings'] = 'Edit in course settings';
$string['edit_in_skilland'] = 'Edit Content';
$string['edit_in_skilland_desc'] = 'Click to open the Skilland lesson editor. You will be automatically logged in with your Moodle account.';
$string['edit_in_skilland_header'] = 'Edit in Skilland';
$string['edit_in_skilland_header_desc'] = 'Edit content for course "{$a}" in Skilland platform';
$string['edit_lessons_in_skilland'] = 'Edit Lessons in Skilland';
$string['error_api_unavailable'] = 'The SkilLand service could not be reached. Try again later, or ask your site administrator to check the SkilLand plugin settings.';
$string['error_config_invalid_credentials'] = 'Invalid API credentials. Please check your Organization ID and API Key in plugin settings.';
$string['error_config_missing_apikey'] = 'Skilland API Key is not configured. Please set it in plugin settings.';
$string['error_config_missing_courseid'] = 'Skilland Course ID is required to fetch topics.';
$string['error_config_missing_endpoint'] = 'Skilland URL is not configured. Please set it in plugin settings.';
$string['error_config_missing_lessonid'] = 'Skilland Lesson ID is required to fetch SCORM package.';
$string['error_config_missing_orgid'] = 'Skilland Organization ID is not configured. Please set it in plugin settings.';
$string['error_config_missing_topicid'] = 'Skilland Topic ID is required to fetch lessons.';
$string['error_course_not_mapped'] = 'This Moodle course is not mapped to a Skilland course. Set the Skilland Course ID in the course settings first.';
$string['error_course_not_mapped_to_skill'] = 'The requested Skilland content does not belong to the Skilland course mapped to this Moodle course.';
$string['error_create_course'] = 'Failed to create course: {$a}';
$string['error_create_course_failed'] = 'Failed to create course in Skilland.';
$string['error_create_inactive_member'] = 'Your SkilLand account is deactivated in this organization, so it cannot create courses. Ask a SkilLand organization admin to reactivate it.';
$string['error_create_insufficient_role'] = 'Your SkilLand account cannot create courses. Ask a SkilLand organization admin for the Expert role.';
$string['error_create_name_taken'] = 'A SkilLand course with this name already exists. Rename the Moodle course or link the existing SkilLand course instead.';
$string['error_create_not_a_member'] = 'Your email address belongs to a SkilLand account outside this organization, so the course cannot be created for you.';
$string['error_create_rate_limited'] = 'Too many SkilLand courses were created recently. Try again in a few minutes.';
$string['error_fetch_courses'] = 'Failed to fetch courses from Skilland. Please check your configuration and try again.';
$string['error_fetch_courses_detail'] = 'Failed to fetch courses from Skilland: {$a}';
$string['error_fetch_topics'] = 'Failed to fetch topics';
$string['error_fetch_topics_detail'] = 'Failed to fetch topics from Skilland: {$a}';
$string['error_grade_scale_unsupported'] = 'Scales are not supported. Choose a point grade or None.';
$string['error_graphql_http'] = 'HTTP error when calling Skilland API: {$a}';
$string['error_graphql_invalid_json'] = 'Invalid JSON response from Skilland API';
$string['error_http'] = 'HTTP {$a}';
$string['error_http_redirect'] = 'The Skilland server answered with a redirect (HTTP {$a}), which is not followed. Check the configured URL.';
$string['error_insecure_url'] = 'The Skilland {$a} URL must use https://.';
$string['error_lessons_not_in_topic'] = 'The selected lessons do not belong to this topic. Reselect the lessons and save again.';
$string['error_loading_courses'] = 'Error loading courses: {$a}';
$string['error_missing_parameters'] = 'Missing required parameters for SSO redirect.';
$string['error_network'] = 'Network error occurred';
$string['error_no_valid_id_provisioning'] = 'No valid ID provided for provisioning';
$string['error_package_host_not_allowed'] = 'SCORM packages may not be downloaded from {$a}. Add the host to the SCORM package hosts setting if it is trusted.';
$string['error_package_not_zip'] = 'The downloaded SCORM package is not a valid zip file.';
$string['error_package_too_large'] = 'The SCORM package is larger than the {$a} MB limit.';
$string['error_plugin_disabled'] = 'The Skilland plugin is currently disabled.';
$string['error_provision_in_progress'] = 'SCORM content for this activity is already being prepared. Please try again in a moment.';
$string['error_scorm_create_failed'] = 'Failed to create SCORM activity: {$a}';
$string['error_scorm_download_failed'] = 'Failed to download SCORM package: {$a}';
$string['error_scorm_fetch_failed'] = 'Failed to fetch SCORM package from Skilland.';
$string['error_scorm_hash_mismatch'] = 'SCORM package integrity check failed. The downloaded file may be corrupted.';
$string['error_scorm_not_available'] = 'SCORM content is not available for this lesson.';
$string['error_scorm_parse_failed'] = 'The SCORM package could not be parsed into launchable lessons: {$a}';
$string['error_scorm_signature_invalid'] = 'The signature of the SCORM package from Skilland does not match the package, so it was not imported.';
$string['error_scorm_signature_malformed'] = 'The signature of the SCORM package from Skilland is malformed, so the package was not imported.';
$string['error_scorm_signature_missing'] = 'The SCORM package from Skilland is not signed, so it was not imported. Ask your site administrator to check the Skilland plugin settings.';
$string['error_scorm_signature_unknown_key'] = 'The SCORM package from Skilland is signed with a key this site does not trust, so it was not imported. Ask your site administrator to check the SCORM package signing keys.';
$string['error_scorm_upload_failed'] = 'Failed to upload SCORM package to Moodle.';
$string['error_signing_keys_invalid'] = 'Invalid lines: {$a}. Each line must be keyid:publickey, where the key id has 1 to 64 letters, digits, dots, underscores or hyphens (each id once) and the public key is base64 of exactly 32 bytes.';
$string['error_sso_secret_known_dev'] = 'This SSO shared secret was published as a development default and cannot be used. Copy your organization\'s secret (at least 32 bytes) from SkilLand › Settings › Integrations › Moodle.';
$string['error_sso_secret_too_short'] = 'The SSO shared secret must be at least 32 bytes long. Copy your organization\'s secret from SkilLand › Settings › Integrations › Moodle.';
$string['error_sso_user_not_allowed'] = 'This Moodle account cannot sign in to SkilLand: guest, suspended, deleted, unconfirmed and no-login accounts are refused. Contact your site administrator if you think this is a mistake.';
$string['error_topicid_required'] = 'A topic is required. Select one, or retry if the topic list failed to load.';
$string['error_unknown'] = 'Unknown error';
$string['error_url_https_required'] = 'This URL must use https://.';
$string['failed'] = 'Failed';
$string['go_to_skilland'] = 'Go to Skilland';
$string['hidelabels'] = 'Hide Skilland codes';
$string['hidelabels_desc'] = 'Hide Skilland codes (e.g. T2-L1) from student-facing activity names';
$string['in_progress'] = 'In progress';
$string['invalidactivity'] = 'Invalid activity specified.';
$string['launch_lesson'] = 'Start Lesson';
$string['lesson_not_available'] = 'This lesson is not available in this activity.';
$string['lesson_sco_missing'] = 'This lesson is not in the current content package. Use Update From Skilland in the activity settings to rebuild it.';
$string['lessons'] = 'Lessons';
$string['lessonselection'] = 'Select Lessons';
$string['lessonselection_help'] = 'Choose which lessons from this topic to import as SCORM activities.';
$string['loading'] = 'Loading...';
$string['loading_courses'] = 'Loading courses from Skilland...';
$string['lockafterfirstaccess'] = 'Lock after first access';
$string['lockafterfirstaccess_desc'] = 'Once a student has accessed the content, stop auto-update from deleting and recreating this SCORM package (their attempts and grades are preserved)';
$string['lockafterfirstaccess_hint'] = 'To keep the current content unchanged for students who have already started, turn on "{$a}" in the activity settings instead.';
$string['messageprovider:contentupdate'] = 'Skilland content updates available for your activities';
$string['missing_lessons_warning'] = 'These lessons are no longer available in SkilLand. They stay part of this activity until you remove them.';
$string['modulename']   = 'Skilland content';
$string['modulename_help'] = 'Link a SkilLand topic and its lessons as a Moodle activity, provisioned as SCORM content that tracks learner progress.';
$string['modulenameplural'] = 'Skilland contents';
$string['new_content_available'] = 'New Content Available';
$string['no_courses_available'] = 'No courses available';
$string['no_lessons_configured'] = 'No lessons have been selected for this topic yet. Edit the activity settings to add lessons.';
$string['no_lessons_found'] = 'No lessons found for this topic.';
$string['no_next_lesson'] = 'No next lesson';
$string['no_previous_lesson'] = 'No previous lesson';
$string['no_topics_available'] = 'No topics available';
$string['not_started'] = 'Not started';
$string['open_new_course_in_skilland'] = 'Open the new course in SkilLand';
$string['pluginadministration'] = 'Skilland content administration';
$string['pluginname']   = 'Skilland content';
$string['privacy:metadata:skilland'] = 'To sign a user in to SkilLand Studio (SSO), to list the SkilLand courses of a teacher, and to create a SkilLand course from Moodle (which creates a SkilLand account for that email address), the plugin sends personal data to the organisation\'s SkilLand platform. Data already sent to SkilLand is handled by the organisation\'s SkilLand administrator.';
$string['privacy:metadata:skilland:courseaccess'] = 'The Moodle courses the user is enrolled in that are linked to a SkilLand course, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland:email'] = 'The user\'s email address, sent when signing in to SkilLand Studio, listing their SkilLand courses and creating a SkilLand course.';
$string['privacy:metadata:skilland:fullname'] = 'The user\'s full name, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland:role'] = 'The SkilLand role the user is given, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland:userid'] = 'The user\'s Moodle user ID, sent when signing in to SkilLand Studio so that SkilLand recognises the same Moodle account on every sign-in.';
$string['privacy:metadata:skilland_progress'] = 'The best status and score each learner reached in each lesson of a Skilland activity.';
$string['privacy:metadata:skilland_progress:lessonid'] = 'The ID of the lesson.';
$string['privacy:metadata:skilland_progress:score'] = 'The highest score the learner reached in the lesson.';
$string['privacy:metadata:skilland_progress:status'] = 'The best status the learner reached in the lesson.';
$string['privacy:metadata:skilland_progress:timemodified'] = 'The time the progress was last updated.';
$string['privacy:metadata:skilland_progress:userid'] = 'The ID of the learner.';
$string['privacy:path:progress'] = 'Lesson progress';
$string['provision_content'] = 'Prepare Content';
$string['provision_content_desc'] = 'Download and create the SCORM lesson from Skilland.';
$string['provision_failed'] = 'Failed to prepare content: {$a}';
$string['provision_success'] = 'Content prepared successfully!';
$string['provision_topic'] = 'Prepare Topic Content';
$string['provision_topic_desc'] = 'Download and create the SCORM package containing all lessons for this topic.';
$string['provisioning'] = 'Preparing content...';
$string['provisioning_elapsed'] = 'Preparing content… {$a}';
$string['provisioning_timeout_message'] = 'This is taking longer than expected. It keeps running in the background — you can refresh this page in a few minutes to check on it.';
$string['provisioning_wait_hint'] = 'This can take several minutes for large topics. Keep this page open.';
$string['ready_to_start'] = 'Ready';
$string['remove_from_activity'] = 'Remove from activity';
$string['retry'] = 'Retry';
$string['score'] = 'Score';
$string['scorm_download_complete'] = 'Download complete';
$string['scorm_downloading'] = 'Downloading lesson content...';
$string['scorm_missing_reprovision'] = 'The content package for this activity was deleted. Prepare it again to make the lessons playable.';
$string['scorm_not_ready'] = 'This lesson is not yet ready to play. Please wait for the content to be prepared.';
$string['select_all'] = 'Select All';
$string['select_skilland_course'] = 'Select a Skilland course...';
$string['select_topic'] = 'Select a topic';
$string['select_topic_first'] = 'Select a topic first to view lessons.';
$string['set_skilland_course_id'] = 'Set Skilland Course ID in course settings';
$string['settings_apikey'] = 'API Key';
$string['settings_apikey_desc'] = 'Enter your Skilland API key here.';
$string['settings_devmode'] = 'Verbose debug logging';
$string['settings_devmode_desc'] = 'Logs request details at DEBUG_DEVELOPER level. Never enable on a production site.';
$string['settings_frontend_url'] = 'Frontend URL (optional)';
$string['settings_frontend_url_desc'] = 'Optional override of the Skilland URL for SSO redirects, Studio links and the SkilLand REST API. Leave it empty to use the Skilland URL.';
$string['settings_graphql_endpoint'] = 'Skilland URL';
$string['settings_graphql_endpoint_desc'] = 'The address of the Skilland site, for example https://app.skilland.ai. The plugin calls the SkilLand REST API under it (/api/moodle), authenticated with the API key. A value saved with a trailing /graphql or /api/moodle still works.';
$string['settings_orgid'] = 'Organization ID';
$string['settings_orgid_desc'] = 'Enter your Skilland Organization ID here.';
$string['settings_package_hosts'] = 'SCORM package hosts';
$string['settings_package_hosts_desc'] = 'Comma-separated hosts SCORM packages may be downloaded from, besides the Skilland URL and Frontend URL hosts. "*.example.com" matches subdomains of example.com only.';
$string['settings_package_max_mb'] = 'Maximum SCORM package size (MB)';
$string['settings_package_max_mb_desc'] = 'Downloads larger than this are aborted.';
$string['settings_signingkeys'] = 'SCORM package signing keys';
$string['settings_signingkeys_desc'] = 'SkilLand signs every SCORM package it sends, and this site imports a package only when its Ed25519 signature verifies, for the topic that was requested, against a trusted key: the keys built into the plugin plus the ones listed here, one <code>keyid:base64publickey</code> per line. A package runs in the Moodle SCORM player as content of this site, calls the SCORM API and reads and writes each learner\'s SCORM track, so add only a public key SkilLand has published, for example while it rotates keys. Unsigned packages are always refused.';
$string['settings_sso_secret'] = 'SSO Shared Secret';
$string['settings_sso_secret_desc'] = 'Your organization\'s Moodle SSO secret. Copy it from SkilLand › Settings › Integrations › Moodle: SkilLand derives a secret for each organization and only accepts sign-in tokens signed with the one that belongs to this site\'s Organization ID. It must be at least 32 bytes long.';
$string['skilland:accessstudio'] = 'Open SkilLand Studio and link courses';
$string['skilland:addinstance'] = 'Add a new Skilland content activity';
$string['skilland:provision'] = 'Prepare and update SkilLand content in an activity';
$string['skilland:view'] = 'View Skilland content';
$string['skilland_course_id'] = 'Skilland Course';
$string['skilland_course_id_help'] = 'The Skilland Course for this Moodle course. This value is set in the course settings and applies to all Skilland activities in the Moodle course.';
$string['skilland_course_id_not_set'] = 'Skilland Course ID is not set for this course.';
$string['skilland_course_id_required'] = 'Skilland Course ID must be set for this course. {$a}';
$string['skilland_course_id_required_message'] = 'Before you can create a Skilland activity, you must first set the Skilland Course ID for this course in the course settings.';
$string['sso_continue'] = 'Continue to SkilLand';
$string['sso_redirecting'] = 'Signing you in to SkilLand…';
$string['task_sync_content'] = 'Sync Skilland content for auto-update activities';
$string['toggle_fullscreen'] = 'Toggle fullscreen';
$string['topic_change_confirm'] = 'Changing the topic replaces the content for this activity.';
$string['topic_change_confirm_students'] = 'Changing the topic replaces the content for this activity. {$a} student(s) have progress that will be permanently deleted.';
$string['topic_change_confirm_title'] = 'Change the topic?';
$string['topic_changed_reprovision_failed'] = 'The topic was changed, but its content could not be built. Open the activity and use Prepare Topic Content to create it.';
$string['topic_no_longer_available'] = '(no longer available in SkilLand)';
$string['topic_no_longer_available_warning'] = 'The topic saved for this activity is no longer available in SkilLand. Its settings are kept; select a different topic to replace it.';
$string['topicid'] = 'Topic';
$string['topicid_help'] = 'Select the topic within the Skilland course that this activity represents.';
$string['update_available'] = 'Update Available';
$string['update_available_desc'] = 'Content has been updated in Skilland. Click the button to refresh the SCORM package.';
$string['update_confirm_message'] = 'This will download the latest content from Skilland and replace the current SCORM package.';
$string['update_confirm_message_students'] = 'This will download the latest content from Skilland and replace the current SCORM package. {$a} student(s) have progress on this topic that will be permanently deleted and cannot be recovered.';
$string['update_confirm_title'] = 'Update Content?';
$string['update_error'] = 'Failed to update content';
$string['update_from_skilland'] = 'Update From Skilland';
$string['update_notification_body'] = 'The Skilland content of "{$a->activity}" in {$a->course} has changed. Nothing was replaced: open the activity and press "Update From Skilland" to apply it. Applying the update deletes the students\' attempts and grades on this activity.

{$a->url}';
$string['update_notification_small'] = 'New Skilland content is available for "{$a->activity}".';
$string['update_notification_subject'] = 'Content update available: {$a->activity}';
$string['update_success'] = 'Content updated successfully from Skilland.';
$string['updated'] = 'Updated';
$string['updated_on'] = 'Updated {$a}';
$string['updating'] = 'Updating...';
