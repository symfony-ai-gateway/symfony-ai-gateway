FROM php:8.2-alpine AS build

RUN apk add --no-cache curl zip unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress --no-autoloader

COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM php:8.2-alpine AS production

RUN apk add --no-cache curl sqlite-libs \
    && docker-php-ext-install pdo_sqlite

RUN addgroup -g 1000 gateway && adduser -u 1000 -G gateway -s /bin/sh -D gateway

WORKDIR /app

COPY --from=build --chown=gateway:gateway /app /app

USER gateway

ENV PORT=8080
ENV HOST=0.0.0.0

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD curl -f http://localhost:${PORT}/v1/health || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
