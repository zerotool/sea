FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip librdkafka-dev libzip-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY composer.json composer.lock ./
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && rm composer-setup.php \
    && composer install --no-dev --optimize-autoloader --ignore-platform-req=ext-redis --ignore-platform-req=ext-rdkafka
COPY . .

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
