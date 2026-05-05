#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
DATA_DIR="${APP_ROOT}/data"
OPDS_CACHE_DIR="${DATA_DIR}/opds-cache"
THUMB_DIR="${APP_ROOT}/thumb"
RUNTIME_UID="${PUID:-82}"
RUNTIME_GID="${PGID:-82}"

mkdir -p "${DATA_DIR}" "${OPDS_CACHE_DIR}" "${THUMB_DIR}" /run/nginx /var/log/nginx
chown -R "${RUNTIME_UID}:${RUNTIME_GID}" "${DATA_DIR}" "${THUMB_DIR}" || true
chmod 0777 "${DATA_DIR}" "${OPDS_CACHE_DIR}" || true
find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.lock' -o -name '*.key' -o -name '*.log' \) -exec chmod 0666 {} \; || true
find "${OPDS_CACHE_DIR}" -maxdepth 1 -type f -name '*.xml' -exec chmod 0666 {} \; || true

cat > /usr/local/etc/php/conf.d/zz-bookslib.ini <<EOF
memory_limit=${PHP_MEMORY_LIMIT:-64M}
date.timezone=${TZ:-UTC}
opcache.enable=${PHP_OPCACHE_ENABLE:-1}
opcache.enable_cli=0
opcache.memory_consumption=${PHP_OPCACHE_MEMORY_CONSUMPTION:-32}
opcache.interned_strings_buffer=${PHP_OPCACHE_INTERNED_STRINGS_BUFFER:-8}
opcache.max_accelerated_files=${PHP_OPCACHE_MAX_ACCELERATED_FILES:-10000}
opcache.validate_timestamps=${PHP_OPCACHE_VALIDATE_TIMESTAMPS:-0}
EOF

cat > /usr/local/etc/php-fpm.d/zz-bookslib.conf <<EOF
[www]
user = www-data
group = www-data
listen = 127.0.0.1:9000
listen.allowed_clients = 127.0.0.1
pm = ${PHP_PM:-ondemand}
pm.max_children = ${PHP_PM_MAX_CHILDREN:-1}
pm.start_servers = ${PHP_PM_START_SERVERS:-1}
pm.min_spare_servers = ${PHP_PM_MIN_SPARE_SERVERS:-1}
pm.max_spare_servers = ${PHP_PM_MAX_SPARE_SERVERS:-1}
pm.max_requests = ${PHP_PM_MAX_REQUESTS:-40}
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
EOF

php "${APP_ROOT}/init_runtime.php"
chown -R "${RUNTIME_UID}:${RUNTIME_GID}" "${DATA_DIR}" "${THUMB_DIR}" || true
chmod 0777 "${DATA_DIR}" "${OPDS_CACHE_DIR}" || true
find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.lock' -o -name '*.key' -o -name '*.log' \) -exec chmod 0666 {} \; || true
find "${OPDS_CACHE_DIR}" -maxdepth 1 -type f -name '*.xml' -exec chmod 0666 {} \; || true

cat > /etc/crontabs/root <<EOF
* * * * * cd ${APP_ROOT} && /usr/local/bin/books-worker --app-root ${APP_ROOT} --once >> ${DATA_DIR}/cron.log 2>&1
EOF

crond -b -l 8
php-fpm -D
exec nginx -g 'daemon off;'
