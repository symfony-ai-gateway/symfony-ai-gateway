FROM dunglas/frankenphp:1-php8.3-alpine AS base

RUN apk add --no-cache sqlite-libs \
    && docker-php-ext-install pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-autoloader \
    && composer clear-cache

COPY . .
RUN composer dump-autoload --no-dev --optimize

ENV FRANKENPHP_CONFIG="worker ./public/index.php"
ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV APP_KERNEL_CLASS=AIGateway\\Standalone\\StandaloneKernel

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD curl -f http://localhost/v1/health || exit 1

CMD ["frankenphp", "run"]
