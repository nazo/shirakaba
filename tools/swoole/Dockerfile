# PHP with Swoole for the Swoole job in CI. Swoole is built with its coroutine PostgreSQL and
# SQLite drivers, as deployments that use PostgreSQL under Swoole do: with them, PDO::connect()
# returns plain PDO objects instead of Pdo\Pgsql and Pdo\Sqlite, which Shirakaba must handle.
# It is kept out of the PHP that runs the rest of the suite, which tests the driver subclasses.
# CI caches the built image by this file's hash, so changing it rebuilds the image.
#
# Swoole is installed with PIE, the PHP Foundation's installer that replaced PECL. Configure
# options that are not passed keep Swoole's defaults, which leave the other features off.
FROM php:8.5-cli
ARG PIE_VERSION=1.5.5
# The digest GitHub lists for the pie.phar asset of PIE_VERSION; update it together with the version.
ARG PIE_SHA256=2ca0dff6868f1b53c095625674deef38ac698588e0a25e4321cd834a5b7553bc
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libssl-dev libcurl4-openssl-dev libpq-dev libsqlite3-dev \
    && docker-php-ext-install pdo_pgsql \
    && curl -fsSL -o /usr/local/bin/pie "https://github.com/php/pie/releases/download/${PIE_VERSION}/pie.phar" \
    && echo "${PIE_SHA256}  /usr/local/bin/pie" | sha256sum --check \
    && chmod +x /usr/local/bin/pie \
    && pie install --no-interaction swoole/swoole:6.2.6 --enable-swoole-curl --enable-swoole-pgsql --enable-swoole-sqlite \
    # Keep the libraries the extensions load, drop the headers, the build tree and PIE.
    && apt-mark manual libssl3t64 libcurl4t64 libpq5 libsqlite3-0 >/dev/null \
    && apt-get purge -y --auto-remove unzip libssl-dev libcurl4-openssl-dev libpq-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* /usr/src/php /usr/local/bin/pie /root/.pie /root/.config/pie
