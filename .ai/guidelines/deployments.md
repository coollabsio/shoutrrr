# Deployments — Docker + Laravel Octane (FrankenPHP)

This project ships a single production Docker image built on
[serversideup/php](https://serversideup.net/open-source/docker-php/) FrankenPHP images,
running the web tier through **Laravel Octane (FrankenPHP worker mode)**. The image is built
from one multi-stage `Dockerfile` (root) and run via two Compose files:
`docker-compose.development.yaml` (builds locally from source) and
`docker-compose.production.yaml` (pulls `ghcr.io/coollabsio/shoutrrr:latest`).

## One image, supervised processes

A single container runs **supervisord** (PID 1, as `www-data`) supervising four programs:

- **octane** — the Octane (FrankenPHP) web server. Always on; the primary process.
- **worker** — `queue:work`. Idles when `QUEUE_WORKER_ENABLED=false`.
- **schedule** — `schedule:work`. Idles when `SCHEDULER_ENABLED=false`.
- **ssr** — `inertia:start-ssr`. Idles unless `INERTIA_SSR_ENABLED=true`.

For a cloud deployment, disable the in-container worker/scheduler via those env vars and run
them as **separate services that override the image CMD**, e.g.
`php artisan queue:work --tries=3 --max-time=3600` or `php artisan schedule:work`.

## Critical facts (do not "fix" these without re-checking)

- **The frankenphp image has NO s6-overlay** (`/init`, `/command/execlineb`, `s6-svscan`,
  `s6-rc` are all absent — s6 only exists in serversideup's `fpm-nginx`/`fpm-apache`
  variants). The image therefore uses **supervisord**, not s6. Do not reintroduce
  s6 service files for this image — they are inert. Config: `docker/supervisord.conf`.
- **Octane uses the image's bundled `frankenphp` binary.** Just `composer require laravel/octane`
  + `octane:start --server=frankenphp`. Do not download a FrankenPHP binary into the project
  (`/frankenphp`, `frankenphp-worker.php`, `**/caddy` are gitignored). `OCTANE_SERVER=frankenphp`.
- **`config/octane.php` is a vendor-published stub** and is excluded from Rector
  (`rector.php` `withSkip`). Don't hand-edit it to satisfy lint/refactor tools.
- **Wayfinder + Docker:** the `wayfinder()` Vite plugin shells out to `php artisan` on every
  `vite build`. The Docker `assets` stage (bun, no PHP) sets `SKIP_WAYFINDER_GENERATE=true`,
  which `vite.config.ts` honors to drop the plugin. The TS is generated in the PHP `vendor`
  stage and copied in. Keep that gate when touching `vite.config.ts`.

## Build & run

```bash
# Build locally from source
APP_KEY="base64:..." docker compose -f docker-compose.development.yaml up -d --build

# Or pull the published image (ghcr.io/coollabsio/shoutrrr:latest)
APP_KEY="base64:..." docker compose -f docker-compose.production.yaml up -d

# Migrations auto-run on boot; to run them manually:
docker compose -f docker-compose.production.yaml exec app php artisan migrate --force
```

Health: the app uses serversideup's native `healthcheck-octane`. Web serves on container
port `8080`.

## Datastore — SQLite (default) or Postgres + Redis

- Default is **SQLite** + the **database** driver for cache/queue/session. Zero external
  services; the SQLite file lives on a named volume at
  `/var/www/html/database/sqlite/database.sqlite` (created by `docker/entrypoint.d/10-init-app.sh`).
- For **Postgres + Redis**, uncomment the `postgres`/`redis` services (and their volumes)
  in the Compose file (`postgres:18-alpine`, `redis:alpine`) and override env.
  In `.env` set `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, **`DB_DATABASE=laravel`**
  (the sqlite-path default is invalid for pgsql — this override is required),
  `DB_USERNAME=laravel`, `DB_PASSWORD=<your-password>`, `CACHE_STORE=redis`,
  `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis`. The `redis` PHP extension is in the image.
- Run migrations after first boot; the database cache/queue/session drivers need their tables.

## Inertia SSR (off by default, runtime-toggleable)

- The SSR bundle is always built (`bun run build:ssr` in the `assets` stage), but SSR is
  controlled at runtime by `INERTIA_SSR_ENABLED` (default `false`, see `config/inertia.php`).
- Set `INERTIA_SSR_ENABLED=true` — the supervised `ssr` program boots
  `inertia:start-ssr --runtime=bun` instead of idling.

## Conventions when changing the Docker setup

- The supervised process commands live in `docker/supervisord.conf`
  (`queue:work --tries=3 --max-time=3600`, `schedule:work`, the octane flags). Keep the worker/
  scheduler/ssr guards (`QUEUE_WORKER_ENABLED` / `SCHEDULER_ENABLED` / `INERTIA_SSR_ENABLED`)
  so processes can be toggled off and run as separate cloud services.
- New runtime env knobs should be documented in `.env.example` (Docker section) and wired
  through the compose `x-environment` anchor.
- Keep the image non-root (`www-data`); rely on Docker named-volume initialization (which copies
  image dir ownership) rather than chowning volumes at runtime.
