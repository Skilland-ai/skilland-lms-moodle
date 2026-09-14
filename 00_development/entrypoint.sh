#!/bin/bash
set -e

# Wait for MariaDB to be ready
wait_for_db() {
    echo "Waiting for MariaDB..."
    while ! mysqladmin ping -h "$MOODLE_DB_HOST" -u "$MOODLE_DB_USER" -p"$MOODLE_DB_PASS" --skip-ssl --silent 2>/dev/null; do
        sleep 2
    done
    echo "MariaDB is ready"
}

# Sync Skilland plugin from staging to Moodle mod directory
# This is necessary because Docker bind mounts don't work into named volumes
sync_plugin() {
    echo "Syncing Skilland plugin..."
    mkdir -p /var/www/html/mod/skilland

    if [ -d "/opt/skilland-plugin" ]; then
        cp -r /opt/skilland-plugin/* /var/www/html/mod/skilland/
    fi

    chown -R www-data:www-data /var/www/html/mod/skilland
    echo "Skilland plugin synced successfully"
}

# Check if Moodle is installed (mdl_config table exists with version)
is_installed() {
    mysql -h "$MOODLE_DB_HOST" -u "$MOODLE_DB_USER" -p"$MOODLE_DB_PASS" --skip-ssl "$MOODLE_DB_NAME" \
        -e "SELECT 1 FROM mdl_config WHERE name='version' LIMIT 1" 2>/dev/null | grep -q 1
}

# Run Moodle CLI installation
install_moodle() {
    echo "Installing Moodle..."
    php /var/www/html/admin/cli/install_database.php \
        --agree-license \
        --adminuser="$MOODLE_ADMIN_USER" \
        --adminpass="$MOODLE_ADMIN_PASS" \
        --adminemail="$MOODLE_ADMIN_EMAIL" \
        --fullname="$MOODLE_SITE_FULLNAME" \
        --shortname="$MOODLE_SITE_SHORTNAME"
    echo "Moodle installation complete"
}

# Create test users for E2E tests (teacher and student with proper roles)
create_test_users() {
    echo "Creating test users..."
    php /opt/moodle-setup/create_test_users.php
}

# Main logic
wait_for_db
sync_plugin

if is_installed; then
    echo "Moodle is already installed, skipping installation"
else
    install_moodle
    create_test_users
fi

# Start Apache
exec apache2-foreground
