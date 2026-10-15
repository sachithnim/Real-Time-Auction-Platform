#!/bin/sh
# Prepares the Laravel app on container start, then runs the given command.
set -e

# Worker containers (queue, scheduler) start after the API container has
# already installed dependencies and migrated, so they skip straight to work.
if [ "${SKIP_BOOTSTRAP:-0}" = "1" ]; then
    exec "$@"
fi

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --force
fi

php artisan config:clear >/dev/null
php artisan migrate --force
# Seeders are idempotent, so re-running them on every start is safe.
php artisan db:seed --force

exec "$@"
