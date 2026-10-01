# Moodle 4.5 + PHP 8.3 for the plugin's real-Moodle suites (src/tests); opt-in, see DEVELOPMENT.md.
FROM moodlehq/moodle-php-apache:8.3

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip rsync && rm -rf /var/lib/apt/lists/*
RUN git clone --branch MOODLE_405_STABLE --depth 1 https://github.com/moodle/moodle.git /var/www/html \
    && mkdir -p /var/moodledata /var/behatdata /var/phpunitdata \
    && chown -R www-data:www-data /var/www/html /var/moodledata /var/behatdata /var/phpunitdata
WORKDIR /var/www/html
