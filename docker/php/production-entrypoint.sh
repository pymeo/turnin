#!/bin/sh
# Production entrypoint. Migrations are NOT run here: schema changes are a
# deliberate deploy step (see docs/DEPLOYMENT.md) so that a rolling restart can
# never race two containers into the same migration.
set -e

php bin/console cache:warmup --no-interaction

exec "$@"
