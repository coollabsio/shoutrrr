# syntax=docker/dockerfile:1

# ============================================================
# Build arguments
# ============================================================
# https://hub.docker.com/r/serversideup/php/tags?name=frankenphp
ARG SERVERSIDEUP_PHP_VERSION=8.5-frankenphp-trixie
# https://www.postgresql.org/support/versioning/
ARG POSTGRES_VERSION=17
ARG USER_ID=9999
ARG GROUP_ID=9999

# ============================================================
# Stage: vendor — composer deps + Wayfinder generation
# ============================================================
# Pinned to the native build platform: its output (PHP vendor/ + generated
# Wayfinder TS) is arch-independent, so we build it once instead of emulating it
# per target arch. The arch-specific runtime image is the final `app` stage.
FROM --platform=$BUILDPLATFORM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS vendor

USER root
ARG USER_ID
ARG GROUP_ID
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

# Required by moneyphp/money via laravel/cashier during Composer install.
RUN install-php-extensions bcmath

# git lets Composer fall back to source checkouts when GitHub dist downloads
# fail; single-request Composer downloads avoid intermittent codeload failures.
RUN apt-get update && apt-get install -y --no-install-recommends git \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
# Source needed to boot artisan for `wayfinder:generate`
COPY --chown=www-data:www-data artisan ./
COPY --chown=www-data:www-data bootstrap ./bootstrap
COPY --chown=www-data:www-data config ./config
COPY --chown=www-data:www-data routes ./routes
COPY --chown=www-data:www-data app ./app
COPY --chown=www-data:www-data database ./database
COPY --chown=www-data:www-data storage ./storage

# Full install (with dev) so Wayfinder/artisan can run
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        COMPOSER_MAX_PARALLEL_HTTP=1 composer install --no-interaction --no-plugins --no-scripts --prefer-install=auto && break; \
        if [ "$attempt" = 5 ]; then exit 1; fi; \
        sleep $((attempt * 5)); \
    done
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views
RUN php artisan wayfinder:generate --with-form
# Re-install without dev for the production vendor dir
RUN set -eux; \
    for attempt in 1 2 3 4 5; do \
        COMPOSER_MAX_PARALLEL_HTTP=1 composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-install=auto && break; \
        if [ "$attempt" = 5 ]; then exit 1; fi; \
        sleep $((attempt * 5)); \
    done

USER www-data

# ============================================================
# Stage: assets — frontend build (client + SSR bundles)
# ============================================================
# Pinned to the native build platform: the built JS/CSS (public/build) and the
# SSR bundle are arch-independent, and this avoids running Bun/Vite under QEMU
# emulation. node_modules native deps here (oxide/lightningcss/rolldown) are
# build-time only; the SSR runtime bundle loads pure-JS deps.
FROM --platform=$BUILDPLATFORM oven/bun:latest AS assets

WORKDIR /app
COPY package.json bun.lock vite.config.ts ./
RUN bun install --frozen-lockfile

# App source (minus .dockerignore'd paths)
COPY . .
# Vendor (some vite plugins resolve from it) + generated Wayfinder files
COPY --from=vendor /var/www/html/vendor ./vendor
COPY --from=vendor /var/www/html/resources/js/actions ./resources/js/actions
COPY --from=vendor /var/www/html/resources/js/routes ./resources/js/routes
COPY --from=vendor /var/www/html/resources/js/wayfinder ./resources/js/wayfinder

# Skip the wayfinder vite plugin (no PHP here; files already generated)
ENV SKIP_WAYFINDER_GENERATE=true
# Builds client assets AND the SSR bundle (bootstrap/ssr/ssr.mjs)
RUN bun run build:ssr

# ============================================================
# Stage: app — production image (single container, supervised)
# ============================================================
# serversideup's frankenphp image ships NO s6-overlay (s6 only exists in the
# fpm-nginx/fpm-apache variants), so in-container supervision uses supervisord.
# supervisord runs the Octane web server plus the queue worker, scheduler, and
# (when toggled) the Inertia SSR process — each toggleable via env vars so a
# cloud deployment can disable the in-container worker/scheduler and run them as
# separate services with their own entry point (override the image CMD).
# The serversideup ENTRYPOINT still runs the /etc/entrypoint.d/* init scripts
# before exec'ing this CMD.
FROM serversideup/php:${SERVERSIDEUP_PHP_VERSION} AS app

ARG USER_ID
ARG GROUP_ID
ARG POSTGRES_VERSION

WORKDIR /var/www/html
USER root

RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

# Redis extension for optional Redis cache/queue (pdo_pgsql ships in the image)
# gd + exif: required by intervention/image for runtime image compression
# bcmath: required by moneyphp/money via laravel/cashier
RUN install-php-extensions redis gd exif bcmath

# System packages + Bun (needed when SSR is toggled on: inertia:start-ssr --runtime=bun)
RUN apt-get update && apt-get install -y --no-install-recommends \
        postgresql-client-${POSTGRES_VERSION} \
        git \
        unzip \
        curl \
        jq \
        ffmpeg \
        supervisor \
    && rm -rf /var/lib/apt/lists/*
# Install the Bun binary for the target architecture (amd64 -> x64, arm64 -> aarch64).
# TARGETARCH is provided automatically by buildx.
ARG TARGETARCH
RUN set -eux; \
    case "${TARGETARCH}" in \
        amd64) bun_arch=x64 ;; \
        arm64) bun_arch=aarch64 ;; \
        *) echo "unsupported TARGETARCH: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL "https://github.com/oven-sh/bun/releases/latest/download/bun-linux-${bun_arch}.zip" -o /tmp/bun.zip; \
    unzip /tmp/bun.zip -d /tmp; \
    mv "/tmp/bun-linux-${bun_arch}/bun" /usr/local/bin/bun; \
    chmod 755 /usr/local/bin/bun; \
    rm -rf /tmp/bun.zip "/tmp/bun-linux-${bun_arch}"

# serversideup runtime configuration knobs
ARG AUTORUN_ENABLED=true
ARG AUTORUN_LARAVEL_CONFIG_CACHE=true
ARG AUTORUN_LARAVEL_EVENT_CACHE=true
ARG AUTORUN_LARAVEL_ROUTE_CACHE=true
ARG AUTORUN_LARAVEL_VIEW_CACHE=true
ARG AUTORUN_LARAVEL_STORAGE_LINK=true
ARG PHP_OPCACHE_ENABLE=1
ARG SSL_MODE=off
# Number of queue worker processes supervisord runs (numprocs in laravel.conf).
# Must be a non-empty integer or supervisord fails to start.
ARG QUEUE_WORKER_COUNT=1

ENV PHP_OPCACHE_ENABLE=${PHP_OPCACHE_ENABLE} \
    AUTORUN_ENABLED=${AUTORUN_ENABLED} \
    AUTORUN_LARAVEL_CONFIG_CACHE=${AUTORUN_LARAVEL_CONFIG_CACHE} \
    AUTORUN_LARAVEL_EVENT_CACHE=${AUTORUN_LARAVEL_EVENT_CACHE} \
    AUTORUN_LARAVEL_ROUTE_CACHE=${AUTORUN_LARAVEL_ROUTE_CACHE} \
    AUTORUN_LARAVEL_VIEW_CACHE=${AUTORUN_LARAVEL_VIEW_CACHE} \
    AUTORUN_LARAVEL_STORAGE_LINK=${AUTORUN_LARAVEL_STORAGE_LINK} \
    APP_BASE_DIR=/var/www/html \
    SSL_MODE=${SSL_MODE} \
    OCTANE_SERVER=frankenphp \
    QUEUE_WORKER_COUNT=${QUEUE_WORKER_COUNT}

# Supervisor supervises the web/worker/scheduler/ssr processes
COPY docker/supervisord.conf /etc/supervisor/laravel.conf

# Entrypoint init scripts (run by the serversideup ENTRYPOINT before the CMD)
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/

# Application source
COPY --chown=www-data:www-data . .
# Production vendor + built assets on top (so source copies don't clobber them)
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
# Emoji data (emojibase `en`) is generated into public/emoji by the vite build
# and is gitignored, so it exists only in the assets stage — copy it explicitly.
COPY --from=assets --chown=www-data:www-data /app/public/emoji ./public/emoji
COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr ./bootstrap/ssr
# node_modules needed for the SSR runtime when toggled on
COPY --from=assets --chown=www-data:www-data /app/node_modules ./node_modules

# Directory for the optional SQLite database (lives on a named volume at runtime)
RUN mkdir -p database/sqlite && chown -R www-data:www-data database/sqlite

RUN composer dump-autoload --no-plugins --no-scripts \
    && php artisan package:discover --ansi

USER www-data

# Default entry point: supervisord runs Octane + worker + scheduler (+ SSR when
# toggled). Override the CMD to run a single process, e.g. for a cloud worker:
#   php artisan queue:work --tries=3 --max-time=3600
CMD ["supervisord", "-c", "/etc/supervisor/laravel.conf"]
