# PHP with the PDO drivers the database suites need (shirakaba-php-db): tools/databases/test.sh builds it.
FROM php:8.5-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev git \
    && docker-php-ext-install pdo_pgsql pdo_mysql pcntl zip \
    && rm -rf /var/lib/apt/lists/*
