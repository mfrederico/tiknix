# tiknix-base — PHP 8.3 + Apache with all tiknix runtime extensions precompiled.
#
# Built ONCE and pushed to a registry (ghcr.io/mfrederico/tiknix-base:8.3); the app
# Dockerfile does `FROM` this image, so per-deploy builds NEVER compile extensions.
# Rebuild/republish only when the PHP version or extension set changes, via either:
#   - GitHub Actions:  .github/workflows/base-image.yml  (Run workflow)
#   - Local docker:    ./docker/build-base.sh
# Matches the PHP the control plane runs (8.5.x), so a tenant container and core
# behave identically — a version skew here shows up as bugs that only reproduce in
# one place. Publish this as a NEW tag (…-base:8.5): Proxmox refuses to overwrite an
# existing template, so an image can never be updated in place under the same tag.
FROM php:8.5-apache

# Extensions: pdo_sqlite, pdo_mysql, mbstring, sodium, gd (PNG-only), zip, intl, apcu.
# intl is REQUIRED, not optional: translatify/translatify declares ext-intl, so without
# it `composer install` fails outright and the app has no vendor/ at all. Core has intl,
# so this only ever bit deploys that build their dependencies inside the image.
# pdo_mysql: the app runs on SQLite, but RedBeanPHP's Functions.php references
# PDO::MYSQL_ATTR_INIT_COMMAND at autoload time and fatals if pdo_mysql is absent
# (it uses bundled mysqlnd, so no extra libs). gd is PNG-only on purpose — skipping
# jpeg/webp/avif (rav1e/svt-av1) keeps the build lean; the app only needs PNG (2FA QR
# codes render as SVG). These compiles run HERE, once, so app images never pay for them.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip sqlite3 \
        libsodium-dev libonig-dev libzip-dev libsqlite3-dev libpng-dev libicu-dev \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite pdo_mysql mbstring sodium gd zip intl \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && printf 'apc.enable=1\napc.enable_cli=1\n' > /usr/local/etc/php/conf.d/docker-apcu.ini \
    && rm -rf /var/lib/apt/lists/*

# Composer (for the app stage's `composer install`).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: docroot -> public/, allow .htaccess overrides, enable rewrite.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/tiknix.conf \
    && a2enconf tiknix \
    && a2enmod rewrite

WORKDIR /var/www/html
