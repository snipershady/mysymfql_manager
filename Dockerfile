# syntax=docker/dockerfile:1
FROM composer:2 AS composer

FROM php:8.4-apache-bookworm AS app

# --- System dependencies --------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        wget gnupg lsb-release ca-certificates openssl \
        libicu-dev libonig-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

# --- Official Oracle MySQL client (provides mysqldump/mysql) -------------
# See README.md "Installing mysqldump (Oracle MySQL client on Debian/Ubuntu)".
# Pinning the base image to -bookworm keeps it on a Debian release that the
# MySQL APT repo officially supports (component: mysql-8.4-lts).
# mysql-apt-config's own postinst only works through an interactive debconf
# dialog, so its GPG key + repo are extracted/added by hand instead of
# running `dpkg -i` on the package.
RUN set -eux; \
    wget -q https://dev.mysql.com/get/mysql-apt-config_0.8.36-1_all.deb -O /tmp/mysql-apt-config.deb; \
    mkdir -p /tmp/mysql-apt-config; \
    dpkg-deb -e /tmp/mysql-apt-config.deb /tmp/mysql-apt-config; \
    sed -n '/BEGIN PGP PUBLIC KEY BLOCK/,/END PGP PUBLIC KEY BLOCK/p' /tmp/mysql-apt-config/postinst \
        | gpg --dearmor -o /usr/share/keyrings/mysql-apt-config.gpg; \
    echo "deb [signed-by=/usr/share/keyrings/mysql-apt-config.gpg] http://repo.mysql.com/apt/debian/ bookworm mysql-8.4-lts" \
        > /etc/apt/sources.list.d/mysql.list; \
    rm -rf /tmp/mysql-apt-config /tmp/mysql-apt-config.deb; \
    apt-get update; \
    apt-get install -y --no-install-recommends mysql-client; \
    rm -rf /var/lib/apt/lists/*

# --- PHP extensions -------------------------------------------------------
ADD --chmod=755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql intl bcmath redis sockets pcntl opcache

COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-app-prod.ini

# --- Apache configuration --------------------------------------------------
# mod_ssl: Apache terminates TLS itself on 29443 (HTTP is not served at all),
# required for Altcha/WebCrypto which only run in a secure context.
RUN a2enmod rewrite headers ssl \
    && sed -ri 's/^Listen 80$/Listen 29443/' /etc/apache2/ports.conf
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
EXPOSE 29443

ENV APP_ENV=prod
ENV BACKUP_PATH=/var/backups/mysymfql
WORKDIR /var/www/html

# --- Dependencies (cached separately from the app source) -----------------
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist

# --- Application source ----------------------------------------------------
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# Asset Mapper only copies/versions static files, no DB/secrets involved,
# so it is safe to run at build time. importmap:install fetches the vendor
# JS packages (e.g. @hotwired/stimulus) declared in importmap.php.
RUN php bin/console importmap:install --env=prod \
    && php bin/console asset-map:compile --env=prod

RUN chown -R www-data:www-data var public/assets

COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
