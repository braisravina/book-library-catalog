# syntax=docker/dockerfile:1
# Digital Library Archive — PHP-FPM image.
#   dev  : tooling (composer), code bind-mounted from the host
#   prod : code and optimised autoloader baked in, runs as www-data

ARG PHP_VERSION=8.4

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-fpm-alpine AS base
RUN set -eux; \
    apk add --no-cache libpng libjpeg-turbo libwebp freetype; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev; \
    docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql gd; \
    apk del .build-deps; \
    php -m | grep -qi pdo_mysql; php -m | grep -qi gd
COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-app.conf
WORKDIR /var/www/html

FROM base AS dev
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/entrypoint-dev.sh /usr/local/bin/entrypoint-dev.sh
ENTRYPOINT ["entrypoint-dev.sh"]
CMD ["php-fpm"]

FROM composer AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts --prefer-dist \
    --optimize-autoloader --classmap-authoritative --ignore-platform-reqs
COPY src ./src
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM base AS prod
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY --chown=root:root bin ./bin
COPY --chown=root:root config ./config
COPY --chown=root:root database ./database
COPY --chown=root:root lang ./lang
COPY --chown=root:root public ./public
COPY --chown=root:root src ./src
COPY --chown=root:root templates ./templates
COPY --from=vendor --chown=root:root /app/vendor ./vendor
# Code is owned by root and read-only to the FPM user; only storage is writable.
RUN mkdir -p storage/covers storage/logs storage/cache storage/backups storage/tmp \
    && chown -R www-data:www-data storage \
    && chmod -R 0750 storage
USER www-data
