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

## First-time setup (clone → admin UI)

A complete, from-scratch run: from a fresh clone to logging into the admin UI and
configuring from there. (The Quick start above is the short form; this is the full
walkthrough. Refine freely.)

### 1. Clone

```bash
git clone https://github.com/Maggsi/KLMS-for-SSP.git && cd KLMS-for-SSP
# The IDM fork's code is NOT in this repo — clone it separately (build context):
git clone https://github.com/mrhund/IDM-for-DoT.git
```

Requires `podman` + `podman-compose` (docker 29 has **no** compose plugin — never
`docker compose`).

### 2. Build the two images

```bash
podman build -f docker/idm/Dockerfile -t klms-dot-idm /path/to/IDM-for-DoT
podman build -f docker/klms/Dockerfile -t klms-dot_klms .
```

### 3. Set up the environment

```bash
cd docker/
./setup.sh
```

`setup.sh` walks you through `docker/.env` (each value with a one-line explanation +
a default), writes it (mode 600), and runs `podman-compose up -d`. The values:
postgres superuser; the **two-tier** DB users (app user owns the DB, migration user
has all rights); KLMS `APP_SECRET` (signs sessions/CSRF), the **IDM API key + auth**
(shared secret between the two apps), `MAILER_DSN` (`null://null` = no mail),
`SITE_BASE_HOST` (what you browse to, e.g. `192.168.0.170:8094`); IDM `APP_SECRET`.
It derives the two `DATABASE_URL`s from the user/password/db-name you gave.
(Prefer manual? `cp .env.example .env`, edit, `podman-compose up -d`.)

### 4. First boot (automatic)

On first boot postgres runs `initdb/setup.sh` (DBs + two-tier roles) and **both
entrypoints auto-create the schema** (0 tables → `doctrine:schema:create` → start).
A fresh `up -d` just works. Give it a minute, then:

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://<SITE_BASE_HOST>/   # → 200
```

### 5. Create your first admin (the critical step)

A fresh stack has **no users** — the first one is created in the IDM (the identity
backend), via the IDM console. Two things:

```bash
# (a) the API key KLMS uses to reach IDM — value MUST MATCH docker/.env KLMS_IDM_APIKEY
podman exec klms-dot_idm_1 php bin/console app:apikeys:create klms <your-api-key>

# (b) your admin user (--confirmed so it's not stuck in the email-verification flow)
podman exec klms-dot_idm_1 php bin/console app:user:create you@example.com <password> <nickname> --confirmed
```

The API key is the linchpin: the same value lives in `docker/.env`
(`KLMS_IDM_APIKEY`) and must exist in IDM's `api_key` table, or login fails.

### 6. Log in to the admin UI

Open `http://<SITE_BASE_HOST>/` → the login form (field is `username`, i.e. your
email) → enter the email + password from step 5 → you land in the admin UI at
`/admin`.

### 7. Configure from there

From the admin UI set the site's real values (title, logo, mail sender if using a
real `MAILER_DSN`, recaptcha, …) via the Settings (Einstellungen) screens, then do
normal CMS administration (pages, users, news).

### The two things that bite a first-timer
1. **The API key must match in both places** (`docker/.env` and IDM) — the #1
   "login fails" cause.
2. **The first user is created via the IDM console** — a fresh instance has no
   self-signup, so `app:user:create` is the only way in.

> **Production note:** this is the bigboy test stack (port 8094, `null://null`
> mail). For a real deploy, point `SITE_BASE_HOST`/`MAILER_DSN` at production values
> and put a reverse proxy (nginx/Traefik) in front — swapping `php -S` for
> `php-fpm` is only an entrypoint + service change.

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
