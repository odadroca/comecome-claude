# ComeCome — self-host container image.
# PHP + Apache + SQLite (PDO). libsodium enables the optional at-rest field
# encryption. No build step; index.php is the single entry point.
FROM php:8.3-apache

# --- PHP extensions ---------------------------------------------------------
# pdo_sqlite : the app's only DB driver (SQLite via PDO).
# sodium     : required ONLY for opt-in at-rest field encryption
#              (includes/crypto.php); harmless to include otherwise.
# curl is installed for the container HEALTHCHECK below.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libsodium-dev libsqlite3-dev curl; \
    docker-php-ext-install pdo_sqlite sodium; \
    apt-get purge -y --auto-remove libsodium-dev libsqlite3-dev; \
    rm -rf /var/lib/apt/lists/*

# --- Apache -----------------------------------------------------------------
# The tracked .htaccess uses mod_rewrite (pretty URLs + HTTPS redirect),
# mod_headers (security headers / HSTS) and mod_expires (asset caching).
# AllowOverride All so it is honoured.
RUN set -eux; \
    a2enmod rewrite headers expires; \
    sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Recommended production PHP hardening.
RUN { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
    } > /usr/local/etc/php/conf.d/comecome.ini

# --- App --------------------------------------------------------------------
COPY . /var/www/html/

# Keep the SQLite DB OUTSIDE the web root, in a mounted volume. config.php reads
# COMECOME_DB_PATH; www-data must own /data to create the DB + its -wal/-shm.
ENV COMECOME_DB_PATH=/data/data.db
RUN set -eux; \
    mkdir -p /data; \
    chown -R www-data:www-data /data /var/www/html

VOLUME ["/data"]
EXPOSE 80

# The app is designed to sit behind a TLS-terminating proxy (see docker-compose
# + Caddyfile). The login page should answer 200 over HTTP inside the container.
# X-Forwarded-Proto: https mirrors the TLS proxy so .htaccess does not 301 the
# check; verifies PHP actually renders (not just that Apache is up).
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD curl -fsS -H "X-Forwarded-Proto: https" "http://localhost/?page=login" >/dev/null || exit 1
