# Docker stack for KLMS-for-SSP + IDM-for-DoT

A podman-compose stack that runs the KLMS-for-SSP fork (branch `main`) and the
IDM-for-DoT fork (branch `develop`) as a self-contained test environment.

## Quick start

**Option 1 — first-time helper (interactive):**
```bash
cd docker/
./setup.sh
```
If `docker/.env` does not exist, `setup.sh` walks you through the values (with a
brief explanation of each), writes `docker/.env`, then starts the stack. If `.env`
already exists, it just starts the stack (no questions).

**Option 2 — you already have a `.env`:**
```bash
cd docker/
cp .env.example .env        # test values are fine for local
cd ..

# Build both images (the IDM image is built from the separate IDM clone — see below)
podman build -f docker/idm/Dockerfile -t klms-dot-idm \
    /home/max/.hermes/cache/scratch/klms-dot/IDM-for-DoT
podman-compose build        # builds klms (context = this worktree)

podman-compose up -d
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8094/
```

> **Schema bootstrap (automatic):** the databases are created by `initdb/setup.sh`
> (runs on first start). The **tables** are created automatically by the entrypoints
> (`klms-entrypoint.sh` / `idm-entrypoint.sh`) on first boot: each checks whether the
> `public` schema has any tables, and if not, runs `doctrine:schema:create` before
> starting its process. On every later boot the check finds tables and skips it
> (a no-op — it never touches the `messenger_messages` queue on a running stack).
> So a fresh `up -d` just works; no manual `schema:create` needed.

> **Why the IDM image is built separately:** the IDM fork's code is NOT in the KLMS
> worktree. `podman-compose build` resolves a service's `dockerfile` path *relative to
> its build context*, so it cannot build an image whose Dockerfile lives outside the
> context. The `idm` service therefore references a pre-built `klms-dot-idm` image
> (`image:` instead of `build:`); build it with the `podman build -f` command above.

## Services

| Service     | Port | Purpose                                   |
|-------------|------|-------------------------------------------|
| postgres    | 5432 | `klms_dot` + `idm_dot` databases         |
| idm         | 8095 | IDM-for-DoT REST API (internal only)     |
| klms        | 8094 | KLMS-for-SSP web tier (php -S)          |
| klms-worker | —    | messenger worker (internal only)         |

The `idm` and `klms-worker` ports are **not** exposed on the host — KLMS reaches
the IDM over the internal docker network (`http://idm:8095`). Only `klms` (8094)
is published.

The `klms` image is run as **two containers** (one process per container — no
supervisor inside the container):
1. `klms` — `php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php` (web tier)
2. `klms-worker` — `php bin/console messenger:consume async --time-limit=3600` (the worker)

The worker exits after its `--time-limit`; `restart: unless-stopped` brings a
fresh one up (the same "one worker" invariant as the VM's supervisor setup).
Each container has its own healthcheck: the web tier is probed over HTTP, the
worker over its process list — a dead tier can no longer mask as healthy.

## DB users (two-tier)

`initdb/setup.sh` (runs on first start, idempotent) creates, per app:
- **`<app>_dot`** — the application user. **Owns** the database: read/write on all
  tables *and* can CREATE runtime tables (e.g. `messenger_messages`). `DATABASE_URL`
  uses this user.
- **`<app>_dot_mig`** — the migration user, **all rights**, for `doctrine:schema` /
  migrations.

Passwords come from the environment (`.env` / compose), never hardcoded in the image.

## Known quirks

- **`public/media/header_dot_bg.jpg` is not in the repo.** A placeholder is required
  before `yarn encore prod` will succeed (the SCSS references it). Create a 1×1 JPEG
  at that path before building the frontend.
- **The fork's composer lockfile requires PHP ≥ 8.4** (despite `composer.json` saying
  `^8.3` — the locked doctrine/qr-code deps hard-require `^8.4`). Use the `php:8.4-cli`
  base image.
- **`--classmap-authoritative` must NOT be used** for `composer dump-autoload`. The
  console app references dev-only bundles (`DoctrineFixturesBundle`) that are excluded by
  `--no-dev`; an authoritative classmap blocks their (dev-only) autoloading and breaks
  `bin/console` boot.
- **The `php:8.4-cli` base image ships no php-fpm binary.** The plan's original
  `php-fpm -D` lines are removed; the PHP built-in server (`php -S`) is the web tier.
  For a production deploy, swap in an `nginx` service + `php:8.4-fpm` (the VM's current
  setup) — only the entrypoint and a service change.
- **`docker/.env` is gitignored** — never commit real secrets.

## Deploying to the SSP VM

The VM currently runs the fork natively (php8.4-fpm + apache). To deploy this docker
stack to the VM:

1. Copy this worktree's `docker/` directory (and the IDM clone) to the VM.
2. On the VM, create `docker/.env` with real values:
   - `KLMS_DATABASE_URL` → use the real `klms_dot` password from `/root/klms-secrets.txt`
   - `KLMS_IDM_URL` → `http://idm:8095` (docker network)
   - `KLMS_MAILER_DSN` → copy from the VM's existing `/var/www/klms-dot/.env.local`
   - `KLMS_IDM_APIKEY` / `KLMS_IDM_AUTH` → from the VM's `.env.local`
3. Build both images (see Quick start), `podman-compose up -d`.
4. The schema is created automatically on first boot (see "Schema bootstrap (automatic)"); no manual `schema:create` needed.
5. Point Traefik at the container port (or a reverse proxy on the VM).

> **Postgres init scripts only run on first start** (empty `pgdata` volume). To re-init
> with new passwords: `podman-compose down -v` then `up -d`.

## Stopping / cleaning up

```bash
podman-compose down          # stop containers (volumes preserved)
podman-compose down -v      # stop + delete volumes (data lost)
```
