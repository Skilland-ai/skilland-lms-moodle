#!/bin/bash
set -euo pipefail

# Fail before touching the database when the host build is absent.
if [ ! -s /var/www/html/mod/skilland/version.php ]; then
    echo "ERROR: Skilland dist/version.php is missing. Run npm ci && npm run build in moodle/, then restart Moodle." >&2
    exit 1
fi

wait_for_db() {
    local attempt
    echo "Waiting for MariaDB (up to 120 seconds)..."
    for attempt in {1..40}; do
        if timeout 1s mysql -h "$MOODLE_DB_HOST" -u "$MOODLE_DB_USER" -p"$MOODLE_DB_PASS" \
            --skip-ssl --connect-timeout=1 "$MOODLE_DB_NAME" -Nse 'SELECT 1' >/dev/null 2>&1; then
            echo "MariaDB is ready"
            return 0
        fi
        sleep 2
    done
    echo "ERROR: MariaDB did not become ready. Check docker compose logs moodle-db." >&2
    return 1
}

is_installed() {
    mysql -h "$MOODLE_DB_HOST" -u "$MOODLE_DB_USER" -p"$MOODLE_DB_PASS" \
        --skip-ssl --connect-timeout=5 "$MOODLE_DB_NAME" \
        -Nse "SELECT 1 FROM mdl_config WHERE name='version' LIMIT 1" 2>/dev/null | grep -qx 1
}

moodle_php() {
    runuser -u www-data -- php "$@"
}

wait_for_db

if is_installed; then
    echo "Moodle is already installed"
else
    echo "Installing Moodle..."
    moodle_php /var/www/html/admin/cli/install_database.php \
        --agree-license \
        --adminuser="$MOODLE_ADMIN_USER" \
        --adminpass="$MOODLE_ADMIN_PASS" \
        --adminemail="$MOODLE_ADMIN_EMAIL" \
        --fullname="$MOODLE_SITE_FULLNAME" \
        --shortname="$MOODLE_SITE_SHORTNAME"
    moodle_php /opt/moodle-setup/create_test_users.php
fi

# Discover new plugin versions and language/template changes on every start.
moodle_php /var/www/html/admin/cli/upgrade.php --non-interactive
moodle_php /var/www/html/admin/cli/purge_caches.php

exec apache2-foreground
