#!/usr/bin/env sh
set -eu

# Railway injects configuration as real environment variables. There is no .env file
# on the server - it is gitignored and never deployed - so anything the app needs must
# exist as a Railway service variable.

# A missing APP_KEY makes every request touching sessions or encryption a 500, with the
# real reason buried once APP_DEBUG is off. Fail loudly at boot instead.
if [ -z "${APP_KEY:-}" ]; then
  echo "FATAL: APP_KEY is not set."
  echo "       Run 'php artisan key:generate --show' locally and set the resulting"
  echo "       'base64:...' value as an APP_KEY variable in Railway."
  exit 1
fi

: "${DB_CONNECTION:=sqlite}"
: "${DB_DATABASE:=/app/storage/database/database.sqlite}"
export DB_CONNECTION DB_DATABASE

# A Railway volume mounted at /app/storage starts empty and shadows the storage
# subdirectories baked into the image, which Laravel reports as "Please provide a valid
# cache path". Recreate them on every boot so the app works with or without a volume.
mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  storage/app/public

if [ "${DB_CONNECTION}" = "sqlite" ]; then
  mkdir -p "$(dirname "${DB_DATABASE}")"
  if [ ! -f "${DB_DATABASE}" ]; then
    echo "Creating SQLite database at ${DB_DATABASE}"
    touch "${DB_DATABASE}"
    DB_IS_NEW=1
  fi
fi

php artisan config:cache
php artisan migrate --force --no-interaction

# Seed only a database we just created, so demo data exists on first boot without
# duplicating itself on every redeploy.
if [ "${DB_IS_NEW:-0}" = "1" ] && [ "${SEED_ON_FIRST_BOOT:-true}" = "true" ]; then
  echo "Fresh database - running seeders"
  php artisan db:seed --force --no-interaction
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"
