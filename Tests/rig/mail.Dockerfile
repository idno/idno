# The image Tests/rig/mail.sh runs the mail delivery test in: PHP 8.4 on the command line with the
# extensions composer.json requires (pdo_sqlite is in the base image). The build context is a copy of the
# checkout the script makes (never the checkout itself), so nothing done in the container reaches the
# developer's files.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip procps pkg-config libssl-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd gettext pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
# The dependencies first, so that the layer is reused while composer.json does not change.
COPY app/composer.* ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader
COPY app/ ./
RUN composer dump-autoload --no-interaction
