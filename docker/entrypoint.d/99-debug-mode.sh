#!/bin/bash
# Non-fatal by design: a failure here (e.g. optimize:clear hitting an unmigrated
# database cache store) must not abort the serversideup entrypoint and kill the
# container, so each step falls back to a warning.
if [ "${APP_DEBUG}" = "true" ]; then
    echo "[debug] APP_DEBUG=true — installing dev dependencies"
    composer install --dev --no-interaction --no-scripts || echo "[debug] composer install --dev failed (continuing)"
    echo "[debug] clearing optimized caches"
    php artisan optimize:clear || echo "[debug] optimize:clear failed (continuing)"
fi
