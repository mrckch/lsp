# syntax=docker/dockerfile:1.7

FROM php:8.4-fpm-bookworm AS base

ARG WWWUSER=1000
ARG WWWGROUP=1000

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# System-Pakete + PHP-Extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        git curl unzip libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev libxml2-dev libicu-dev libsodium-dev \
        default-mysql-client mariadb-client \
        ca-certificates gnupg supervisor gosu \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl mbstring pdo_mysql pcntl sockets sodium zip exif opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node 22 (für asset build via vite)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# Non-root user
RUN groupadd -g $WWWGROUP lsp \
    && useradd -u $WWWUSER -g lsp -m -s /bin/bash lsp \
    && mkdir -p /var/www/html \
    && chown -R lsp:lsp /var/www/html

# PHP-Konfig
COPY infra/app/php.ini /usr/local/etc/php/conf.d/zz-lsp.ini
COPY infra/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-lsp.conf

WORKDIR /var/www/html

# Entrypoint
COPY infra/app/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Container startet als root → Entrypoint setzt Permissions auf gemountete
# Bind-Mounts (storage/, bootstrap/cache) und droppt dann via 'su' zum
# unprivilegierten lsp-User. Siehe infra/app/entrypoint.sh.
EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
