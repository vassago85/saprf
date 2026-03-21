# SAPRF Production Dockerfile
# Multi-stage build for optimized image size

# =============================================================================
# Stage 1: Install PHP dependencies (needed by frontend for Flux CSS)
# =============================================================================
FROM composer:2 AS composer-builder

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev

# =============================================================================
# Stage 2: Build frontend assets
# =============================================================================
FROM node:22-alpine AS frontend-builder

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --production=false

COPY resources/ ./resources/
COPY vite.config.js ./
COPY --from=composer-builder /app/vendor ./vendor

RUN npm run build

# =============================================================================
# Stage 3: Production image
# =============================================================================
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip \
    icu-libs \
    oniguruma \
    curl

RUN apk add --no-cache --virtual .build-deps \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg
RUN docker-php-ext-install -j$(nproc) pdo_mysql gd zip intl bcmath opcache mbstring pcntl exif

RUN pecl install redis && docker-php-ext-enable redis

RUN apk del .build-deps

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/prod/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/prod/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/prod/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/prod/nginx.conf /etc/nginx/nginx.conf
COPY docker/prod/default.conf /etc/nginx/http.d/default.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

WORKDIR /var/www/html

COPY --from=composer-builder /app/vendor ./vendor
COPY --from=frontend-builder /app/public/build ./public/build
COPY . .

RUN rm -rf \
    docker \
    tests \
    .git \
    .gitignore \
    .gitattributes \
    phpunit.xml \
    vite.config.js \
    package.json \
    package-lock.json \
    node_modules \
    *.md

RUN mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    /var/log/supervisor \
    /var/run/supervisor \
    /var/lib/nginx/tmp/client_body \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 storage bootstrap/cache \
    && chmod -R 755 /var/lib/nginx/tmp

COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

RUN php artisan storage:link || true

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
