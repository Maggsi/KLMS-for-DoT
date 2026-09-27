#!/bin/sh
# IDM has no background worker — the PHP built-in server IS the whole service.
# (php:8.4-cli ships no php-fpm, so php -S is the web tier; see the KLMS entrypoint
# for the same reasoning.) The router serves existing static files natively and
# forwards everything else to the front controller.
#
# The web server runs in the FOREGROUND: when it exits, the container exits,
# so a dead IDM is never "healthy" while serving nothing.
set -e

# --- First-time schema bootstrap (no-op on subsequent boots) ---
# Same pattern as klms-entrypoint.sh: only runs when the DB is empty (0 tables
# in public). On a fresh volume this creates the IDM schema before the web
# server starts; on every later boot the check finds tables and skips.
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

exec php -S 0.0.0.0:8095 -t /app/public /idm-router.php
