# syntax=docker/dockerfile:1
# --- Tahap 1: dependensi PHP (tanpa paket dev) ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# --- Tahap 2: PHP-FPM 8.4 ---
FROM php:8.4-fpm-alpine
RUN apk add --no-cache gmp libpng libjpeg-turbo libwebp tzdata \
 && apk add --no-cache --virtual .build $PHPIZE_DEPS gmp-dev libpng-dev libjpeg-turbo-dev libwebp-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql bcmath gmp opcache pcntl \
 && apk del .build
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-orca.ini
WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
 && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
ENV TZ=Asia/Jakarta
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
