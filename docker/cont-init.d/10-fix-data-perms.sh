#!/command/with-contenv sh
set -eu

DATA_DIR="/var/www/html/data"
OPDS_CACHE_DIR="${DATA_DIR}/opds-cache"
THUMB_DIR="/var/www/html/thumb"
RUNTIME_UID="${PUID:-33}"
RUNTIME_GID="${PGID:-33}"

mkdir -p "${DATA_DIR}" "${OPDS_CACHE_DIR}" "${THUMB_DIR}"

# Ensure runtime services can write sqlite/log files using the requested
# container uid/gid instead of hardcoded www-data (33:33).
chown -R "${RUNTIME_UID}:${RUNTIME_GID}" "${DATA_DIR}" "${THUMB_DIR}" || true
chmod 0777 "${DATA_DIR}" "${OPDS_CACHE_DIR}" || true

# sqlite side files may be created later; fix common existing artifacts on boot.
find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.sqlite' -o -name '*.sqlite-*' \) -exec chmod 0666 {} \; || true
find "${DATA_DIR}" -maxdepth 1 -type f \( -name '*.lock' -o -name '*.key' -o -name '*.log' \) -exec chmod 0666 {} \; || true
find "${OPDS_CACHE_DIR}" -maxdepth 1 -type f -name '*.xml' -exec chmod 0666 {} \; || true
