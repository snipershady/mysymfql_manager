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

# `app:setup` (see README.md) decides whether it still needs to ask the
# .env.local questions by re-reading .env.local's *file content* — it
# ignores real process env vars for that check. Since this image is
# configured entirely through real env vars (docker/app.env), generate
# .env.local from them here so `app:setup` skips straight to the
# migration + admin-user step instead of re-asking already-known answers.
if [ ! -f .env.local ] && [ -n "${APP_SECRET:-}" ] && [ -n "${SQLCLIENT_ENCRYPTION_KEY:-}" ] && [ -n "${ALTCHAKEY:-}" ] && [ -n "${APP_DB_NAME:-}" ] && [ -n "${APP_DB_HOSTNAME:-}" ] && [ -n "${APP_DB_USER:-}" ] && [ -n "${APP_DB_PASSWORD:-}" ] && [ -n "${BACKUP_PATH:-}" ]; then
    cat > .env.local <<EOF
APP_SECRET=$APP_SECRET
SQLCLIENT_ENCRYPTION_KEY=$SQLCLIENT_ENCRYPTION_KEY
ALTCHAKEY=$ALTCHAKEY
APP_DB_NAME=$APP_DB_NAME
APP_DB_HOSTNAME=$APP_DB_HOSTNAME
APP_DB_PORT=${APP_DB_PORT:-3306}
APP_DB_USER=$APP_DB_USER
APP_DB_PASSWORD=$APP_DB_PASSWORD
BACKUP_PATH=$BACKUP_PATH
MAILER_DSN=${MAILER_DSN:-null://null}
EOF
    chown www-data:www-data .env.local
    chmod 600 .env.local
fi

# Altcha (and other WebCrypto-based widgets) only work in a secure context,
# so Apache always terminates TLS on 29443. Generate a self-signed
# certificate if none was provided (mount real ones at /etc/apache2/ssl).
SSL_DIR=/etc/apache2/ssl
SSL_CERT="$SSL_DIR/cert.pem"
SSL_KEY="$SSL_DIR/key.pem"
mkdir -p "$SSL_DIR"
if [ ! -s "$SSL_CERT" ] || [ ! -s "$SSL_KEY" ]; then
    echo "No certificate found in $SSL_DIR, generating a self-signed one (CN=${SSL_CERT_CN:-localhost})."
    openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
        -keyout "$SSL_KEY" -out "$SSL_CERT" \
        -subj "/CN=${SSL_CERT_CN:-localhost}" \
        -addext "basicConstraints=critical,CA:FALSE" \
        -addext "keyUsage=digitalSignature,keyEncipherment" \
        -addext "extendedKeyUsage=serverAuth"
fi
chmod 600 "$SSL_KEY"
chown www-data:www-data "$SSL_CERT" "$SSL_KEY"

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
