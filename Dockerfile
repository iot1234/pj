FROM php:8.3-apache@sha256:a05f87f7f1e3927b9f3a44d64c01dfe15992328fa179bdfa72ad06e66769ff57

ENV TZ=Asia/Bangkok

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        curl \
        default-mysql-client \
        gosu \
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
    && chmod 0755 \
        /var/www/html/scripts/bootstrap_database.sh \
        /var/www/html/scripts/provision_runtime_db_user.sh \
        /var/www/html/scripts/setup-database.sh \
        /var/www/html/scripts/start-web.sh \
        /var/www/html/scripts/start-worker.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD role="${RUNTIME_ROLE:-web}"; \
        if [ "$role" = "web" ]; then \
            curl --fail --silent --show-error --max-time 4 \
                "http://127.0.0.1:${PORT:-80}/healthz.php" \
                | grep --quiet '"status":"ok"'; \
        elif [ "$role" = "worker" ]; then \
            worker_uid="$(id -u www-data)" \
                && worker_gid="$(id -g www-data)" \
                && grep --quiet "^Uid:[[:space:]]*${worker_uid}[[:space:]]" /proc/1/status \
                && grep --quiet "^Gid:[[:space:]]*${worker_gid}[[:space:]]" /proc/1/status; \
        elif [ "$role" = "job" ]; then \
            exit 0; \
        else \
            exit 1; \
        fi

CMD ["/var/www/html/scripts/start-web.sh"]
