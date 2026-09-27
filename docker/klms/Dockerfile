# syntax=docker/dockerfile:1
# Pinned digest (was the floating php:8.4-cli tag) for reproducible builds.
# Re-pin when you intentionally want a newer PHP:
#   podman pull php:8.4-cli && podman image inspect php:8.4-cli --format '{{.Digest}}'
FROM php:8.4-cli@sha256:c4723acd1cacb5df50df3b4866730f4bcccde1b5c0948ab49ae615fb7c6c9a01

# --- system deps for PHP extensions ---
# (no xdebug: this is a prod image)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev libpng-dev libxml2-dev libicu-dev libcurl4-openssl-dev \
        libzip-dev libonig-dev unzip curl \
    && docker-php-ext-install pdo_pgsql pgsql gd intl xml mbstring curl zip bcmath \
    && rm -rf /var/lib/apt/lists/*

# --- composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Bake code (vendor/ built at image build time).
# .dockerignore keeps node_modules/, tests/, var/ etc. out of the context.
COPY . .
# Minimal placeholder .env so Dotenv::loadEnv('/app/.env') finds the file.
# (Real values are injected at runtime via compose `environment:`; the secret
#  .env stays on the host and is NOT baked in.)
COPY docker/.env.placeholder .env
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts \
    && composer dump-autoload --no-dev \
    && mkdir -p var/cache var/log

# Writable dirs (also mounted as volumes at runtime; mkdir so the image works standalone)
RUN mkdir -p public/images/news public/images/user public/images/site \
    public/images/sponsor public/images/gallery public/images/beamernews \
    public/media public/uploads/tourney-rules

EXPOSE 8094

COPY docker/klms-entrypoint.sh /klms-entrypoint.sh
COPY docker/klms-router.php /app/docker/klms-router.php
RUN chmod +x /klms-entrypoint.sh

# One container = one process. Compose runs this image twice with different
# arguments (see compose.yaml): `web` (php -S) and `worker` (messenger:consume).
# No CMD default: a bare `docker run` with no arg exits with a usage error,
# which is the desired behaviour.
ENTRYPOINT ["/klms-entrypoint.sh"]
