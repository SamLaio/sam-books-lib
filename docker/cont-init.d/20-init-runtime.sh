#!/command/with-contenv sh
set -eu

DATA_DIR="/var/www/html/data"
THUMB_DIR="/var/www/html/thumb"
RUNTIME_UID="${PUID:-33}"
RUNTIME_GID="${PGID:-33}"

php /var/www/html/init_runtime.php

# init_runtime runs during cont-init as root, so normalize ownership again
# after bootstrap-created sqlite, lock, key and log files are present.
chown -R "${RUNTIME_UID}:${RUNTIME_GID}" "${DATA_DIR}" "${THUMB_DIR}" || true
find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.lock' -o -name '*.key' -o -name '*.log' \) -exec chmod 0666 {} \; || true
