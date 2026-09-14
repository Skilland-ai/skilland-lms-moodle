<?php
/**
 * CLI script to configure Skilland plugin API settings.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Get CLI options.
list($options, $unrecognized) = cli_get_params(
    array(
        'endpoint' => 'http://host.docker.internal:8000/graphql',
        'orgid' => '',
        'apikey' => '',
        'help' => false,
    ),
    array(
        'h' => 'help',
    )
);

if ($options['help']) {
    echo "Configure Skilland plugin API settings.\n\n";
    echo "Options:\n";
    echo "  --endpoint=URL    API endpoint URL (default: http://host.docker.internal:8000/graphql)\n";
    echo "  --orgid=ID        Organization ID\n";
    echo "  --apikey=KEY      API key\n";
    echo "  -h, --help        Print this help\n\n";
    echo "Example:\n";
    echo "  php configure_api.php --orgid=your-org-id --apikey=your-api-key\n\n";
    exit(0);
}

// Set configuration values.
echo "Configuring Skilland plugin...\n\n";

set_config('graphql_endpoint', $options['endpoint'], 'mod_skilland');
echo "Endpoint: " . $options['endpoint'] . "\n";

if (!empty($options['orgid'])) {
    set_config('orgid', $options['orgid'], 'mod_skilland');
    echo "Organization ID: " . $options['orgid'] . "\n";
}

if (!empty($options['apikey'])) {
    set_config('apikey', $options['apikey'], 'mod_skilland');
    echo "API Key: " . substr($options['apikey'], 0, 10) . "***\n";
}

echo "\nConfiguration saved successfully!\n";

// Test the connection.
echo "\nTesting connection...\n";
require_once($CFG->dirroot . '/mod/skilland/locallib.php');

try {
    $query = <<<'GRAPHQL'
{
  __typename
}
GRAPHQL;

    $result = mod_skilland_graphql($query, []);
    echo "✓ Connection successful!\n";
    print_r($result);
} catch (Exception $e) {
    echo "✗ Connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);

