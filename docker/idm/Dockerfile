# syntax=docker/dockerfile:1
FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libxml2-dev libicu-dev \
    && docker-php-ext-install pdo_pgsql pgsql xml mbstring intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && composer dump-autoload --no-dev --classmap-authoritative \
    && mkdir -p var/cache var/log

EXPOSE 8095

# IDM has no worker; php-fpm in background + tail to keep container alive
CMD ["sh", "-c", "php-fpm -D && tail -f /dev/null"]
