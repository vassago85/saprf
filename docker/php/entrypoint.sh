#!/bin/sh
set -e

cd /var/www

# Fast path for local hardcore testing / restarts. Set SKIP_BOOTSTRAP=1 in
# docker-compose so php-fpm starts immediately; migrate/cache stay host-driven.
if [ "${SKIP_BOOTSTRAP:-0}" = "1" ]; then
    exec "$@"
fi

if [ ! -f .env ]; then
    cp .env.docker .env
    echo "Copied .env.docker to .env"
fi

# Local compose bind-mounts ./vendor from the host. Skip composer when autoload already
# exists — regenerating the optimized classmap over a Windows bind mount can stall boot
# for many minutes. Production uses docker/prod/entrypoint.sh instead.
if [ ! -f vendor/autoload.php ]; then
    echo "Installing Composer dependencies..."
    COMPOSER_OPTIMIZE_AUTOLOADER=0 composer install --no-interaction --ignore-platform-req=ext-gd --no-scripts
fi

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
