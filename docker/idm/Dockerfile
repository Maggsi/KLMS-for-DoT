# syntax=docker/dockerfile:1
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libxml2-dev libicu-dev libonig-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql xml mbstring intl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && composer dump-autoload --no-dev \
    && mkdir -p var/cache var/log

EXPOSE 8095

# IDM has no worker; the PHP built-in server is the web tier (php:8.4-cli has no php-fpm).
# The router (from the KLMS worktree's docker/ dir) serves static files natively
# and forwards the rest to the front controller.
COPY docker/idm-router.php /idm-router.php
CMD ["sh", "-c", "php -S 0.0.0.0:8095 -t /app/public /idm-router.php"]
