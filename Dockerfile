# tiknix — FlightPHP + RedBeanPHP (SQLite). Container image for Spaceship Hyperlift
# (GitHub + Dockerfile deploy). Serves public/index.php via Apache + mod_rewrite.
#
# FROM a prebuilt base (extensions + apache + composer already baked in), so THIS
# per-deploy build compiles nothing — it only installs PHP deps. If the base image
# is missing/unavailable, build+publish it first: run the "base-image" GitHub Action
# (or ./docker/build-base.sh) and make the GHCR package public so Hyperlift can pull it.
FROM ghcr.io/mfrederico/tiknix-base:8.3

WORKDIR /var/www/html
COPY . /var/www/html

# PHP dependencies (production) + runtime dirs.
RUN composer install --no-dev --no-interaction --optimize-autoloader --no-progress \
    && mkdir -p database storage/logs \
    && chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
