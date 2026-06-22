#!/bin/bash
set -e

# Ensure Laravel's storage skeleton exists (named volumes start from the image
# contents, but be defensive for bind mounts).
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/app/public \
    storage/logs

# When using SQLite, make sure the database file exists before migrations run.
if [ "${DB_CONNECTION}" = "sqlite" ]; then
    DB_FILE="${DB_DATABASE:-/var/www/html/database/sqlite/database.sqlite}"
    mkdir -p "$(dirname "${DB_FILE}")"
    if [ ! -f "${DB_FILE}" ]; then
        touch "${DB_FILE}"
        echo "[init] created SQLite database at ${DB_FILE}"
    fi
fi
