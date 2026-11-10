#!/bin/sh
set -eu
[ -f .env ] || cp .env.example .env
composer install --no-interaction --prefer-dist
if ! grep -Eq '^APP_KEY=.+$' .env; then
    php artisan key:generate --force
fi
php artisan migrate --force
exec "$@"
