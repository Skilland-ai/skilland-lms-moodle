<?php

$string['pluginname']   = 'Skilland content';
$string['modulename']   = 'Skilland content';
$string['modulenameplural'] = 'Skilland contents';
$string['skilland:addinstance'] = 'Add a new Skilland content activity';
$string['skilland:view'] = 'View Skilland content';
$string['skilland:provision'] = 'Provision and update SkilLand content in an activity';
$string['skilland:accessstudio'] = 'Open SkilLand Studio and link courses';
$string['pluginadministration'] = 'Skilland content administration';

// Settings.
$string['settings_apikey'] = 'API Key';
$string['settings_apikey_desc'] = 'Enter your Skilland API key here.';
$string['settings_orgid'] = 'Organization ID';
$string['settings_orgid_desc'] = 'Enter your Skilland Organization ID here.';

// Course mapping.
$string['coursemapping_exists'] = 'This course is mapped to Skilland course: {$a}';
$string['coursemapping_select'] = 'Skilland Course ID';
$string['coursemapping_info'] = 'Select the Skilland course for this Moodle course. This Skilland course will be used for all Skilland activities in this Moodle course.';
$string['coursemapping_info_help'] = 'The Skilland course mapping is set once per Moodle course and shared across all Skilland activities.';

// Activity form.
$string['topicid'] = 'Topic';
$string['topicid_help'] = 'Select the topic within the Skilland course that this activity represents.';
$string['select_topic'] = 'Select a topic';
$string['no_topics_available'] = 'No topics available';
$string['loading'] = 'Loading...';
$string['lessonselection'] = 'Select Lessons';
$string['lessonselection_help'] = 'Choose which lessons from this topic to import as SCORM activities.';
$string['lessons'] = 'Lessons';
$string['select_topic_first'] = 'Select a topic first to view lessons.';
$string['no_lessons_found'] = 'No lessons found for this topic.';

// Behaviour settings.
$string['behaviour'] = 'Behaviour Settings';
$string['autoupdate'] = 'Auto-update content';
$string['autoupdate_desc'] = 'When a new version is published in Skilland, automatically delete and recreate this SCORM package. This deletes all student attempts and grades for this activity — there is no way to recover them.';
$string['autoupdate_warning'] = 'Auto-update is on and "Lock after first access" is off: as soon as content changes in Skilland, this activity will be deleted and recreated, erasing every student\'s attempts and grades. Turn on "Lock after first access" to stop that once students have started.';
$string['lockafterfirstaccess'] = 'Lock after first access';
$string['lockafterfirstaccess_desc'] = 'Once a student has accessed the content, stop auto-update from deleting and recreating this SCORM package (their attempts and grades are preserved)';
$string['hidelabels'] = 'Hide Skilland codes';
$string['hidelabels_desc'] = 'Hide Skilland codes (e.g. T2-L1) from student-facing activity names';

// Course custom field.
$string['customfield_skilland_course_id'] = 'Skilland Course ID';
$string['customfield_skilland_course_id_desc'] = 'The Skilland Course ID associated with this Moodle course. This is used to link all Skilland activities in the Moodle course to the correct Skilland course.';
$string['skilland_course_id'] = 'Skilland Course';
$string['skilland_course_id_help'] = 'The Skilland Course for this Moodle course. This value is set in the course settings and applies to all Skilland activities in the Moodle course.';
$string['skilland_course_id_not_set'] = 'Skilland Course ID is not set for this course.';
$string['skilland_course_id_required'] = 'Skilland Course ID must be set for this course. {$a}';
$string['skilland_course_id_required_message'] = 'Before you can create a Skilland activity, you must first set the Skilland Course ID for this course in the course settings.';
$string['set_skilland_course_id'] = 'Set Skilland Course ID in course settings';
$string['edit_course_settings'] = 'Edit in course settings';

// GraphQL settings.
$string['settings_graphql_endpoint'] = 'GraphQL Endpoint';
$string['settings_graphql_endpoint_desc'] = 'The URL of the Skilland GraphQL API endpoint.';
$string['settings_package_hosts'] = 'SCORM package hosts';
$string['settings_package_hosts_desc'] = 'Comma-separated hosts SCORM packages may be downloaded from, besides the GraphQL endpoint host. "*.example.com" matches subdomains of example.com only.';
$string['settings_package_max_mb'] = 'Maximum SCORM package size (MB)';
$string['settings_package_max_mb_desc'] = 'Downloads larger than this are aborted.';
$string['settings_frontend_url'] = 'Frontend URL';
$string['settings_frontend_url_desc'] = 'The URL of the Skilland frontend application (for SSO redirects). If not set, will use the GraphQL endpoint URL.';

// SSO settings.
$string['settings_sso_secret'] = 'SSO Shared Secret';
$string['settings_sso_secret_desc'] = 'Shared secret for SSO authentication. This must match the MOODLE_SSO_SECRET environment variable in the Skilland backend. Use at least 32 random bytes, e.g. generate one with <code>openssl rand -base64 32</code>.';

// Development settings.
$string['settings_devmode'] = 'Verbose debug logging';
$string['settings_devmode_desc'] = 'Logs request details at DEBUG_DEVELOPER level. Never enable on a production site.';

// Error messages.
$string['error_config_missing_orgid'] = 'Skilland Organization ID is not configured. Please set it in plugin settings.';
$string['error_config_missing_apikey'] = 'Skilland API Key is not configured. Please set it in plugin settings.';
$string['error_config_missing_endpoint'] = 'GraphQL endpoint is not configured. Please set it in plugin settings.';
$string['error_config_invalid_credentials'] = 'Invalid API credentials. Please check your Organization ID and API Key in plugin settings.';
$string['error_sso_secret_too_short'] = 'The SSO shared secret must be at least 32 bytes long. Generate one with openssl rand -base64 32.';
$string['error_sso_secret_known_dev'] = 'This SSO shared secret was published as a development default and cannot be used. Generate a new one with openssl rand -base64 32.';
$string['error_url_https_required'] = 'This URL must use https://.';
$string['error_insecure_url'] = 'The Skilland {$a} URL must use https://.';
$string['error_http_redirect'] = 'The Skilland server answered with a redirect (HTTP {$a}), which is not followed. Check the configured URL.';
$string['error_package_host_not_allowed'] = 'SCORM packages may not be downloaded from {$a}. Add the host to the SCORM package hosts setting if it is trusted.';
$string['error_package_too_large'] = 'The SCORM package is larger than the {$a} MB limit.';
$string['error_package_not_zip'] = 'The downloaded SCORM package is not a valid zip file.';
$string['error_api_unavailable'] = 'The SkilLand service could not be reached. Try again later, or ask your site administrator to check the SkilLand plugin settings.';
$string['error_graphql_http'] = 'HTTP error when calling Skilland API: {$a}';
$string['error_graphql_invalid_json'] = 'Invalid JSON response from Skilland API';
$string['error_graphql'] = 'GraphQL error: {$a}';
$string['error_graphql_unknown'] = 'Unknown GraphQL error';
$string['error_http'] = 'HTTP {$a}';
$string['error_fetch_courses'] = 'Failed to fetch courses from Skilland. Please check your configuration and try again.';
$string['error_fetch_topics'] = 'Failed to fetch topics';
$string['error_config_missing_courseid'] = 'Skilland Course ID is required to fetch topics.';
$string['error_config_missing_topicid'] = 'Skilland Topic ID is required to fetch lessons.';
$string['error_config_missing_lessonid'] = 'Skilland Lesson ID is required to fetch SCORM package.';
$string['configure_plugin_settings'] = 'Configure plugin settings';

// Course custom field status in settings.
$string['customfield_status'] = 'Course Custom Field Status';
$string['customfield_exists'] = 'Custom field exists';
$string['customfield_missing'] = 'Custom field not found. It is recreated on the next plugin upgrade or the first time a course is mapped to a SkilLand course.';

// View page.
$string['no_lessons_configured'] = 'No lessons have been selected for this topic yet. Edit the activity settings to add lessons.';
$string['not_started'] = 'Not started';
$string['ready_to_start'] = 'Ready';
$string['in_progress'] = 'In progress';
$string['completed'] = 'Completed';
$string['failed'] = 'Failed';
$string['score'] = 'Score';
$string['updated'] = 'Updated';
$string['back_to_lessons'] = 'Back to lessons';
$string['toggle_fullscreen'] = 'Toggle fullscreen';
$string['aria_previous_lesson'] = 'Previous lesson: {$a}';
$string['aria_next_lesson'] = 'Next lesson: {$a}';
$string['no_previous_lesson'] = 'No previous lesson';
$string['no_next_lesson'] = 'No next lesson';
$string['launch_lesson'] = 'Start Lesson';
$string['content_coming_soon'] = 'Content Coming Soon';
$string['content_being_prepared'] = 'This lesson content is being prepared. Please check back later.';
$string['content_last_updated'] = 'Content last updated: {$a}. Any update to this activity resets progress on it.';
$string['content_not_provisioned'] = 'Content Not Yet Available';
$string['lesson_not_available'] = 'This lesson is not available in this activity.';
$string['scorm_not_ready'] = 'This lesson is not yet ready to play. Please wait for the content to be provisioned.';
$string['scorm_missing_reprovision'] = 'The content package for this activity was deleted. Provision it again to make the lessons playable.';

// SCORM integration.
$string['error_scorm_not_available'] = 'SCORM content is not available for this lesson.';
$string['error_scorm_fetch_failed'] = 'Failed to fetch SCORM package from Skilland.';
$string['error_scorm_download_failed'] = 'Failed to download SCORM package: {$a}';
$string['error_scorm_hash_mismatch'] = 'SCORM package integrity check failed. The downloaded file may be corrupted.';
$string['error_scorm_create_failed'] = 'Failed to create SCORM activity: {$a}';
$string['error_scorm_upload_failed'] = 'Failed to upload SCORM package to Moodle.';
$string['error_provision_in_progress'] = 'SCORM content for this activity is already being provisioned. Please try again in a moment.';
$string['error_scorm_parse_failed'] = 'The SCORM package could not be parsed into launchable lessons: {$a}';
$string['scorm_downloading'] = 'Downloading lesson content...';
$string['scorm_download_complete'] = 'Download complete';
$string['provision_content'] = 'Provision Content';
$string['provision_content_desc'] = 'Download and create the SCORM lesson from Skilland.';
$string['provision_topic'] = 'Provision Topic Content';
$string['provision_topic_desc'] = 'Download and create the SCORM package containing all lessons for this topic.';
$string['provisioning'] = 'Provisioning content...';
$string['provision_success'] = 'Content provisioned successfully!';
$string['provision_failed'] = 'Failed to provision content: {$a}';
$string['provisioning_wait_hint'] = 'This can take several minutes for large topics. Keep this page open.';
$string['provisioning_elapsed'] = 'Preparing content… {$a}';
$string['provisioning_timeout_message'] = 'This is taking longer than expected. It keeps running in the background — you can refresh this page in a few minutes to check on it.';

// New content indicator.
$string['new_content_available'] = 'New Content Available';

// Update from Skilland.
$string['update_from_skilland'] = 'Update From Skilland';
$string['updating'] = 'Updating...';
$string['update_confirm_title'] = 'Update Content?';
$string['update_confirm_message'] = 'This will download the latest content from Skilland and replace the current SCORM package.';
$string['update_confirm_message_students'] = 'This will download the latest content from Skilland and replace the current SCORM package. {$a} student(s) have progress on this topic that will be permanently deleted and cannot be recovered.';

// Destructive confirmation styling (SKL-697).
$string['destructive_confirm_action'] = 'Replace content and delete progress';
$string['lockafterfirstaccess_hint'] = 'To keep the current content unchanged for students who have already started, turn on "{$a}" in the activity settings instead.';

// Topic change and SCO reconciliation (SKL-655).
$string['topic_change_confirm_title'] = 'Change the topic?';
$string['topic_change_confirm'] = 'Changing the topic replaces the content for this activity.';
$string['topic_change_confirm_students'] = 'Changing the topic replaces the content for this activity. {$a} student(s) have progress that will be permanently deleted.';
$string['topic_changed_reprovision_failed'] = 'The topic was changed, but its content could not be built. Open the activity and use Provision Topic Content to create it.';
$string['lesson_sco_missing'] = 'This lesson is not in the current content package. Use Update From Skilland in the activity settings to rebuild it.';
$string['update_success'] = 'Content updated successfully from Skilland.';
$string['update_error'] = 'Failed to update content';

// Edit in Skilland.
$string['edit_in_skilland'] = 'Edit Content';
$string['edit_lessons_in_skilland'] = 'Edit Lessons in Skilland';
$string['edit_in_skilland_desc'] = 'Click to open the Skilland lesson editor. You will be automatically logged in with your Moodle account.';
$string['edit_in_skilland_header'] = 'Edit in Skilland';
$string['edit_in_skilland_header_desc'] = 'Edit content for course "{$a}" in Skilland platform';

// SSO handoff (SKL-687).
$string['sso_continue'] = 'Continue to SkilLand';
$string['sso_redirecting'] = 'Signing you in to SkilLand…';

// Lesson selection.
$string['select_all'] = 'Select All';
$string['deselect_all'] = 'Deselect All';

// Configure Skilland button.
$string['configure_skilland'] = 'Configure Skilland';
$string['configure_skilland_desc'] = 'Set up Skilland course mapping for this course';
$string['go_to_skilland'] = 'Go to Skilland';

// Create in Skilland.
$string['create_in_skilland'] = 'Create in SkilLand';
$string['creating_course'] = 'Creating course in Skilland...';

// Auto-update / scheduled task.
$string['task_sync_content'] = 'Sync Skilland content for auto-update activities';
$string['update_available'] = 'Update Available';
$string['update_available_desc'] = 'Content has been updated in Skilland. Click the button to refresh the SCORM package.';

// Plugin disabled.
$string['error_plugin_disabled'] = 'The Skilland plugin is currently disabled.';
$string['error_course_not_mapped'] = 'This Moodle course is not mapped to a Skilland course. Set the Skilland Course ID in the course settings first.';
$string['error_course_not_mapped_to_skill'] = 'The requested Skilland content does not belong to the Skilland course mapped to this Moodle course.';
$string['error_lessons_not_in_topic'] = 'The selected lessons do not belong to this topic. Reselect the lessons and save again.';

// Errors.
$string['invalidactivity'] = 'Invalid activity specified.';
$string['error_missing_parameters'] = 'Missing required parameters for SSO redirect.';

// Completion and grades (SKL-668).
$string['completionlessons'] = 'Complete all lessons';
$string['completionlessons_desc'] = 'Learners must complete or pass every visible lesson';
$string['completionlessons_help'] = 'If enabled, the activity is marked complete once the learner has completed or passed every visible lesson. Hidden lessons do not count, and an activity with no visible lesson is never complete. Progress is kept when the SCORM package is rebuilt.';
$string['completiondetail:lessons'] = 'Complete all lessons';
$string['error_grade_scale_unsupported'] = 'Scales are not supported. Choose a point grade or None.';

// Privacy API (SKL-660).
$string['privacy:metadata:skilland'] = 'To sign a user in to SkilLand Studio (SSO), to list the SkilLand courses of a teacher, and to create a SkilLand course from Moodle (which creates a SkilLand account for that email address), the plugin sends personal data to the organisation\'s SkilLand platform. Data already sent to SkilLand is handled by the organisation\'s SkilLand administrator.';
$string['privacy:metadata:skilland:email'] = 'The user\'s email address, sent when signing in to SkilLand Studio, listing their SkilLand courses and creating a SkilLand course.';
$string['privacy:metadata:skilland:fullname'] = 'The user\'s full name, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland:role'] = 'The SkilLand role the user is given, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland:courseaccess'] = 'The Moodle courses the user is enrolled in that are linked to a SkilLand course, sent when signing in to SkilLand Studio.';
$string['privacy:metadata:skilland_progress'] = 'The best status and score each learner reached in each lesson of a Skilland activity.';
$string['privacy:metadata:skilland_progress:userid'] = 'The ID of the learner.';
$string['privacy:metadata:skilland_progress:lessonid'] = 'The ID of the lesson.';
$string['privacy:metadata:skilland_progress:status'] = 'The best status the learner reached in the lesson.';
$string['privacy:metadata:skilland_progress:score'] = 'The highest score the learner reached in the lesson.';
$string['privacy:metadata:skilland_progress:timemodified'] = 'The time the progress was last updated.';
$string['privacy:path:progress'] = 'Lesson progress';

// Course mapping field (SKL-664).
$string['create_course_confirm_title'] = 'Create a SkilLand course';
$string['create_course_confirm_body'] = 'Create “{$a}” in SkilLand? The SkilLand course is created now and linked to this Moodle course, even if you cancel this form.';
$string['create_course_confirm_replace'] = 'This Moodle course is already linked to a SkilLand course: the new course replaces that link.';
$string['create_course_confirm_yes'] = 'Create';
$string['course_unknown'] = 'Unknown course (ID {$a})';
$string['course_unknown_warning'] = 'The linked SkilLand course is no longer available to this site. Choose another course or clear the selection.';
$string['open_new_course_in_skilland'] = 'Open the new course in SkilLand';
