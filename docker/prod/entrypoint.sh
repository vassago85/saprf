#!/bin/sh
set -e

cd /var/www/html

echo "Running migrations..."
php artisan migrate --force 2>/dev/null || true

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
