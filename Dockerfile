# syntax=docker/dockerfile:1

FROM node:20-alpine AS frontend-build
WORKDIR /app

COPY package*.json ./
RUN npm install

COPY . .
RUN npm run build

FROM php:8.2-fpm-alpine
WORKDIR /var/www/html

RUN apk add --no-cache \
    bash \
    curl \
    freetype-dev \
    git \
    libjpeg-turbo-dev \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-client \
    postgresql-dev \
    sqlite \
    sqlite-dev \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pdo_sqlite gd zip bcmath exif pcntl \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

COPY . .
COPY --from=frontend-build /app/public/build ./public/build

RUN composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader --no-dev \
    && php artisan storage:link || true \
    && chown -R www-data:www-data storage bootstrap/cache /var/www/html

EXPOSE 8080

CMD ["sh", "./docker/start.sh"]
