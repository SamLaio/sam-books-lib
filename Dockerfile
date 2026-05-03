FROM php:8.4-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        curl \
        freetype \
        icu-libs \
        libjpeg-turbo \
        libpng \
        libzip \
        nginx \
        procps \
        sqlite-libs \
        tzdata \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        freetype-dev \
        icu-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libzip-dev \
        oniguruma-dev \
        sqlite-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_sqlite \
        zip \
    && apk del .build-deps \
    && mkdir -p /run/nginx /var/log/nginx /var/www/html/data /var/www/html/thumb /books

COPY ./site /var/www/html
COPY ./docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY ./docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY ./docker/bookslib-entrypoint.sh /usr/local/bin/bookslib-entrypoint

RUN chmod +x /usr/local/bin/bookslib-entrypoint

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/index.php >/dev/null || exit 1

EXPOSE 80

ENTRYPOINT ["bookslib-entrypoint"]
