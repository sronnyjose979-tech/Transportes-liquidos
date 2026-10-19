# syntax=docker/dockerfile:1
FROM php:8.4-fpm-bookworm AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpq-dev libonig-dev libicu-dev libzip-dev libcurl4-openssl-dev \
    libpng-dev libjpeg62-turbo-dev util-linux ca-certificates \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install -j2 pdo_pgsql mbstring intl zip curl gd bcmath pcntl opcache \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

FROM php-base AS dependencies
COPY --from=composer:2.9 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY routes ./routes
COPY database ./database
COPY resources ./resources
COPY artisan ./artisan
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && composer check-platform-reqs --no-dev

FROM node:24-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json ./
# Tailwind scans Laravel pagination templates explicitly; include the locked sources.
COPY --from=dependencies /var/www/html/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views
ENV TRANSPORTE_BUILD_OFFLINE=1
RUN npm run build

FROM php-base AS app
COPY --from=dependencies --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=frontend --chown=www-data:www-data /build/public ./public
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/app-entrypoint
RUN mkdir -p storage/app/private/workshop storage/app/fleet-documents-private \
    storage/framework/cache/data storage/framework/cache/locks \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 750 storage bootstrap/cache
USER www-data
ENTRYPOINT ["app-entrypoint"]
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web
COPY --from=frontend /build/public /var/www/html/public
COPY docker/nginx/http.conf.template docker/nginx/tls.conf.template /etc/transport/
COPY --chmod=755 docker/nginx/40-transport-config.sh /docker-entrypoint.d/40-transport-config.sh
