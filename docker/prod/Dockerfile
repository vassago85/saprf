# SAPRF Production Dockerfile
# Multi-stage build for optimized image size

# =============================================================================
# Stage 1: Install PHP dependencies (needed by frontend for Flux CSS)
# =============================================================================
FROM composer:2 AS composer-builder

WORKDIR /app

# Force Composer to install every package via `git clone` against the
# actual GitHub repos instead of downloading zips from codeload.github.com.
#
# GitHub's `codeload.github.com/*/legacy.zip/<sha>` endpoint is served
# by a separate edge from `github.com/*.git`, and the southafricanorth
# edge for codeload has been reproducibly returning HTTP 400 (16-byte
# "invalid request" body) for a stable subset of SHA-pinned Composer
# archives — symfony/mime, symfony/finder, symfony/event-dispatcher-
# contracts, and guzzlehttp/promises being the current offenders.
# curl reproduces the 400 outside the build too, so it's not a
# Docker / HTTP2 / User-Agent problem — codeload's SA edge is the issue.
#
# `--prefer-source` sidesteps codeload entirely by cloning from
# `github.com/vendor/pkg.git` (standard git-smart-HTTP, different
# infrastructure). Adds ~30-60s to the build vs zip download but is
# reliable. `composer:2` image ships with git, so no extra apk step
# is needed. Once GitHub's SA edge is healthy again this can flip
# back to `--prefer-dist`.
COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-source \
    --ignore-platform-req=ext-gd

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
FROM php:8.4-fpm-alpine AS production

RUN apk add --no-cache \
    nginx \
    supervisor \
    libpng \
    libjpeg-turbo \
    libwebp \
    freetype \
    libzip \
    icu-libs \
    oniguruma \
    curl \
    chromium \
    nss \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    nodejs \
    npm \
    mariadb-client

# Alpine packages expose `chromium`; some images also ship chromium-browser.
ENV CHROME_PATH=/usr/bin/chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true

# Browsershot drives Puppeteer, which drives the system Chromium above.
# Install puppeteer globally so laravel-pdf can `require('puppeteer')` without
# a per-project `npm install`. PUPPETEER_SKIP_CHROMIUM_DOWNLOAD stops puppeteer
# bundling its own copy — we already have one at /usr/bin/chromium.
RUN npm install -g --omit=dev puppeteer@^23 \
    && npm cache clean --force

# spatie/laravel-pdf picks these up via config/laravel-pdf.php env() calls at
# config:cache time (entrypoint runs the cache after boot, so any override
# supplied via docker-compose env wins).
ENV LARAVEL_PDF_CHROME_PATH=/usr/bin/chromium
ENV LARAVEL_PDF_NO_SANDBOX=true
ENV LARAVEL_PDF_NODE_MODULES_PATH=/usr/lib/node_modules

RUN apk add --no-cache --virtual .build-deps \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# GD compiled with WebP so Intervention's queued job can emit WebP as
# well as JPEG variants for the media library.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp
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
    storage/fonts \
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
