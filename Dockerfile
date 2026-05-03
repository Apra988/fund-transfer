# Optional local PHP runtime matching composer.json (PHP 8.2+).
# Most developers run Symfony with PHP installed natively; docker-compose only starts MySQL + Redis by default.
FROM php:8.3-cli-alpine
RUN apk add --no-cache git unzip $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
