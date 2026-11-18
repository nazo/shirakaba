#!/bin/sh
# Runs PHPUnit against SQLite, MySQL 8.4 and PostgreSQL 17 in Docker (composer test:databases),
# with Mailpit for the mailer's SMTP test and Redis for the Redis client, sessions and queue.
# Arguments go to PHPUnit, for example: tools/databases/test.sh packages/orm/tests
# Containers this script starts are stopped afterwards; running ones are reused and left running.
set -eu

root=$(cd "$(dirname "$0")/../.." && pwd)

docker image inspect shirakaba-php-db >/dev/null 2>&1 || docker build -t shirakaba-php-db "$root/tools/databases"
docker network inspect shirakaba-net >/dev/null 2>&1 || docker network create shirakaba-net >/dev/null

started=""
if ! docker ps --format '{{.Names}}' | grep -qx shirakaba-mysql; then
    docker run -d --rm --name shirakaba-mysql --network shirakaba-net \
        -e MYSQL_ROOT_PASSWORD=shirakaba -e MYSQL_DATABASE=shirakaba mysql:8.4 >/dev/null
    started="$started shirakaba-mysql"
fi
if ! docker ps --format '{{.Names}}' | grep -qx shirakaba-postgres; then
    docker run -d --rm --name shirakaba-postgres --network shirakaba-net \
        -e POSTGRES_PASSWORD=shirakaba -e POSTGRES_DB=shirakaba postgres:17 >/dev/null
    started="$started shirakaba-postgres"
fi
if ! docker ps --format '{{.Names}}' | grep -qx shirakaba-mailpit; then
    docker run -d --rm --name shirakaba-mailpit --network shirakaba-net axllent/mailpit:v1.27 >/dev/null
    started="$started shirakaba-mailpit"
fi
if ! docker ps --format '{{.Names}}' | grep -qx shirakaba-redis; then
    docker run -d --rm --name shirakaba-redis --network shirakaba-net redis:8.8.4-alpine --requirepass shirakaba >/dev/null
    started="$started shirakaba-redis"
fi

until docker exec shirakaba-mysql mysqladmin ping -uroot -pshirakaba --silent >/dev/null 2>&1 \
    && docker exec shirakaba-postgres pg_isready -U postgres >/dev/null 2>&1; do
    sleep 2
done
# MySQL answers pings before it accepts the test user on a fresh start.
sleep 3

status=0
docker run --rm --network shirakaba-net -v "$root":/app:ro -w /app \
    -e SHIRAKABA_TEST_MYSQL_DSN='mysql:host=shirakaba-mysql;dbname=shirakaba' -e SHIRAKABA_TEST_MYSQL_USER=root -e SHIRAKABA_TEST_MYSQL_PASSWORD=shirakaba \
    -e SHIRAKABA_TEST_PGSQL_DSN='pgsql:host=shirakaba-postgres;dbname=shirakaba' -e SHIRAKABA_TEST_PGSQL_USER=postgres -e SHIRAKABA_TEST_PGSQL_PASSWORD=shirakaba \
    -e SHIRAKABA_TEST_REDIS_URL='redis://:shirakaba@shirakaba-redis:6379/1' \
    -e SHIRAKABA_TEST_SMTP_DSN='smtp://shirakaba-mailpit:1025?tls=none' -e SHIRAKABA_TEST_MAILPIT_API='http://shirakaba-mailpit:8025' \
    shirakaba-php-db php -d memory_limit=1G vendor/bin/phpunit -c phpunit.dist.xml --do-not-record-test-run-history "$@" || status=$?

if [ -n "$started" ]; then
    docker stop $started >/dev/null
fi
exit $status
