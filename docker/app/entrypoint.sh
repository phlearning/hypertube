#!/bin/sh
set -e

cd /var/www/html

if [ -z "$SKIP_SETUP" ]; then
    if [ ! -d vendor ]; then
        composer install --no-interaction --prefer-dist
    fi

    if [ ! -f .env ]; then
        cp .env.example .env
    fi

    if ! grep -q '^APP_KEY=base64' .env 2>/dev/null; then
        php artisan key:generate --force
    fi

    php artisan migrate --force

    chown -R 1000:1000 /var/www/html
fi

exec "$@"
