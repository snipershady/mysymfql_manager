#!/usr/bin/env bash
# Browser smoke test: starts the app in the test environment, runs tests/browser/smoke.mjs in headless Chrome,
# then stops the server and drops the demo database. Needs Node >= 22 and Chrome (CHROME_BIN).
set -euo pipefail
cd "$(dirname "$0")/../.."

PORT="${SMOKE_PORT:-8099}"
LOG="var/log/smoke-server.log"
mkdir -p var/log

# Compiled assets (asset-map:compile) take precedence over assets/ in every environment: the smoke test
# would silently check stale files. They belong to production deploys only.
if [ -f public/assets/manifest.json ]; then
    echo "public/assets/ contains compiled assets that shadow assets/: remove it (rm -rf public/assets) and retry." >&2
    exit 1
fi

php bin/console cache:clear --env=test > /dev/null
php tests/browser/seed.php up

# variables_order=EGPCS: the built-in server must see APP_ENV, otherwise the app boots in dev.
APP_ENV=test php -d variables_order=EGPCS -S "127.0.0.1:${PORT}" -t public > "$LOG" 2>&1 &
SERVER_PID=$!
cleanup() {
    kill "$SERVER_PID" 2> /dev/null || true
    php tests/browser/seed.php down || true
}
trap cleanup EXIT

for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:${PORT}/login" && break
    sleep 0.2
done

node tests/browser/smoke.mjs "http://127.0.0.1:${PORT}"
