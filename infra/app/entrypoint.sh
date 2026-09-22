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

    # .env muss vom Container-User lsp (uid/gid 1000) lesbar sein. queue/scheduler
    # wechseln gleich per gosu zu lsp und würden sonst config:cache mit LEEREM
    # APP_KEY schreiben — bootstrap/cache ist per Bind-Mount von allen Containern
    # geteilt, der leere Key clobbert dann den korrekten. Wir setzen NUR das
    # Gruppen-Leserecht (Gruppe lsp), Secrets bleiben also nicht world-readable.
    if [ -f .env ]; then
        chgrp lsp .env 2>/dev/null || true
        chmod g+r,o-rwx .env 2>/dev/null || true
    fi

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
    # Nur cachen, wenn der APP_KEY wirklich verfügbar ist (aus der Umgebung oder
    # einer lesbaren .env). Sonst würde ein config.php mit leerem Key in den
    # geteilten bootstrap/cache geschrieben (Race zwischen app/queue/scheduler)
    # und die Klarnamen-Krypto bräche mit „No application encryption key".
    if [ -n "${APP_KEY:-}" ] || { [ -r .env ] && grep -q "^APP_KEY=base64:" .env; }; then
        php artisan config:cache  || true
        php artisan route:cache   || true
        php artisan view:cache    || true
    else
        echo "[entrypoint] WARNUNG: APP_KEY nicht lesbar (.env-Rechte?) — überspringe config:cache und leere den Cache, um keinen leeren Key zu cachen." >&2
        php artisan config:clear || true
    fi
fi

exec "$@"
