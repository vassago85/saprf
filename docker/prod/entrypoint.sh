#!/bin/sh
set -e

cd /var/www/html

# Host-side `php artisan config:cache` can get COPY'd into the image and
# pin a Laragon/dev DB_HOST (mysql). Drop it so this boot uses compose/.env.
rm -f bootstrap/cache/config.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php

echo "Running migrations..."
php artisan migrate --force

echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "Creating storage link..."
php artisan storage:link 2>/dev/null || true

echo "Setting permissions..."
mkdir -p storage/fonts storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "SAPRF ready."
exec "$@"
