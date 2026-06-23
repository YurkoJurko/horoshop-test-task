FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip zip curl libicu-dev libonig-dev libxml2-dev libzip-dev \
    && docker-php-ext-install pdo pdo_mysql intl opcache zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer