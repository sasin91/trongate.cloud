# Platform app (trongate.cloud): FrankenPHP serving public/ on :8080 as a non-root user.
FROM dunglas/frankenphp:1.12-php8.4-bookworm

RUN install-php-extensions pdo_mysql mysqli intl sodium

ENV PORT=8080 \
    SERVER_NAME=:8080 \
    XDG_CONFIG_HOME=/tmp/caddy/config \
    XDG_DATA_HOME=/tmp/caddy/data

COPY docker/Caddyfile /etc/frankenphp/Caddyfile

WORKDIR /app
COPY --chown=root:root . /app

USER www-data

EXPOSE 8080

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
