FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.3-cli-alpine
WORKDIR /app
COPY --from=deps /app/vendor ./vendor
COPY index.php ./
COPY src ./src

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
