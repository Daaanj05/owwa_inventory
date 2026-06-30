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

FROM php:8.4-cli-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    pkg-config \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libicu-dev \
    libonig-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        mbstring \
        xml \
        curl \
        zip \
        gd \
        intl \
        bcmath \
        opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p bootstrap/cache \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    && chmod -R 775 bootstrap/cache storage \
    && composer dump-autoload --optimize --classmap-authoritative --no-scripts \
    && chmod +x docker/render-entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=10000

EXPOSE 10000

CMD ["docker/render-entrypoint.sh"]
