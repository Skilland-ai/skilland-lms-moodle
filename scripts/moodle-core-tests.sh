#!/usr/bin/env bash
# Run the real-Moodle suites (src/tests) locally, as moodle-plugin-ci does in CI.
#
#   scripts/moodle-core-tests.sh phpunit [filter]   mod_skilland_testsuite (PHPUnit --filter)
#   scripts/moodle-core-tests.sh behat [name]       Behat @mod_skilland (a feature path or scenario name is optional)
#   scripts/moodle-core-tests.sh down               stop the stack and drop its volumes
#
# Opt-in: the first run builds a Moodle 4.5 image and initialises the test sites (several minutes,
# a few GB of Docker disk); later runs only sync src/ and re-run. Behat needs the selenium chrome service.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose=(docker compose -f "$root/core-tests/docker-compose.yml")
mode="${1:-}"
filter="${2:-}"

if [[ "$mode" != "phpunit" && "$mode" != "behat" && "$mode" != "down" ]]; then
  sed -n '2,10p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit 2
fi

if [[ "$mode" == "down" ]]; then
  "${compose[@]}" --profile behat down -v
  exit 0
fi

if [[ ! -f "$root/vendor/autoload.php" ]]; then
  echo "vendor/ is missing: run 'composer install' (or npm run build) in the plugin repo first" >&2
  exit 1
fi

if [[ "$mode" == "behat" ]]; then
  "${compose[@]}" --profile behat up -d --build --wait db moodle chrome
else
  "${compose[@]}" up -d --build --wait db moodle
fi

run() { "${compose[@]}" exec -T -u www-data moodle "$@"; }

# Mirror CI: the plugin is src/ installed as mod/skilland, with the root vendor/ staged into it
# (src/vendor/autoload.php is a dev shim pointing at the repository root).
# shellcheck disable=SC2016 # the script runs in the container, not here
"${compose[@]}" exec -T moodle bash -c '
  set -e
  mkdir -p /var/www/html/mod/skilland /var/behatdata/faildump
  chown www-data:www-data /var/behatdata/faildump
  rsync -a --delete /plugin-src/ /var/www/html/mod/skilland/
  rsync -a /plugin-vendor/ /var/www/html/mod/skilland/vendor/
  chown -R www-data:www-data /var/www/html/mod/skilland
  cat > /var/www/html/config.php <<PHP
<?php
unset(\$CFG);
global \$CFG;
\$CFG = new stdClass();
\$CFG->dbtype = "mariadb";
\$CFG->dblibrary = "native";
\$CFG->dbhost = "db";
\$CFG->dbname = "moodle";
\$CFG->dbuser = "root";
\$CFG->dbpass = "";
\$CFG->prefix = "mdl_";
\$CFG->dboptions = ["dbcollation" => "utf8mb4_unicode_ci"];
\$CFG->wwwroot = "http://moodle";
\$CFG->dataroot = "/var/moodledata";
\$CFG->admin = "admin";
\$CFG->directorypermissions = 02777;
\$CFG->phpunit_prefix = "phpu_";
\$CFG->phpunit_dataroot = "/var/phpunitdata";
\$CFG->behat_prefix = "bht_";
\$CFG->behat_dataroot = "/var/behatdata";
\$CFG->behat_wwwroot = "http://moodle:8000";
\$CFG->behat_profiles = ["chrome" => [
    "browser" => "chrome",
    "wd_host" => "http://chrome:4444/wd/hub",
    "capabilities" => ["extra_capabilities" => ["goog:chromeOptions" => ["args" => ["--headless=new", "--no-sandbox"]]]],
]];
\$CFG->behat_faildump_path = "/var/behatdata/faildump";
require_once(__DIR__ . "/lib/setup.php");
PHP
  chown www-data:www-data /var/www/html/config.php
'

if ! run test -x vendor/bin/phpunit; then
  "${compose[@]}" exec -T moodle bash -c 'cd /var/www/html && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist && chown -R www-data:www-data vendor'
fi

if [[ "$mode" == "phpunit" ]]; then
  # init.php re-runs cheaply once the phpunit tables exist and picks up new plugin files.
  run env COMPOSER_HOME=/tmp/composer php admin/tool/phpunit/cli/init.php
  args=(--testsuite mod_skilland_testsuite --fail-on-warning)
  [[ -n "$filter" ]] && args+=(--filter "$filter")
  run vendor/bin/phpunit "${args[@]}"
else
  run env COMPOSER_HOME=/tmp/composer php admin/tool/behat/cli/init.php --no-composer-self-update
  # Behat drives the site over http://moodle:8000 from the chrome container.
  "${compose[@]}" exec -T -d -u www-data moodle php -S 0.0.0.0:8000 -t /var/www/html || true
  args=(--config /var/behatdata/behatrun/behat/behat.yml --profile chrome --tags=@mod_skilland)
  [[ -n "$filter" ]] && args+=(--name "$filter")
  run vendor/bin/behat "${args[@]}"
fi
