#!/bin/sh
set -e

cd /var/www/html

if [ -n "$APP_KEY" ] && ! echo "$APP_KEY" | grep -q '^base64:'; then
    export APP_KEY="base64:${APP_KEY}"
fi

php artisan package:discover --ansi
php artisan migrate --force

# Seed only on explicit boot flag when the users table is empty (first provision).
# Never reseed an existing presentation/demo database on every wake/restart.
if [ "$SEED_ON_BOOT" = "true" ]; then
    user_count="$(php artisan tinker --execute='echo Illuminate\Support\Facades\Schema::hasTable("users") ? (string) App\Models\User::query()->count() : "0";' 2>/dev/null || echo "0")"
    if [ "$user_count" = "0" ]; then
        echo "INFO: Empty database detected; running DatabaseSeeder..."
        php artisan db:seed --force
        if [ "$SEED_DEMO" = "true" ]; then
            php artisan db:seed --class=DemoDataSeeder --force || echo "WARN: DemoDataSeeder failed; continuing startup"
        fi
    else
        echo "INFO: Skipping seeders (users already present)."
    fi
elif [ "$SEED_DEMO" = "true" ]; then
    echo "WARN: SEED_DEMO=true ignored without SEED_ON_BOOT=true (refusing to reseed on every boot)."
fi

mkdir -p bootstrap/cache/filament storage/framework/views storage/app/templates /config/caddy /data/caddy

if [ ! -f storage/app/templates/.synced ] && [ -d resources/owwa-templates ]; then
    cp -r resources/owwa-templates/. storage/app/templates/ 2>/dev/null || true
    touch storage/app/templates/.synced
fi

php artisan app:audit-owwa-templates --json > /tmp/owwa-template-audit.json 2>&1 || true
if [ -f /tmp/owwa-template-audit.json ]; then
    missing=$(php -r 'echo count(json_decode(file_get_contents("/tmp/owwa-template-audit.json"), true)["missing_configured_templates"] ?? []);' 2>/dev/null || echo "0")
    if [ "$missing" != "0" ]; then
        echo "WARN: OWWA template audit reports ${missing} missing configured template(s). Run: php artisan owwa:sync-templates"
    fi
fi

php artisan config:cache

php artisan tinker --execute="Illuminate\Support\Facades\DB::connection()->getPdo(); echo 'DB OK'.PHP_EOL;" || {
    echo "ERROR: Database connection failed. Check Render DB_* environment variables."
    exit 1
}

export PORT="${PORT:-10000}"
export SERVER_NAME=":${PORT}"

echo "INFO: Starting FrankenPHP on port ${PORT} (concurrent HTTP)..."
exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
