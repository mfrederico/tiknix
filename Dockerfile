# tiknix — FlightPHP + RedBeanPHP (SQLite).
# Container image for Spaceship Hyperlift (GitHub + Dockerfile deploy).
# Serves public/index.php via Apache + mod_rewrite (public/.htaccess routes to index.php).
FROM php:8.3-apache

# --- PHP extensions the app uses: pdo_sqlite, mbstring, sodium, gd (QR), zip, apcu ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip sqlite3 \
        libsodium-dev libonig-dev libzip-dev libsqlite3-dev \
        libpng-dev libjpeg-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite mbstring sodium gd zip \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && printf 'apc.enable=1\napc.enable_cli=1\n' > /usr/local/etc/php/conf.d/docker-apcu.ini \
    && rm -rf /var/lib/apt/lists/*

# --- Composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Apache: docroot -> public/, allow .htaccess overrides, enable rewrite ---
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/tiknix.conf \
    && a2enconf tiknix \
    && a2enmod rewrite

WORKDIR /var/www/html
COPY . /var/www/html

# --- PHP dependencies (production) + runtime dirs ---
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-progress \
    && mkdir -p database storage/logs \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
