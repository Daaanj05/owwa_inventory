# syntax=docker/dockerfile:1

FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
COPY public ./public

RUN mkdir -p storage/framework/views \
    vendor/laravel/framework/src/Illuminate/Pagination/resources/views \
    && npm run build

FROM dunglas/frankenphp:1-php8.4-bookworm AS app

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    mbstring \
    xml \
    curl \
    zip \
    gd \
    intl \
    bcmath \
    opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Keep /var/www/html so existing Render disk mounts for OWWA templates remain valid.
WORKDIR /var/www/html

ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    SERVER_ROOT=public \
    XDG_CONFIG_HOME=/config \
    XDG_DATA_HOME=/data

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/Caddyfile /etc/caddy/Caddyfile

RUN mkdir -p bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    storage/app/templates \
    /config/caddy \
    /data/caddy \
    && if [ -d resources/owwa-templates ]; then cp -r resources/owwa-templates/. storage/app/templates/; fi \
    && chmod -R 775 bootstrap/cache storage \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && chmod +x docker/render-entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache /config /data \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ENV PORT=10000

EXPOSE 10000

# Override FrankenPHP's default entrypoint so the Render boot script owns lifecycle.
ENTRYPOINT ["/var/www/html/docker/render-entrypoint.sh"]
