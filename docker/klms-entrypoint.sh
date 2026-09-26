#!/bin/sh
# Two processes + a watchdog:
#   1. PHP built-in server (background) — serves /app/public on port 8094
#      (router = /app/docker/klms-router.php: static files natively, rest → index.php)
#   2. Messenger worker (foreground) — the container's main process
#   3. Watchdog (background) — if the web server dies, restart it. Without this,
#      a dead web tier is masked by the healthy worker and the container keeps
#      running "fine" while the site is down.
#
# Why php -S and not php-fpm: the php:8.4-cli base image ships no php-fpm
# binary. php -S is single-threaded (fine for a LAN party) and needs the
# router script for correct MIME types on static assets.

php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php &

# Watchdog: stateless — if the port stops answering, (re)start the web server.
# (curl -s WITHOUT -f: any HTTP response, even a 404, means the server is UP;
#  only a connection refused/timeout (exit 7/28) means it's down. /bin/sh is
#  dash, which has no /dev/tcp, so we probe with curl.)
(
  while true; do
    if ! curl -s -o /dev/null --max-time 3 http://127.0.0.1:8094/ 2>/dev/null; then
      echo "[watchdog] port 8094 not answering, (re)starting web server"
      php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php &
    fi
    sleep 5
  done
) &

exec php bin/console messenger:consume async --time-limit=3600
