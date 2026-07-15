FROM php:8.3-apache

ENV TZ=Asia/Bangkok

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql curl mbstring gd fileinfo \
    && a2enmod rewrite headers expires \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -ri \
        -e 's/^expose_php = On/expose_php = Off/' \
        -e 's/^display_errors = On/display_errors = Off/' \
        -e 's/^upload_max_filesize = .*/upload_max_filesize = 4M/' \
        -e 's/^post_max_size = .*/post_max_size = 5M/' \
        -e 's/^memory_limit = .*/memory_limit = 128M/' \
        -e 's/^;date.timezone =.*/date.timezone = Asia\/Bangkok/' \
        "$PHP_INI_DIR/php.ini" \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN mkdir -p \
        /var/www/html/storage/private/slips \
        /var/www/html/storage/logs \
        /var/www/html/storage/sessions \
        /var/www/html/storage/cache \
    && chown -R www-data:www-data /var/www/html/storage \
    && chmod -R 0750 /var/www/html/storage \
    && chmod 0755 /var/www/html/scripts/start-web.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php scripts/check_requirements.php --db >/dev/null || exit 1

CMD ["/var/www/html/scripts/start-web.sh"]
