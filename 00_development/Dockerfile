FROM php:8.1-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    libxml2-dev \
    libpq-dev \
    git \
    unzip \
    util-linux \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd zip intl mysqli pdo pdo_mysql opcache soap exif

# Configure PHP settings for Moodle
RUN echo "max_input_vars = 5000" > /usr/local/etc/php/conf.d/moodle.ini \
    && echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/moodle.ini \
    && echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/moodle.ini

# Enable Apache modules
RUN a2enmod rewrite

# Prepare directories with correct ownership BEFORE cloning
WORKDIR /var/www/html
RUN chown www-data:www-data /var/www/html

# Create moodledata directory
RUN mkdir /var/moodledata && chown www-data:www-data /var/moodledata

# Clone Moodle as www-data user (no chown -R needed)
USER www-data
ARG MOODLE_TAG=v4.5.15
RUN git clone --branch "$MOODLE_TAG" --depth 1 https://github.com/moodle/moodle.git .

# Copy config.php (use --chown to set ownership during copy)
USER root
COPY --chown=www-data:www-data config.php /var/www/html/config.php

# Install mariadb-client for database health checks
RUN apt-get update && apt-get install -y mariadb-client && rm -rf /var/lib/apt/lists/*

# Copy the development setup helper
RUN mkdir -p /opt/moodle-setup
COPY create_test_users.php /opt/moodle-setup/

# Copy entrypoint script
COPY --chmod=755 entrypoint.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
