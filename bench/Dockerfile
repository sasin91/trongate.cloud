# Benchmark image: stock Trongate v2 with a hello-world route, on the same FrankenPHP
# base, php.ini and Caddyfile that Railpack gives customer apps (railpack v0.40.1).
#   docker buildx build --platform linux/amd64 -f bench/Dockerfile --build-arg SESSION_START=lazy ...
# SESSION_START=stock keeps Trongate's session_start() on every request; lazy uses
# app/ignition.lazy.php. PHP_SESSION_HANDLER/PHP_SESSION_PATH pick files or Valkey at runtime.
FROM dunglas/frankenphp:php8.4.26-trixie

ARG TRONGATE_REF=cc968cf
ARG SESSION_START=stock

RUN install-php-extensions pdo_mysql redis \
    && apt-get update && apt-get install -y --no-install-recommends git && rm -rf /var/lib/apt/lists/* \
    && git clone https://github.com/trongate/trongate-framework.git /app \
    && git -C /app checkout -q "${TRONGATE_REF}" && rm -rf /app/.git \
    && sed -i "s|define('BASE_URL', '\*\*\*\*');|define('BASE_URL', 'http://localhost/');|; s|define('ENV', 'dev');|define('ENV', 'live');|" /app/config/config.php \
    && grep -q "define('ENV', 'live')" /app/config/config.php \
    && mkdir -p /app/modules/bench \
    && echo '<?php echo "Hello World";' > /app/public/raw.php

COPY bench/app/Bench.php /app/modules/bench/Bench.php
COPY bench/app/ignition.lazy.php /tmp/ignition.lazy.php
RUN if [ "${SESSION_START}" = lazy ]; then cp /tmp/ignition.lazy.php /app/engine/ignition.php; fi \
    && grep -c "session_start" /app/engine/ignition.php

COPY bench/php.ini /usr/local/etc/php/conf.d/php.ini
COPY bench/Caddyfile /Caddyfile
RUN printf 'session.save_handler=${PHP_SESSION_HANDLER}\nsession.save_path="${PHP_SESSION_PATH}"\n' \
    > /usr/local/etc/php/conf.d/zz-session.ini

ENV PORT=8080 PHP_SESSION_HANDLER=files PHP_SESSION_PATH= CADDY_LOG_LEVEL=DEBUG \
    XDG_CONFIG_HOME=/tmp/caddy/config XDG_DATA_HOME=/tmp/caddy/data
USER 1000:1000
EXPOSE 8080
CMD ["frankenphp", "run", "--config", "/Caddyfile", "--adapter", "caddyfile"]
