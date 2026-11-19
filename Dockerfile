FROM php:8.3-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update && apt-get install -y --no-install-recommends \
      curl git unzip libcurl4-openssl-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
      libc-client2007e-dev libkrb5-dev libssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl pdo_mysql gd \
    && yes '' | pecl install imap \
    && docker-php-ext-enable imap \
    && a2enmod rewrite headers remoteip \
    && { echo 'RemoteIPHeader X-Forwarded-For'; echo 'RemoteIPTrustedProxy 172.16.0.0/12'; echo 'RemoteIPTrustedProxy 10.0.0.0/8'; echo 'RemoteIPTrustedProxy 192.168.0.0/16'; } > /etc/apache2/conf-available/tg-remoteip.conf \
    && a2enconf tg-remoteip \
    && printf '<Directory /var/www/html>\nOptions FollowSymLinks\nAllowOverride All\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/tg-gateways.conf \
    && a2enconf tg-gateways \
    && rm -rf /var/lib/apt/lists/*

# Production php.ini: display_errors off (stray notices must never reach a JSON
# response or a checkout page), errors to stderr for `docker compose logs`.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-tg-gateways.ini

WORKDIR /var/www/html
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
COPY . .
RUN chown -R www-data:www-data /var/www/html && mkdir -p storage && chown -R www-data:www-data storage

EXPOSE 80
