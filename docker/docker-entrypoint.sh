#!/bin/sh
set -e

APP_DIR=/var/www/html
APP_ENV="${APP_ENV:-prod}"

cd "$APP_DIR"

mkdir -p var/cache var/log var/share
chown -R www-data:www-data var

if [ -n "${BACKUP_PATH:-}" ]; then
    mkdir -p "$BACKUP_PATH"
    chown www-data:www-data "$BACKUP_PATH" || true
fi

# Container restarts must rebuild the cache against the *real* runtime env
# vars (DB host, secrets, ...), which only exist now, not at build time.
php bin/console cache:clear --env="$APP_ENV" --no-warmup
php bin/console cache:warmup --env="$APP_ENV"

# Applies any migration files already committed to migrations/. The very
# first deployment still needs an interactive
# `docker exec -it <container> php bin/console app:setup` (see README.md)
# to generate the initial schema diff and create the first admin user.
if ls migrations/*.php >/dev/null 2>&1; then
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env="$APP_ENV"
fi

exec "$@"
