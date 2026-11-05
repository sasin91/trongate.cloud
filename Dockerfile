# Platform app (trongate.cloud): FrankenPHP serving public/ on :8080 as a non-root user.
# FrankenPHP's binary on the stock PHP image, as for docker/app-base/Dockerfile:
# its own 1.12-php8.4 image is still on PHP 8.4.26 (CVE-2026-109618, CVE-2026-109619)
FROM dunglas/frankenphp:1.12.7-php8.4-bookworm AS frankenphp

FROM php:8.4.27-zts-bookworm

# git: clone and push of trongate.cloud git (modules/git runs git http-backend)
RUN apt-get update \
    && apt-get install -y --no-install-recommends mailcap libcap2-bin libbrotli1 libstdc++6 git \
    && apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=frankenphp /usr/local/lib/libwatcher-c.so* /usr/local/lib/
COPY --from=frankenphp /usr/local/bin/frankenphp /usr/local/bin/install-php-extensions /usr/local/bin/
# `frankenphp version` names the PHP it was compiled against; the build log
# shows the one it runs
RUN ldconfig \
    && setcap cap_net_bind_service=+ep /usr/local/bin/frankenphp \
    && echo '<?php echo "Serving PHP ", PHP_VERSION, "\n";' > /tmp/version.php \
    && frankenphp php-cli /tmp/version.php \
    && rm /tmp/version.php
ENV GODEBUG=cgocheck=0

# redis: PHP sessions in the shared Valkey (engine/Valkey_sessions.php)
# zip: sample modules downloaded from the docs (modules/docs)
RUN install-php-extensions pdo_mysql intl sodium redis zip

ENV PORT=8080 \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

COPY docker/Caddyfile /etc/frankenphp/Caddyfile
# Upload size for the Database tab's .sql file import, in parts (docker/php.ini)
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-trongate-cloud.ini

WORKDIR /app
COPY --chown=root:root . /app

USER www-data

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
