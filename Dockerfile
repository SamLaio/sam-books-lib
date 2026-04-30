FROM shinsenter/phpfpm-nginx:latest

WORKDIR /var/www/html

COPY ./site /var/www/html
COPY ./site-enable/00-default.conf /etc/nginx/sites-enabled/00-default.conf
COPY ./docker/cron/books-scan /etc/cron.d/books-scan
COPY ./docker/cont-init.d/10-fix-data-perms.sh /etc/cont-init.d/10-fix-data-perms.sh
COPY ./docker/cont-init.d/20-init-runtime.sh /etc/cont-init.d/20-init-runtime.sh
COPY ./docker/s6-overlay/s6-rc.d/cron /etc/s6-overlay/s6-rc.d/cron
COPY ./docker/s6-overlay/s6-rc.d/user/contents.d/cron /etc/s6-overlay/s6-rc.d/user/contents.d/cron

RUN mkdir -p /var/www/html/data /var/www/html/thumb /books \
    && chmod 0644 /etc/cron.d/books-scan \
    && chmod +x /etc/cont-init.d/10-fix-data-perms.sh \
    && chmod +x /etc/cont-init.d/20-init-runtime.sh \
    && chmod +x /etc/s6-overlay/s6-rc.d/cron/run

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/index.php >/dev/null || exit 1

EXPOSE 80 443
