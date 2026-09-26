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

# --- First-time schema bootstrap (no-op on subsequent boots) ---
# On a fresh DB (0 tables in public), create the schema before starting the
# main process. This runs before the worker starts, so there's no race with
# the messenger transport auto-creating messenger_messages. On subsequent boots
# the DB has tables, so the pg check returns non-zero and we skip schema:create
# entirely (no console boot, no wasted time).
TABLES=$(php -r '
  $u = parse_url(getenv("DATABASE_URL") ?: "");
  if (empty($u["host"])) { echo "0"; exit; }
  $c = @pg_connect("host={$u["host"]} port=" . ($u["port"] ?? 5432) . " dbname=" . ltrim($u["path"], "/") . " user={$u["user"]} password=" . ($u["pass"] ?? ""));
  if (!$c) { echo "0"; exit; }
  $r = pg_query($c, "SELECT count(*) FROM information_schema.tables WHERE table_schema='"'"'public'"'"'");
  echo $r ? pg_fetch_result($r, 0, 0) : "0";
' 2>/dev/null)
if [ "${TABLES:-0}" = "0" ]; then
  echo "First boot: creating database schema..."
  php bin/console doctrine:schema:create 2>&1 | grep -vE 'deprecation|Deprecated' || true
  echo "Schema bootstrap done."
fi

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
