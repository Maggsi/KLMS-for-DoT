#!/bin/sh
# Two processes:
#   1. PHP built-in server (background) — serves /app/public on port 8094
#      (router script = /app/public/index.php, the Symfony front controller)
#   2. messenger worker (foreground) — the main process; when it exits, the container exits
#
# NOTE: the plan's php-fpm lines are intentionally removed — the php:8.4-cli base
# image ships NO php-fpm binary (and its socket would be :9000, not :8095).
# The built-in server IS the web tier here (test env; see README for the prod path).

php -S 0.0.0.0:8094 -t /app/public /app/public/index.php &
exec php bin/console messenger:consume async --time-limit=3600
