<?php

$string['pluginname']   = 'Skilland content';
$string['modulename']   = 'Skilland content';
$string['modulenameplural'] = 'Skilland contents';
$string['skilland:addinstance'] = 'Add a new Skilland content activity';
$string['skilland:view'] = 'View Skilland content';
$string['skilland:submit'] = 'Submit to Skilland activities';
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
$string['autoupdate_desc'] = 'Automatically update SCORM activities when a new snapshot is published in Skilland';
$string['lockafterfirstaccess'] = 'Lock after first access';
$string['lockafterfirstaccess_desc'] = 'Prevent updates once students have accessed the content';
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
$string['settings_frontend_url'] = 'Frontend URL';
$string['settings_frontend_url_desc'] = 'The URL of the Skilland frontend application (for SSO redirects). If not set, will use the GraphQL endpoint URL.';

// SSO settings.
$string['settings_sso_secret'] = 'SSO Shared Secret';
$string['settings_sso_secret_desc'] = 'Shared secret for SSO authentication. This must match the MOODLE_SSO_SECRET environment variable in the Skilland backend. Use a strong random string (min 32 characters).';

// Development settings.
$string['settings_devmode'] = 'Development mode';
$string['settings_devmode_desc'] = 'Enable console logging for debugging. Disable in production.';

// Error messages.
$string['error_config_missing_orgid'] = 'Skilland Organization ID is not configured. Please set it in plugin settings.';
$string['error_config_missing_apikey'] = 'Skilland API Key is not configured. Please set it in plugin settings.';
$string['error_config_missing_endpoint'] = 'GraphQL endpoint is not configured. Please set it in plugin settings.';
$string['error_config_invalid_credentials'] = 'Invalid API credentials. Please check your Organization ID and API Key in plugin settings.';
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

// Custom field creation in settings.
$string['customfield_status'] = 'Course Custom Field Status';
$string['customfield_exists'] = 'Custom field exists';
$string['customfield_missing'] = 'Custom field not found - click button below to create it';
$string['create_customfield_button'] = 'Create Custom Field';
$string['customfield_created'] = 'Custom field created successfully!';
$string['customfield_create_failed'] = 'Failed to create custom field. Please check error logs or create it manually.';

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
$string['launch_lesson'] = 'Start Lesson';
$string['content_coming_soon'] = 'Content Coming Soon';
$string['content_being_prepared'] = 'This lesson content is being prepared. Please check back later.';
$string['content_not_provisioned'] = 'Content Not Yet Available';
$string['lesson_not_found'] = 'Lesson not found.';
$string['scorm_not_ready'] = 'This lesson is not yet ready to play. Please wait for the content to be provisioned.';

// SCORM integration.
$string['error_scorm_not_available'] = 'SCORM content is not available for this lesson.';
$string['error_scorm_fetch_failed'] = 'Failed to fetch SCORM package from Skilland: {$a}';
$string['error_scorm_download_failed'] = 'Failed to download SCORM package: {$a}';
$string['error_scorm_hash_mismatch'] = 'SCORM package integrity check failed. The downloaded file may be corrupted.';
$string['error_scorm_create_failed'] = 'Failed to create SCORM activity: {$a}';
$string['error_scorm_upload_failed'] = 'Failed to upload SCORM package to Moodle.';
$string['scorm_downloading'] = 'Downloading lesson content...';
$string['scorm_download_complete'] = 'Download complete';
$string['provision_content'] = 'Provision Content';
$string['provision_content_desc'] = 'Download and create the SCORM lesson from Skilland.';
$string['provision_topic'] = 'Provision Topic Content';
$string['provision_topic_desc'] = 'Download and create the SCORM package containing all lessons for this topic.';
$string['provisioning'] = 'Provisioning content...';
$string['provision_success'] = 'Content provisioned successfully!';
$string['provision_failed'] = 'Failed to provision content: {$a}';

// New content indicator.
$string['new_content_available'] = 'New Content Available';

// Update from Skilland.
$string['update_from_skilland'] = 'Update From Skilland';
$string['updating'] = 'Updating...';
$string['update_confirm_title'] = 'Update Content?';
$string['update_confirm_message'] = 'This will download the latest content from Skilland and replace the current SCORM package. WARNING: All student progress and grades for this topic will be permanently deleted. This action cannot be undone. Are you sure you want to continue?';
$string['update_success'] = 'Content updated successfully from Skilland.';
$string['update_error'] = 'Failed to update content';

// Edit in Skilland.
$string['edit_in_skilland'] = 'Edit Content';
$string['edit_lessons_in_skilland'] = 'Edit Lessons in Skilland';
$string['edit_in_skilland_desc'] = 'Click to open the Skilland lesson editor. You will be automatically logged in with your Moodle account.';
$string['edit_in_skilland_header'] = 'Edit in Skilland';
$string['edit_in_skilland_header_desc'] = 'Edit content for course "{$a}" in Skilland platform';

// Lesson selection.
$string['select_all'] = 'Select All';
$string['deselect_all'] = 'Deselect All';

// Configure Skilland button.
$string['configure_skilland'] = 'Configure Skilland';
$string['configure_skilland_desc'] = 'Set up Skilland course mapping for this course';
$string['go_to_skilland'] = 'Go to Skilland';

// Create in Skilland.
$string['create_in_skilland'] = '+ Create in Skilland';
$string['creating_course'] = 'Creating course in Skilland...';

// Auto-update / scheduled task.
$string['task_sync_content'] = 'Sync Skilland content for auto-update activities';
$string['update_available'] = 'Update Available';
$string['update_available_desc'] = 'Content has been updated in Skilland. Click the button to refresh the SCORM package.';

// Plugin disabled.
$string['error_plugin_disabled'] = 'The Skilland plugin is currently disabled.';

// Errors.
$string['invalidactivity'] = 'Invalid activity specified.';
$string['error_missing_parameters'] = 'Missing required parameters for SSO redirect.';
