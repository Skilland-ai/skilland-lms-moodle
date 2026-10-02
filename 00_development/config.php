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

// Local Skilland wiring from the monorepo .env, never from the plugin source. Skilland verifies
// every SSO token with the organization's own secret, derived from its master secret
// (MOODLE_SSO_SECRET) as hex(HMAC-SHA256(master, 'skilland:moodle-sso:v1:' . orgId)), and rejects
// the master itself. So the organization id and its derived secret are forced only when
// SKILLAND_ORG_ID is set too; otherwise paste the secret from Skilland > Settings > Integrations >
// Moodle into the plugin settings.
$skillandssosecret = getenv('SKILLAND_SSO_SECRET');
$skillandorgid = getenv('SKILLAND_ORG_ID');
if (!empty($skillandssosecret)) {
    $CFG->forced_plugin_settings['mod_skilland'] = [
        // Skilland URL (config key graphql_endpoint): the Next.js app serving /api/moodle.
        'graphql_endpoint' => getenv('SKILLAND_URL') ?: (getenv('SKILLAND_GRAPHQL_ENDPOINT') ?: 'http://host.docker.internal:3100'),
        'frontend_url' => getenv('SKILLAND_FRONTEND_URL') ?: 'http://host.docker.internal:3100',
    ];
    if (!empty($skillandorgid)) {
        $CFG->forced_plugin_settings['mod_skilland']['orgid'] = $skillandorgid;
        $CFG->forced_plugin_settings['mod_skilland']['sso_secret'] =
            hash_hmac('sha256', 'skilland:moodle-sso:v1:' . $skillandorgid, $skillandssosecret);
    }
    // The dev stack talks to the host over plain http.
    $CFG->mod_skilland_allow_http = true;
}
unset($skillandssosecret, $skillandorgid);

require_once(__DIR__ . '/lib/setup.php');
