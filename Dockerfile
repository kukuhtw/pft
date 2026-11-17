# syntax=docker/dockerfile:1.7
# AI Personal Finance Tracker — production image for Dokploy.
# One image, three roles (CONTAINER_ROLE): web (nginx + php-fpm), worker (queue), scheduler.

ARG PHP_VERSION=8.5

########## base: PHP runtime + required extensions ##########
FROM php:${PHP_VERSION}-fpm-alpine AS base

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

# bcmath is mandatory (all money arithmetic). OPcache is built into PHP 8.5.
RUN install-php-extensions bcmath intl pdo_mysql zip pcntl \
    && apk add --no-cache nginx supervisor su-exec tzdata \
    && rm -rf /var/cache/apk/* /tmp/*

ENV TZ=UTC
WORKDIR /var/www/html

########## vendor: production Composer dependencies ##########
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm -rf tests docs .git node_modules storage/logs/* bootstrap/cache/*.php

########## assets: Vite/Tailwind build (public/build is git-ignored, so it must be built here) ##########
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY vite.config.js ./
# Tailwind scans these for class names.
COPY resources ./resources
COPY app ./app
RUN npm run build

########## runtime ##########
FROM base AS runtime

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY --from=vendor --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Never bake secrets into the image: .env is excluded by .dockerignore and supplied at runtime.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD if [ "${CONTAINER_ROLE:-web}" = "web" ]; then wget -qO- http://127.0.0.1:8080/up >/dev/null || exit 1; fi

ENTRYPOINT ["entrypoint"]
CMD ["web"]
