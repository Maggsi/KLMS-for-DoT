#!/bin/sh
# One-time DB bootstrap, run by the postgres image on FIRST start (files in
# /docker-entrypoint-initdb.d are executed in alphabetical order). The entrypoint
# pre-sets PGHOST/PGUSER/PGPASSWORD, so no explicit -h/-U is needed.
#
# Creates the two-tier users + databases for both apps (see README "DB users"):
#   * <app>_dot      — application user, OWNS the database (read/write + can
#                       CREATE runtime tables such as messenger_messages)
#   * <app>_dot_mig  — migration user, ALL rights, for doctrine:schema / migrations
# Passwords come from environment variables (compose.yaml / docker/.env), never
# hardcoded here. Idempotent: re-runs are safe (roles via pg_roles guard,
# databases via a pg_database guard).
set -e

for app in klms_dot idm_dot; do
  cap=$(printf "%s" "$app" | sed 's/klms_dot/KLMS/;s/idm_dot/IDM/')
  eval "pw=\${${cap}_DB_PASSWORD}"
  eval "mpw=\${${cap}_MIGRATION_DB_PASSWORD}"
  [ -n "$pw" ] && [ -n "$mpw" ] || { echo "missing password env for $app"; exit 1; }

  # Roles (idempotent — CREATE ROLE has no IF NOT EXISTS, so guard via pg_roles):
  psql -v ON_ERROR_STOP=1 <<EOF
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${app}') THEN
    CREATE ROLE ${app} LOGIN PASSWORD '${pw}';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '${app}_mig') THEN
    CREATE ROLE ${app}_mig LOGIN PASSWORD '${mpw}';
  END IF;
END
\$\$;
EOF

  # Database (idempotent — postgres has no CREATE DATABASE IF NOT EXISTS):
  psql -v ON_ERROR_STOP=1 <<EOF
SELECT 'CREATE DATABASE ${app} OWNER ${app}'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = '${app}');
\gexec
EOF

  psql -v ON_ERROR_STOP=1 -c \
    "GRANT ALL PRIVILEGES ON DATABASE ${app} TO ${app};
     GRANT ALL PRIVILEGES ON DATABASE ${app} TO ${app}_mig;"

  echo "bootstrapped ${app} (owner=${app}, migration=${app}_mig)"
done
