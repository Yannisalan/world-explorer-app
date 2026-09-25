FROM php:8.3-apache

# pdo_pgsql is not in the base image's default extension set, and the app talks
# to Neon over PostgreSQL exclusively, so it is the one extension worth adding.
# libpq-dev supplies the headers; it is dropped from the final layer by the
# single-apt-get-run below.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libpq-dev; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql; \
    a2enmod rewrite headers expires; \
    rm -rf /var/lib/apt/lists/*

# The base image ships `AllowOverride None` for /var/www, which would make the
# .htaccess rules that block dotfile and source access silently inert.
RUN set -eux; \
    sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf; \
    printf '%s\n' \
        'ServerTokens Prod' \
        'ServerSignature Off' \
        > /etc/apache2/conf-available/hardening.conf; \
    a2enconf hardening

WORKDIR /var/www/html

COPY . /var/www/html

# The app only ever reads data/*.json, but session files land in the system
# temp dir and the container runs as root before Apache drops privileges, so
# own the tree to avoid permission surprises if a writable path is added later.
RUN chown -R www-data:www-data /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/world-explorer-entrypoint
RUN chmod +x /usr/local/bin/world-explorer-entrypoint

# Render injects $PORT at runtime and the base image hardcodes Listen 80, so
# the port has to be templated at boot rather than baked in at build.
ENTRYPOINT ["/usr/local/bin/world-explorer-entrypoint"]
CMD ["apache2-foreground"]
