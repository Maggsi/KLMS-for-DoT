# Docker stack for KLMS-for-SSP + IDM-for-DoT

A podman-compose stack that runs the KLMS-for-SSP fork (branch `main`) and the
IDM-for-DoT fork (branch `develop`) as a self-contained test environment.

## Quick start (local test env on bigboy)

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

> **Schema bootstrap:** the databases are created by `initdb/setup.sh` (runs on first
> start). To create the tables, run `doctrine:schema:create` **while the messenger
> worker is not running** — the worker auto-creates `messenger_messages` at boot and
> races the command. Easiest: `podman stop klms-dot_klms_1` first, then
> `podman run --rm --entrypoint php --network klms-dot_default -e APP_ENV=prod
> -e APP_SECRET=... -e DATABASE_URL=... klms-dot_klms bin/console
> doctrine:schema:create --no-interaction` (same for `idm_dot`), then
> `podman start klms-dot_klms_1`.

> **Why the IDM image is built separately:** the IDM fork's code is NOT in the KLMS
> worktree. `podman-compose build` resolves a service's `dockerfile` path *relative to
> its build context*, so it cannot build an image whose Dockerfile lives outside the
> context. The `idm` service therefore references a pre-built `klms-dot-idm` image
> (`image:` instead of `build:`); build it with the `podman build -f` command above.

## Services

| Service  | Port | Purpose                              |
|----------|------|--------------------------------------|
| postgres | 5432 | `klms_dot` + `idm_dot` databases     |
| idm      | 8095 | IDM-for-DoT REST API (internal only) |
| klms     | 8094 | KLMS-for-SSP web + messenger worker  |

The `idm` port is **not** exposed on the host — KLMS reaches it over the internal
docker network (`http://idm:8095`). Only `klms` (8094) is published.

The `klms` container runs **three** processes via `docker/klms-entrypoint.sh`:
1. `php -S 0.0.0.0:8094 -t /app/public /app/docker/klms-router.php` (web tier)
2. `php bin/console messenger:consume async --time-limit=3600` (the worker, foreground)
3. a **watchdog** that re-starts the web tier if its port stops answering — so a
   dead web tier does not leave the container "healthy" while serving nothing.

This preserves the "exactly one messenger worker" invariant (same as the VM's supervisor
setup) without a second container.

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
4. Run `doctrine:schema:create` on both (first run only).
5. Point Traefik at the container port (or a reverse proxy on the VM).

> **Postgres init scripts only run on first start** (empty `pgdata` volume). To re-init
> with new passwords: `podman-compose down -v` then `up -d`.

## Stopping / cleaning up

```bash
podman-compose down          # stop containers (volumes preserved)
podman-compose down -v      # stop + delete volumes (data lost)
```
