#!/usr/bin/env sh
set -eu

if [ "${DB_CONNECTION:-}" = "pgsql" ] || [ "${DB_CONNECTION:-}" = "postgres" ]; then
  until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-laravel}" >/dev/null 2>&1; do
    echo "Waiting for PostgreSQL at ${DB_HOST:-db}:${DB_PORT:-5432}..."
    sleep 2
  done
fi

if [ -z "${APP_KEY:-}" ] || [ "${APP_KEY}" = "base64:" ]; then
  php artisan key:generate --force --no-interaction || true
fi

php artisan config:cache
php artisan migrate --force --no-interaction

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
