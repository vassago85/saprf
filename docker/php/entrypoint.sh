#!/bin/sh
set -e

cd /var/www

if [ ! -f .env ]; then
    cp .env.docker .env
    echo "Copied .env.docker to .env"
fi

echo "Installing Composer dependencies..."
composer install --no-interaction --optimize-autoloader --no-dev --ignore-platform-req=ext-gd

if [ ! -d node_modules ] || [ ! -f node_modules/.package-lock.json ]; then
    echo "Installing NPM dependencies..."
    if [ -f package-lock.json ]; then
        npm ci
    else
        npm install
    fi
fi

if [ ! -f public/build/assets/*.css ]; then
    echo "Building frontend assets..."
    npm run build
fi

if ! grep -q "APP_KEY=base64:" .env; then
    echo "Generating application key..."
    php artisan key:generate --force
fi

echo "Running migrations..."
php artisan migrate --force

echo "Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec "$@"
