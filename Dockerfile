FROM php:8.2-alpine AS build

RUN apk add --no-cache curl zip unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-progress

COPY . .
RUN composer install --no-dev --prefer-dist --no-progress

FROM php:8.2-alpine AS production

RUN apk add --no-cache curl

WORKDIR /app

COPY --from=build /app /app

ENV PORT=8080
ENV HOST=0.0.0.0

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD curl -f http://localhost:${PORT}/health || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public/"]
