#!/bin/sh
# Entrypoint: hand off to supervisord, which runs BOTH tiers as supervised
# programs (see docker/klms-supervisord.conf):
#   1. web    — php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php
#   2. worker — php bin/console messenger:consume async --time-limit=3600
#
# Why supervisord and not the old hand-rolled watchdog:
#   - autorestart on crash for BOTH tiers — a dead web tier no longer masks as
#     healthy (the exact problem the curl-probing watchdog was a band-aid for),
#   - exactly ONE messenger worker (supervisord runs a single program instance;
#     it only restarts after the process exits),
#   - per-process logging + supervisorctl status.
#
# Why php -S and not php-fpm: the php:8.4-cli base image ships no php-fpm
# binary. php -S is single-threaded (fine for a LAN party) and needs the
# router script for correct MIME types on static assets.
set -e
exec supervisord -c /klms-supervisord.conf
