#!/bin/sh
set -eu

APP_ROOT="/var/www/html"
DATA_DIR="${APP_ROOT}/data"
OPDS_CACHE_DIR="${DATA_DIR}/opds-cache"
THUMB_DIR="${APP_ROOT}/thumb"
RUNTIME_UID="${PUID:-82}"
RUNTIME_GID="${PGID:-82}"
RUNTIME_USER="${BOOKSLIB_RUNTIME_USER:-bookslib}"
RUNTIME_GROUP="${BOOKSLIB_RUNTIME_GROUP:-bookslib}"

ensure_runtime_identity() {
  existing_group="$(awk -F: -v gid="${RUNTIME_GID}" '$3 == gid { print $1; exit }' /etc/group || true)"
  if [ -n "${existing_group}" ]; then
    RUNTIME_GROUP="${existing_group}"
  else
    addgroup -S -g "${RUNTIME_GID}" "${RUNTIME_GROUP}" >/dev/null 2>&1 || true
    existing_group="$(awk -F: -v gid="${RUNTIME_GID}" '$3 == gid { print $1; exit }' /etc/group || true)"
    if [ -n "${existing_group}" ]; then
      RUNTIME_GROUP="${existing_group}"
    fi
  fi

  existing_user="$(awk -F: -v uid="${RUNTIME_UID}" '$3 == uid { print $1; exit }' /etc/passwd || true)"
  if [ -n "${existing_user}" ]; then
    RUNTIME_USER="${existing_user}"
  else
    adduser -S -D -H -h /tmp -s /sbin/nologin -u "${RUNTIME_UID}" -G "${RUNTIME_GROUP}" "${RUNTIME_USER}" >/dev/null 2>&1 || true
    existing_user="$(awk -F: -v uid="${RUNTIME_UID}" '$3 == uid { print $1; exit }' /etc/passwd || true)"
    if [ -n "${existing_user}" ]; then
      RUNTIME_USER="${existing_user}"
    fi
  fi
}

fix_runtime_permissions() {
  chown -R "${RUNTIME_UID}:${RUNTIME_GID}" "${DATA_DIR}" "${THUMB_DIR}" || true
  chmod 0775 "${DATA_DIR}" "${OPDS_CACHE_DIR}" "${THUMB_DIR}" || true
  find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.sqlite' -o -name '*.sqlite-*' \) -exec chown "${RUNTIME_UID}:${RUNTIME_GID}" {} \; -exec chmod 0664 {} \; || true
  find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.lock' -o -name '*.key' -o -name '*.log' \) -exec chown "${RUNTIME_UID}:${RUNTIME_GID}" {} \; -exec chmod 0664 {} \; || true
  find "${OPDS_CACHE_DIR}" -maxdepth 1 -type f -name '*.xml' -exec chown "${RUNTIME_UID}:${RUNTIME_GID}" {} \; -exec chmod 0664 {} \; || true
}

mkdir -p "${DATA_DIR}" "${OPDS_CACHE_DIR}" "${THUMB_DIR}" /run/nginx /var/log/nginx
ensure_runtime_identity
fix_runtime_permissions

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
user = ${RUNTIME_USER}
group = ${RUNTIME_GROUP}
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

su-exec "${RUNTIME_UID}:${RUNTIME_GID}" php "${APP_ROOT}/init_runtime.php"
fix_runtime_permissions

cat > "/etc/crontabs/${RUNTIME_USER}" <<EOF
* * * * * cd ${APP_ROOT} && /usr/local/bin/books-worker --app-root ${APP_ROOT} --once >> ${DATA_DIR}/cron.log 2>&1
EOF

crond -b -l 8
php-fpm -D
exec nginx -g 'daemon off;'
