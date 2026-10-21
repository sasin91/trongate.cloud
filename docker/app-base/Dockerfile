# Base image of every customer app (modules/platform/tasks/deploy.php): FrankenPHP
# in classic mode with the extensions Trongate apps need, prebuilt so a deploy
# only copies the app in and runs composer. Built by the build_platform task (and
# bin/push-images.sh) as PLATFORM_APP_BASE_IMAGE, from the repository root.
#
# The app pod (Manifests::_deployment) runs it as uid 1000 with PORT=8080,
# mounts config/config.php, config/database.php and the Valkey sessions ini, and
# points Caddy's XDG dirs at /tmp.

# FrankenPHP's own image is php:<version>-zts plus its frankenphp binary, which
# links libphp.so at run time. Its 1.12-php8.4 image is still on PHP 8.4.26
# (CVE-2026-109618, CVE-2026-109619), so the binary goes onto the fixed stock
# image instead. Back to plain dunglas/frankenphp once it ships 8.4.27.
FROM dunglas/frankenphp:1.12.7-php8.4-trixie AS frankenphp

FROM php:8.4.27-zts-trixie

# What FrankenPHP's image adds to the stock one (php/frankenphp Dockerfile)
RUN apt-get update \
    && apt-get install -y --no-install-recommends mailcap libcap2-bin libbrotli1 libstdc++6 \
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

# git, zip and unzip: what composer needs to install packages
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# pdo_mysql: Trongate's database class. redis: the Valkey session handler
# (Manifests::sessions_ini). gd: Trongate's image uploads. The rest are common
# enough that an app rarely needs its own (php_extensions in its settings).
RUN install-php-extensions pdo_mysql redis gd intl zip bcmath exif \
    && rm -rf /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini
COPY docker/app-base/php.ini /usr/local/etc/php/conf.d/trongate-cloud.ini
COPY docker/app-base/Caddyfile /Caddyfile
# Run by the backup Job when the app is deleted (Manifests::backup_job)
COPY docker/app-base/backup.php /usr/local/bin/trongate-cloud-backup.php

ENV PORT=8080 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_FUND=0 \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

WORKDIR /app

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/Caddyfile", "--adapter", "caddyfile"]
