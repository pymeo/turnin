#!/bin/sh
# Development entrypoint: make the bind-mounted checkout usable before handing
# over to php-fpm. Kept idempotent so `docker compose up` can run it repeatedly.
set -e

if [ ! -f vendor/autoload.php ]; then
    echo '[turnin] installing composer dependencies…'
    composer install --no-interaction --no-progress
fi

mkdir -p var/cache var/log

exec "$@"
