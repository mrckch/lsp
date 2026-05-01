#!/usr/bin/env bash
set -euo pipefail

cd /var/www/html

# Wenn als root gestartet: storage/bootstrap-cache-Verzeichnisse anlegen +
# Permissions absichern (Bind-Mount vom Host hat oft falsche Owner-IDs).
# PHP-FPM-Master MUSS als root starten (sonst kein Zugriff auf
# /proc/self/fd/2 für error_log) — Workers laufen via php-fpm.conf
# user/group-Direktive automatisch als lsp. Für andere Befehle
# (queue:work, schedule:run, backup-Worker, artisan) gosu zu lsp.
if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/logs storage/framework/cache storage/framework/sessions \
             storage/framework/views storage/framework/testing bootstrap/cache
    chown -R lsp:lsp storage bootstrap/cache 2>/dev/null || true
    chmod -R 775 storage bootstrap/cache 2>/dev/null || true

    case "${1:-}" in
        php-fpm|*php-fpm*) : ;;  # bleibt root, Worker switch via Pool-Config
        *) exec gosu lsp:lsp "$0" "$@" ;;
    esac
fi

# Install composer deps if vendor missing (first run / dev)
if [ ! -d "vendor" ] && [ -f "composer.json" ]; then
    echo "[entrypoint] composer install (vendor missing)..."
    composer install --no-interaction --prefer-dist --no-scripts
    composer dump-autoload --optimize || true
fi

# Generate APP_KEY if missing
if [ -f .env ] && ! grep -q "^APP_KEY=base64:" .env; then
    echo "[entrypoint] generating APP_KEY..."
    php artisan key:generate --force || true
fi

# Filament-Assets ins public/-Verzeichnis publizieren wenn fehlend
# (idempotent — überschreibt nur, wenn etwas geändert wurde).
if [ -d vendor/filament ] && [ ! -f public/css/filament/filament/app.css ]; then
    echo "[entrypoint] publishing filament assets..."
    php artisan filament:assets || true
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
