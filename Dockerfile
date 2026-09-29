# syntax=docker/dockerfile:1
#
# JobFlow AI — API image for Render.
#
# WHY php:8.5-cli AND NOT php-fpm + nginx
# -----------------------------------------
# Render's free tier is the target and the brief specifies
# `php artisan serve`. That is PHP's built-in server, which is
# SINGLE-THREADED by default: one in-flight request blocks every other
# request. This app makes slow requests by design — a vision OCR call can
# take 30–60s — so a single-threaded server would serialise the entire
# product behind one upload.
#
# The fix is one line: PHP_CLI_SERVER_WORKERS (PHP >= 7.4) forks worker
# processes for the built-in server. That gets genuine concurrency without
# adding nginx and a hand-written vhost, which is a whole extra layer to
# debug on a first deploy. See DEPLOYMENT.md §"Scaling past the built-in
# server" for when to move to php-fpm + nginx.
#
# Note: uploaded files land in storage/app/private, which is EPHEMERAL on
# Render and wiped on every redeploy. That is accepted for the first
# deploy and documented as the reason to move to Cloudflare R2.

FROM php:8.5-cli

# Fail the build rather than boot with a missing extension.
ENV APP_ENV=production \
    APP_DEBUG=false

# --- System packages ------------------------------------------------------
# libpq-dev  → pdo_pgsql (PostgreSQL driver)
# libzip-dev → composer unzip + zip support
# unzip       → composer prefers binary zip over PHP's slower ZipArchive
# file        → `file` command; Laravel's File::types() MIME validation
#              shells out to it to sniff real content types, which is what
#              stops a renamed .php from being accepted as a "PDF".
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        unzip \
        file \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# --- PHP extensions -------------------------------------------------------
# pgsql and pdo_pgsql are NOT in the base image (php -m shows only
# pdo_sqlite), so both are compiled here — installed together in a single
# docker-php-ext-install call, the official pattern; -j parallelises the C
# compiles across cores.
#
# opcache is deliberately NOT reinstalled: php:8.5-cli already ships Zend
# OPcache statically compiled in (php -m lists "Zend OPcache" and the
# extension directory contains only sodium.so). Re-running
# `docker-php-ext-install opcache` against this image is a no-op build:
# configure completes, make produces no object files, modules/ stays empty,
# and the install step dies with `cp: cannot stat 'modules/*'` (exit 2 —
# verified on a pristine php:8.5-cli container).
RUN docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql \
    pgsql

# opcache: the container is rebuilt on every deploy, so a warm in-memory
# opcode cache never carries over — but it still pays off within a single
# long-lived instance and costs nothing.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# --- Composer -------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# The build runs as root, so composer needs a writable COMPOSER_HOME or it
# warns on every install. Set it before the first composer invocation.
ENV COMPOSER_HOME=/tmp/composer

# Dependency layers first: composer install only re-runs when the lock file
# changes, so editing application code does not re-download the whole vendor
# tree on every deploy. composer.lock is copied so the exact locked versions
# (laravel/framework ^13, sanctum, smalot/pdfparser) are installed.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

# `.dockerignore` strips storage/ contents (logs, caches, uploads) so local
# state can never be baked into the image — which also means the runtime
# directories do not exist in the image yet. Recreate them BEFORE the first
# artisan call: package:discover boots the app, and anything it logs needs
# storage/logs, while views/compiled files need storage/framework/*.
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && php artisan package:discover --ansi

# --- Permissions ----------------------------------------------------------
# storage/ and bootstrap/cache/ must be writable by the runtime user, and
# public/ must be readable. `storage:link` is intentionally NOT run here:
# FILESYSTEM_DISK=local is private, so there is nothing to link, and a
# missing symlink is better than a link pointing at an ephemeral directory.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Runtime prep runs here rather than as a Render `buildCommand`: with
# `runtime: docker` the Dockerfile is authoritative and blueprint
# build/start commands are ignored. Keeping the sequence in one visible
# place is better than a setting that silently does nothing.
#
# `migrate --force` on boot is the correct pattern for a single-instance
# service, and `set -e` is deliberate: if a cache step or a migration fails the
# container exits and Render marks the deploy as failed, rather than serving
# an app whose schema is behind its code.
# Migrations run at container START, never during the image build.
#
# `schedule:work` runs the two daily sweeps registered in
# routes/console.php (reminders:send, notifications:generate). A single
# Render instance has no cron process, so without this line those
# features would silently never run in production. It is backgrounded
# so a scheduler failure can never take the API down with it.
#
# NOTE: this script MUST be generated while still root -- /usr/local/bin
# is root-owned, so writing it after `USER www-data` fails the build
# with "permission denied". Hence: entrypoint first, USER below it.
#
# The port is deliberately NOT quoted inside the script: escaped quotes
# would survive into argv as literal quote characters (--port="10000")
# and `php artisan serve` would bind the wrong port, failing Render's
# health check on $PORT.
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    'php artisan config:cache' \
    'php artisan route:cache' \
    'php artisan migrate --force --no-interaction' \
    'php artisan schedule:work &' \
    'exec php artisan serve --host=0.0.0.0 --port=${PORT:-10000}' \
    > /usr/local/bin/entrypoint \
    && chmod +x /usr/local/bin/entrypoint

# Non-root runtime. Everything that must be created under system
# directories (the entrypoint above) has been created already.
USER www-data

EXPOSE 10000

# PHP_CLI_SERVER_WORKERS forks the built-in server into real workers. Without
# it `php artisan serve` handles one request at a time, so a single 30s vision
# OCR call would stall every other user of the app.
ENV PHP_CLI_SERVER_WORKERS=4

ENTRYPOINT ["/usr/local/bin/entrypoint"]
