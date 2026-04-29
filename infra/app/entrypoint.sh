#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Install composer deps if vendor missing (first run / dev)
if [ ! -d "vendor" ] && [ -f "composer.json" ]; then
    echo "[entrypoint] composer install (vendor missing)..."
    composer install --no-interaction --prefer-dist
fi

# Generate APP_KEY if missing
if [ -f .env ] && ! grep -q "^APP_KEY=base64:" .env; then
    echo "[entrypoint] generating APP_KEY..."
    php artisan key:generate --force || true
fi

# Run migrations only when explicitly requested
if [ "${LSP_AUTO_MIGRATE:-false}" = "true" ]; then
    echo "[entrypoint] running migrations..."
    php artisan migrate --force || true
fi

# Cache for production
if [ "${APP_ENV:-local}" = "production" ]; then
    php artisan config:cache  || true
    php artisan route:cache   || true
    php artisan view:cache    || true
fi

exec "$@"
