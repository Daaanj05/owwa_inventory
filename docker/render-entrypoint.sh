#!/bin/sh
set -e

if [ -n "$APP_KEY" ] && ! echo "$APP_KEY" | grep -q '^base64:'; then
    export APP_KEY="base64:${APP_KEY}"
fi

php artisan package:discover --ansi
php artisan migrate --force
php artisan db:seed --force

if [ "$SEED_DEMO" = "true" ]; then
    php artisan db:seed --class=DemoDataSeeder --force || echo "WARN: DemoDataSeeder failed; continuing startup"
fi

mkdir -p bootstrap/cache/filament storage/framework/views

php artisan config:cache

php artisan tinker --execute="Illuminate\Support\Facades\DB::connection()->getPdo(); echo 'DB OK'.PHP_EOL;" || {
    echo "ERROR: Database connection failed. Check Render DB_* environment variables."
    exit 1
}

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
