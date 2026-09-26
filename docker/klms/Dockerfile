# syntax=docker/dockerfile:1
FROM php:8.4-cli

# --- system deps for PHP extensions ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libpng-dev libxml2-dev libicu-dev libcurl4-openssl-dev \
        libzip-dev libonig-dev unzip \
    && docker-php-ext-install pdo_pgsql pgsql gd intl xml mbstring curl zip bcmath \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /var/lib/apt/lists/*

# --- composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Bake code (vendor/ built at image build time)
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && composer dump-autoload --no-dev \
    && mkdir -p var/cache var/log

# Writable dirs (also mounted as volumes at runtime; mkdir so the image works standalone)
RUN mkdir -p public/images/news public/images/user public/images/site \
    public/images/sponsor public/images/gallery public/images/beamernews \
    public/media public/uploads/tourney-rules

# Frontend build is baked in from the build context (see build script, Task 3.1)
# The build script copies public/build/ into the image BEFORE this step is re-run.

EXPOSE 8094

COPY docker/klms-entrypoint.sh /klms-entrypoint.sh
COPY docker/klms-router.php /app/docker/klms-router.php
RUN chmod +x /klms-entrypoint.sh

ENTRYPOINT ["/klms-entrypoint.sh"]
