<?php
/**
 * Create test users for E2E testing
 *
 * This script creates teacher1 and student1 users with appropriate roles
 * for automated testing. It's idempotent - safe to run multiple times.
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/moodlelib.php');

// Test users to create
$users = [
    [
        'username'  => 'teacher1',
        'password'  => 'Teacher123!',
        'firstname' => 'Test',
        'lastname'  => 'Teacher',
        'email'     => 'teacher1@example.com',
        'role'      => 'editingteacher'
    ],
    [
        'username'  => 'student1',
        'password'  => 'Student123!',
        'firstname' => 'Test',
        'lastname'  => 'Student',
        'email'     => 'student1@example.com',
        'role'      => 'student'
    ]
];

foreach ($users as $userdata) {
    // Check if user already exists
    if ($DB->record_exists('user', ['username' => $userdata['username']])) {
        cli_writeln("User {$userdata['username']} already exists, skipping");
        continue;
    }

    // Create user
    $user = new stdClass();
    $user->username = $userdata['username'];
    $user->password = hash_internal_user_password($userdata['password']);
    $user->firstname = $userdata['firstname'];
    $user->lastname = $userdata['lastname'];
    $user->email = $userdata['email'];
    $user->confirmed = 1;
    $user->mnethostid = $CFG->mnet_localhost_id;
    $user->timecreated = time();
    $user->timemodified = time();

    $userid = $DB->insert_record('user', $user);
    cli_writeln("Created user: {$userdata['username']} (id: $userid)");

    // Assign system role (so they have the role site-wide)
    $systemcontext = context_system::instance();
    $roleid = $DB->get_field('role', 'id', ['shortname' => $userdata['role']]);
    if ($roleid) {
        role_assign($roleid, $userid, $systemcontext->id);
        cli_writeln("  Assigned role: {$userdata['role']}");
    }
}

cli_writeln("Test user setup complete");
