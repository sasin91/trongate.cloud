FROM php:8.4-apache AS base

# Install dependencies and extensions
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libsodium-dev \
    openssh-client \
    rsync \
    git \
    unzip \
    && docker-php-ext-install \
    pdo_mysql \
    mysqli \
    intl \
    sodium \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /var/www/html

# Configure Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Development stage
FROM base AS dev
RUN pecl install xdebug && docker-php-ext-enable xdebug
COPY docker/dev/xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini
# Files mounted via docker-compose.dev.yml volume

# Production stage
FROM base AS prod
COPY . /var/www/html
# Remove development files if needed
# RUN rm -rf tests/ ...
