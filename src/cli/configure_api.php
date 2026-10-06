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
 * Development-only CLI script to configure the Skilland plugin API settings.
 *
 * Not shipped in the release zip. The API key is never taken from the command line
 * (it would land in the shell history and the process list): it is read from the
 * SKILLAND_API_KEY environment variable or from a prompt with echo off.
 *
 * @package    mod_skilland
 * @copyright  2024
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'endpoint' => '',
        'orgid' => '',
        'allow-insecure' => false,
        'no-apikey' => false,
        'apikey' => null,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error(get_string('cliunknowoption', 'admin', $unrecognized));
}

if ($options['help']) {
    $help = <<<EOT
Configure the Skilland plugin API settings (development only).

Only the settings you pass are written; everything else is left unchanged.

Options:
  --endpoint=URL      Skilland URL, the address of the Skilland site (e.g. https://app.skilland.ai).
                      A trailing /graphql or /api/moodle is dropped. Must use https:// unless
                      --allow-insecure is passed or config.php sets \$CFG->mod_skilland_allow_http.
  --orgid=ID          Organization id (letters, digits, "_" and "-" only).
  --allow-insecure    Accept an http:// endpoint (local development stacks only).
  --no-apikey         Do not prompt for the API key.
  -h, --help          Print this help.

API key:
  The key is never accepted as an option. It is read from the SKILLAND_API_KEY environment
  variable; otherwise, when run from a terminal, you are prompted for it with echo off
  (press Enter to leave it unchanged). It is never printed.

Example:
  SKILLAND_API_KEY=... php mod/skilland/cli/configure_api.php \\
      --endpoint=https://app.skilland.ai --orgid=your-org-id

EOT;
    echo $help;
    exit(0);
}

if ($options['apikey'] !== null) {
    cli_error('--apikey is not accepted: it would expose the key in the shell history and the process list. ' .
        'Set the SKILLAND_API_KEY environment variable instead, or run interactively to be prompted.');
}

/**
 * Prompt for the API key on the terminal with echo off.
 *
 * @return string|null The key, or null when it could not be read safely.
 */
function mod_skilland_cli_prompt_apikey(): ?string {
    $saved = [];
    $rc = 1;
    @exec('stty -g 2>/dev/null', $saved, $rc);
    if ($rc !== 0 || empty($saved)) {
        cli_problem('Cannot turn off terminal echo (stty unavailable): skipping the API key prompt. ' .
            'Set SKILLAND_API_KEY to update the key.');
        return null;
    }

    // Restore the terminal however the prompt ends: normally, on Ctrl-C, or on a fatal error.
    $restored = false;
    $restore = function () use ($saved, &$restored): void {
        if ($restored) {
            return;
        }
        $restored = true;
        @exec('stty ' . escapeshellarg(trim($saved[0])) . ' 2>/dev/null');
    };
    register_shutdown_function($restore);
    $signals = function_exists('pcntl_signal') && function_exists('pcntl_async_signals');
    if ($signals) {
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($restore): void {
            $restore();
            echo "\n";
            exit(130);
        });
    }

    echo 'API key (input hidden, Enter to leave unchanged): ';
    try {
        @exec('stty -echo 2>/dev/null');
        $line = fgets(STDIN);
    } finally {
        $restore();
        if ($signals) {
            pcntl_signal(SIGINT, SIG_DFL);
        }
        echo "\n";
    }

    return $line === false ? null : trim($line);
}

$apikey = getenv('SKILLAND_API_KEY');
if ($apikey === false || trim($apikey) === '') {
    $apikey = null;
    if (!$options['no-apikey'] && function_exists('stream_isatty') && stream_isatty(STDIN)) {
        $apikey = mod_skilland_cli_prompt_apikey();
    }
}

try {
    $writes = \mod_skilland\local\cli_config::plan($options, $apikey);
} catch (\InvalidArgumentException $e) {
    cli_error($e->getMessage());
}

echo "Configuring Skilland plugin...\n\n";

foreach ($writes as $name => $value) {
    set_config($name, $value, 'mod_skilland');
}

if (isset($writes['graphql_endpoint'])) {
    echo 'Skilland URL: ' . $writes['graphql_endpoint'] . "\n";
}
if (isset($writes['orgid'])) {
    echo 'Organization ID: ' . $writes['orgid'] . "\n";
}
echo 'API key: ' . (isset($writes['apikey']) ? 'updated' : 'unchanged') . "\n";

echo "\nTesting connection...\n";
require_once($CFG->dirroot . '/mod/skilland/locallib.php');

try {
    mod_skilland_rest_get('/api/moodle/skills');
    echo "Connection successful\n";
} catch (\Throwable $e) {
    echo 'Connection failed: ' . $e->getMessage() . "\n";
    $endpoint = skilland_get_frontend_url();
    if (
        strtolower((string) parse_url(trim($endpoint), PHP_URL_SCHEME)) === 'http' &&
        empty($CFG->mod_skilland_allow_http)
    ) {
        echo "Hint: http:// endpoints are also blocked at request time unless config.php sets " .
            "\$CFG->mod_skilland_allow_http = true; --allow-insecure only lets this script save one.\n";
    }
    exit(1);
}

exit(0);
