# TenderHub API — CodeIgniter 4 on PHP 8.3-FPM
#
# The previous image ran no `composer install`, and vendor/ is gitignored, so a
# build from a clean checkout produced a container with no dependencies that
# fataled on its first autoload. The CI "docker build" step still passed,
# because building an image does not execute PHP. This multi-stage build
# installs production dependencies in a dedicated stage.

# --- 1. Composer stage: resolve and install PHP dependencies ---------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: the app's only composer script is phpunit (dev); nothing needs
# to run at install time in a production image.
RUN composer install \
      --no-dev \
      --optimize-autoloader \
      --no-interaction \
      --no-scripts \
      --prefer-dist

# --- 2. Runtime stage -------------------------------------------------------
FROM php:8.3-fpm-alpine

RUN apk update && apk add --no-cache \
      icu-dev libzip-dev libpng-dev oniguruma-dev sqlite-dev curl \
    && docker-php-ext-install \
      intl pdo pdo_mysql pdo_sqlite mysqli mbstring gd zip opcache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
      echo "memory_limit = 256M"; \
      echo "upload_max_filesize = 32M"; \
      echo "post_max_size = 32M"; \
      echo "expose_php = Off"; \
      echo "opcache.enable = 1"; \
      echo "opcache.memory_consumption = 128"; \
      echo "opcache.validate_timestamps = 0"; \
    } >> "$PHP_INI_DIR/conf.d/custom.ini"

WORKDIR /var/www/html

# Dependencies first (cache-friendly), then application source. `.dockerignore`
# excludes the local vendor/, writable payload, tests and .env so none of them
# overwrite the composer-built vendor or leak into an image layer.
COPY --from=vendor /app/vendor ./vendor
COPY . .

# Create a clean writable tree owned by the runtime user. Nothing from the host
# writable/ (dev sqlite, logs, uploads) is copied in — see .dockerignore.
RUN mkdir -p writable/cache writable/logs writable/session writable/uploads writable/documents \
    && chown -R www-data:www-data writable \
    && chmod -R 775 writable

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
