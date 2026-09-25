<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'moodle-db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = 'moodlepassword';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = array(
    'dbpersist' => false,
    'dbsocket'  => false,
    'dbport'    => '',
);

$CFG->wwwroot   = 'http://localhost:8081';
$CFG->dataroot  = '/var/moodledata';
$CFG->directorypermissions = 02777;
$CFG->admin = 'admin';

// Local Skilland wiring. The SSO secret comes from the monorepo .env (MOODLE_SSO_SECRET),
// never from the plugin source, and is forced so the admin form cannot drift from it.
$skillandssosecret = getenv('SKILLAND_SSO_SECRET');
if (!empty($skillandssosecret)) {
    $CFG->forced_plugin_settings['mod_skilland'] = [
        'sso_secret' => $skillandssosecret,
        'graphql_endpoint' => getenv('SKILLAND_GRAPHQL_ENDPOINT') ?: 'http://host.docker.internal:8000/graphql',
        'frontend_url' => getenv('SKILLAND_FRONTEND_URL') ?: 'http://host.docker.internal:3100',
    ];
    // The dev stack talks to the host over plain http.
    $CFG->mod_skilland_allow_http = true;
}
unset($skillandssosecret);

require_once(__DIR__ . '/lib/setup.php');
