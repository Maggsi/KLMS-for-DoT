#!/bin/sh
# Entrypoint: run ONE process (one container = one process).
#
# The compose stack runs this image as TWO containers (see compose.yaml):
#   - klms        (arg: web)    -> php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php
#   - klms-worker (arg: worker) -> php bin/console messenger:consume async --time-limit=3600
#
# Why two containers and not one supervised process:
#   - the Docker convention is one process per container; the container runtime
#     (restart: unless-stopped) is the supervisor — when the worker exits after
#     its --time-limit, the container is restarted with a fresh worker,
#   - per-tier healthchecks: the web container is probed over HTTP, the worker
#     container over its process list — a dead tier can no longer mask as healthy,
#   - independent scaling/logs: web and worker restarts no longer share a PID 1.
#
# Why php -S and not php-fpm: the php:8.4-cli base image ships no php-fpm
# binary. php -S is single-threaded (fine for a LAN party) and needs the
# router script for correct MIME types on static assets.
set -e
case "${1:-}" in
  web)
    exec php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php
    ;;
  worker)
    exec php bin/console messenger:consume async --time-limit=3600
    ;;
  *)
    echo "usage: klms-entrypoint.sh {web|worker}" >&2
    exit 1
    ;;
esac
