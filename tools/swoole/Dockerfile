# PHP with Swoole for the Swoole job in CI. Swoole is built with its coroutine PostgreSQL and
# SQLite drivers, as deployments that use PostgreSQL under Swoole do: with them, PDO::connect()
# returns plain PDO objects instead of Pdo\Pgsql and Pdo\Sqlite, which Shirakaba must handle.
# It is kept out of the PHP that runs the rest of the suite, which tests the driver subclasses.
# CI caches the built image by this file's hash, so changing it rebuilds the image.
FROM php:8.5-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libssl-dev libcurl4-openssl-dev libpq-dev libsqlite3-dev \
    && docker-php-ext-install pdo_pgsql \
    && pecl install -D 'enable-sockets="no" enable-openssl="yes" enable-mysqlnd="no" enable-swoole-curl="yes" enable-cares="no" enable-brotli="no" enable-zstd="no" enable-swoole-pgsql="yes" with-swoole-odbc="no" with-swoole-oracle="no" enable-swoole-sqlite="yes" enable-swoole-thread="no" enable-iouring="no" enable-uring-socket="no"' swoole-6.2.6 \
    && docker-php-ext-enable swoole \
    # Keep the libraries the extensions load, drop the headers and the build tree.
    && apt-mark manual libssl3t64 libcurl4t64 libpq5 libsqlite3-0 >/dev/null \
    && apt-get purge -y --auto-remove libssl-dev libcurl4-openssl-dev libpq-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear /usr/src/php
