FROM php:8.4-fpm-bookworm@sha256:075b11566518bfa979bb9f2fe2e5359148326d659b15a2f414c2c305a0479a4e
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libonig-dev libargon2-dev unzip && docker-php-ext-install pdo_pgsql mbstring opcache pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader
COPY . .
COPY infra/php.ini /usr/local/etc/php/conf.d/billing.ini
RUN composer dump-autoload --no-dev --optimize && mkdir -p /app/var && chown -R www-data:www-data /app/var
USER www-data
CMD ["php-fpm", "-F"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s CMD php bin/console app:health || exit 1
