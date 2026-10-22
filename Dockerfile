# Production image for the RTM API. Same image runs three different
# processes via docker-compose.prod.yml (app/php-fpm, queue worker, Reverb)
# — only the command differs, so it's built once and reused three times.

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM php:8.5-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        libpng-dev \
        oniguruma-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        bcmath \
        gd \
        zip \
        pcntl \
        mbstring \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Production-tuned opcache (opcache.validate_timestamps=0 means the code
# baked into this image is treated as immutable — a new image is required
# to pick up code changes, which matches how it's deployed here).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.jit=tracing'; \
        echo 'opcache.jit_buffer_size=64M'; \
    } > /usr/local/etc/php/conf.d/opcache-prod.ini

# PHP's recommended production settings (errors logged, never shown in a
# response), then this app's on top: uploads as large as the API accepts,
# no version header, and a PHP-FPM pool sized for the VM. Both files are
# named to load after the image's own, so their settings win.
RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-rtm.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-rtm.conf

WORKDIR /var/www/html
COPY --from=vendor /app ./

RUN addgroup -g 1000 www && adduser -u 1000 -G www -h /var/www -D www \
    && chown -R www:www /var/www/html \
    && chmod -R 775 storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER www
EXPOSE 9000
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
