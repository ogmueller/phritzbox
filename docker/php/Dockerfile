FROM php:8.5-cli-bookworm

RUN apk add --no-cache \
        sqlite-libs \
        sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-enable pdo_sqlite

WORKDIR /application
