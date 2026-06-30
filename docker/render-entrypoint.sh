#!/bin/sh
set -e

if [ -n "$APP_KEY" ] && ! echo "$APP_KEY" | grep -q '^base64:'; then
    export APP_KEY="base64:${APP_KEY}"
fi

php artisan package:discover --ansi
php artisan migrate --force
php artisan config:cache

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
