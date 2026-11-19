# The image Tests/rig/mongo-import.sh runs the MongoDB import's end-to-end test in (and captures its
# fixture in): PHP 8.4 on the command line with the extensions composer.json requires, pdo_sqlite (in
# the base image), pdo_mysql, and the mongodb extension, which the import needs and which a
# developer's own PHP usually lacks. The build context is a copy of the checkout the script makes
# (never the checkout itself), so nothing done in the container reaches the developer's files.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip procps pkg-config libssl-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd gettext pdo_mysql zip \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
# The dependencies first, so that the layer is reused while composer.json does not change.
COPY app/composer.* ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader
COPY app/ ./
RUN composer dump-autoload --no-interaction
