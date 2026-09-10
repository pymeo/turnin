# syntax=docker/dockerfile:1.7
#
# Turnin — multi-stage image.
#
#   base         shared PHP runtime + extensions (no app code)
#   development  base + Xdebug + bind-mounted source (compose.yaml)
#   builder      installs prod dependencies and compiles frontend assets
#   production   slim, non-root, OPcache preloaded, no dev dependencies
#   web          Caddy serving the compiled public/ from `production`
#
# See docs/DEPLOYMENT.md for the rationale behind each stage.

ARG PHP_VERSION=8.5

# --------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-trixie AS base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer \
    APP_RUNTIME_DIR=/var/www/app

# OPcache already ships enabled in the official PHP 8.5 image, so it is not in
# the list below — asking docker-php-ext-install for it fails the build.
#
# The apt-mark dance is the pattern used by the official PHP images: build the
# extensions against the -dev packages, then work out from ldd which runtime
# libraries the compiled .so files actually need and keep only those. Purging
# the -dev packages without it silently removes libicu/libzip and leaves intl
# and zip unloadable at runtime.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        $PHPIZE_DEPS; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    pecl clear-cache; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark > /dev/null; \
    apt-mark manual git unzip > /dev/null; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR ${APP_RUNTIME_DIR}

# --------------------------------------------------------------------------
FROM base AS development

ARG HOST_UID=1000
ARG HOST_GID=1000

ENV APP_ENV=dev \
    APP_DEBUG=1

# Xdebug needs a compiler, which the base image deliberately does not keep.
RUN apt-get update \
    && apt-get install -y --no-install-recommends $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

# Run as the host user so that files the container writes into the bind mount
# (generated migrations, compiled assets, caches) stay editable outside Docker.
RUN groupadd --gid "${HOST_GID}" --non-unique turnin \
    && useradd --uid "${HOST_UID}" --gid "${HOST_GID}" --non-unique \
        --home-dir /var/www/app --shell /bin/bash turnin \
    && mkdir -p /var/www/app /tmp/composer \
    && chown -R "${HOST_UID}:${HOST_GID}" /var/www/app /tmp/composer

COPY docker/php/conf.d/xdebug.ini /usr/local/etc/php/conf.d/zz-xdebug.ini
COPY docker/php/php-fpm.d/development.conf /usr/local/etc/php-fpm.d/zz-development.conf
COPY docker/php/entrypoint.sh /usr/local/bin/turnin-entrypoint
RUN chmod +x /usr/local/bin/turnin-entrypoint

USER turnin

HEALTHCHECK --interval=5s --timeout=3s --start-period=90s --retries=40 \
    CMD php -r 'exit(is_file("vendor/autoload.php") ? 0 : 1);'

ENTRYPOINT ["turnin-entrypoint"]
CMD ["php-fpm"]

# --------------------------------------------------------------------------
FROM base AS builder

ENV APP_ENV=prod \
    APP_DEBUG=0

# Dependencies first so the layer caches across source-only changes.
COPY composer.json composer.lock symfony.lock ./
RUN --mount=type=cache,target=/tmp/composer/cache \
    composer install --no-dev --no-interaction --no-scripts --no-progress --prefer-dist

COPY . .

RUN composer dump-autoload --classmap-authoritative --no-dev \
    && composer run-script --no-dev post-install-cmd \
    && php bin/console tailwind:build --minify \
    && php bin/console asset-map:compile \
    && rm -rf var/cache/dev var/log/* .git

# --------------------------------------------------------------------------
FROM base AS production

ENV APP_ENV=prod \
    APP_DEBUG=0

COPY docker/php/conf.d/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/production-entrypoint.sh /usr/local/bin/turnin-entrypoint

COPY --from=builder --chown=www-data:www-data ${APP_RUNTIME_DIR} ${APP_RUNTIME_DIR}

RUN chmod +x /usr/local/bin/turnin-entrypoint \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

USER www-data

HEALTHCHECK --interval=10s --timeout=3s --start-period=20s --retries=3 \
    CMD php bin/console turnin:health --quiet || exit 1

ENTRYPOINT ["turnin-entrypoint"]
CMD ["php-fpm"]

# --------------------------------------------------------------------------
FROM caddy:2-alpine AS web

COPY docker/caddy/Caddyfile.prod /etc/caddy/Caddyfile
COPY --from=production /var/www/app/public /var/www/app/public
