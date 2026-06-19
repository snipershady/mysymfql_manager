# Running mysymfql_manager with Docker

This guide explains how to build and run mysymfql_manager as a container, exposed on **port 29443 over HTTPS only** (Apache terminates TLS directly; plain HTTP is not served, since Altcha and other WebCrypto-based widgets require a secure context). `docker-compose.yml` brings up the whole stack in one shot: the app, Redis, and a bundled MySQL 8.4 container for the **application's own database**. The MySQL servers you manage through the UI (the ones you add under "Server MySQL") are always **remote** — that part is never bundled.

## Prerequisites

- Docker Engine with the Compose plugin (`docker compose version`)
- Network access from the host running the container to any MySQL server you intend to manage through the UI

If you'd rather point the app at your own remote MySQL server instead of the bundled `db` container, remove the `db` service (and its `depends_on` entry under `app`) from `docker-compose.yml` and set `APP_DB_HOSTNAME` in `docker/app.env` to that remote host — see README.md, "Prerequisites — create the application database and user", for how to provision it.

## 1. Configure the environment file

Copy the example file and fill in real values:

```bash
cp docker/app.env.example docker/app.env
```

Edit `docker/app.env`:

| Variable | Description |
|---|---|
| `APP_SECRET` | Generate with `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"` |
| `SQLCLIENT_ENCRYPTION_KEY` | Generate with `php -r "echo sodium_bin2hex(sodium_crypto_secretbox_keygen()).PHP_EOL;"` |
| `ALTCHAKEY` | Generate with `php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"` |
| `APP_DB_HOSTNAME` | `db` (the bundled MySQL container) by default, or your remote host |
| `APP_DB_PORT` | Usually `3306` |
| `APP_DB_NAME` | e.g. `mysymfql` |
| `APP_DB_USER` / `APP_DB_PASSWORD` | Application's database credentials |
| `MYSQL_ROOT_PASSWORD` / `MYSQL_DATABASE` / `MYSQL_USER` / `MYSQL_PASSWORD` | Bootstrap credentials for the bundled `db` container — `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD` **must match** `APP_DB_NAME`/`APP_DB_USER`/`APP_DB_PASSWORD` above. Only read on the very first start of an empty `db_data` volume; unused if you removed the `db` service |
| `DEFAULT_URI` | Public URL of the app (used to build absolute links in emails/CLI) |
| `MAILER_DSN` / `EMAIL_FROM` | SMTP relay for password-reset emails (`null://null` disables sending) |

`docker/app.env` is gitignored — never commit real secrets.

> Redis does not need any configuration here: `docker-compose.yml` already starts a `redis-server` container matching the hostname the application expects by default.

## 2. Build the image

```bash
docker compose build
```

This installs Composer dependencies, compiles the Asset Mapper assets, and installs the official Oracle `mysql-client` (so `mysqldump`/`mysql` are available in `PATH`).

## 3. Start the stack

```bash
docker compose up -d
```

This starts three containers:
- `app` — the Symfony application, Apache listening on `29443` (published to the host as `29443:29443`)
- `redis-server` — session/cache backend
- `db` — MySQL 8.4 for the application's own database (waits to be healthy before `app` starts)

Check that all three are up:

```bash
docker compose ps
```

## 4. First-time setup (schema + first admin user)

The application database schema and the first administrator account are created interactively, exactly as described in README.md, but run **inside** the container:

```bash
docker compose exec app php bin/console app:setup
```

Since `docker/app.env` already provides `APP_DB_*`, `APP_SECRET`, `SQLCLIENT_ENCRYPTION_KEY`, `ALTCHAKEY` and `BACKUP_PATH` as real environment variables, this command goes straight to generating the schema migration and creating the first admin: it will only ask for **Username**, **Email** and **Password**.

## 5. Access the application

Open:

```
https://<host>:29443
```

Log in with the admin account just created.

## TLS certificate

The app is served over HTTPS only. On first start, if no certificate is found in `/etc/apache2/ssl`, the entrypoint generates a **self-signed** one automatically (`docker/docker-entrypoint.sh`) and stores it in the `app_ssl_certs` named volume, so it persists across restarts. Browsers will show a trust warning until you accept/import it — fine for internal/testing use.

For a real certificate (e.g. Let's Encrypt), provide your own `cert.pem`/`key.pem` instead:

```bash
# stop the app first, then copy your files into the named volume
docker compose cp /path/to/fullchain.pem app:/etc/apache2/ssl/cert.pem
docker compose cp /path/to/privkey.pem app:/etc/apache2/ssl/key.pem
docker compose restart app
```

Or bind-mount a host directory instead of the named volume in `docker-compose.yml`:

```yaml
    volumes:
      - ./certs:/etc/apache2/ssl
```

and place `cert.pem`/`key.pem` in `./certs` before starting the stack.

If you only need to change the `CN` of the auto-generated self-signed certificate (e.g. to match the hostname clients use), set `SSL_CERT_CN` in `docker/app.env` and remove the existing certificate so it gets regenerated:

```bash
docker compose exec app rm /etc/apache2/ssl/cert.pem /etc/apache2/ssl/key.pem
docker compose restart app
```

## 6. Register a managed MySQL server

From the UI (admin → "Server MySQL → Add"), or non-interactively from inside the container:

```bash
docker compose exec app php bin/console app:setup-sqlclient "Produzione" 192.168.1.10 manager 'StrongPassword123!' --port=3306
```

See README.md ("Adding a managed MySQL server") for how to create the corresponding MySQL account on the remote server.

## Data persistence

`docker-compose.yml` declares five named volumes:

| Volume | Mounted at | Contains |
|---|---|---|
| `app_var` | `/var/www/html/var` | Symfony cache/log, `var/share` |
| `app_backups` | `/var/backups/mysymfql` | Table/database backups produced by `mysqldump` (`BACKUP_PATH`) |
| `app_ssl_certs` | `/etc/apache2/ssl` | TLS certificate (auto-generated self-signed, or your own — see "TLS certificate" above) |
| `redis_data` | `/data` (in `redis-server`) | Redis persistence (sessions/cache) |
| `db_data` | `/var/lib/mysql` (in `db`) | The application's MySQL data directory |

> The schema migration file generated by `app:setup`/`doctrine:migrations:diff` is written to `migrations/` inside the `app` container, which is **not** a volume — it lives only in that container's writable layer. This mirrors the bare-metal flow in README.md and is fine for a single deployment, but if you recreate the `app` container without recreating `db`, re-running `app:setup` is harmless (it no-ops once `app_user` has rows). If you care about tracking schema history in git, copy the generated file out with `docker compose cp app:/var/www/html/migrations/. migrations/` after the first setup.

These survive `docker compose down`; they are only removed with `docker compose down -v`.

## Scheduled commands (cron-equivalent)

The CLI commands documented in README.md (`app:backup-all`, `app:backup-single`, `app:process-backup-queue`) still work the same way inside the container. Run them from the host's crontab using `docker compose exec`, e.g.:

```cron
* * * * * cd /path/to/mysymfql_manager && docker compose exec -T app php bin/console app:process-backup-queue >> /var/log/mysymfql_queue.log 2>&1
```

## Updating to a new version

```bash
git pull
docker compose build
docker compose up -d
```

The entrypoint automatically clears/warms the Symfony cache and applies any new Doctrine migration on every container start, so no manual step is needed for routine updates (only the very first deployment requires the interactive `app:setup` from step 4).

## Stopping / removing

```bash
docker compose down        # stop, keep volumes (data preserved)
docker compose down -v     # stop and remove volumes (data lost)
```

## Troubleshooting

- **Login page loads but every action fails with a DB error** — if using the bundled `db` service, check `docker compose logs db` and that `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD` in `docker/app.env` match `APP_DB_NAME`/`APP_DB_USER`/`APP_DB_PASSWORD`; if pointing at a remote server instead, check `APP_DB_HOSTNAME`/`APP_DB_PORT`/credentials and that the remote MySQL server's firewall/`bind-address` allows connections from the Docker host (see README.md, section 4 of "Adding a managed MySQL server").
- **Changed `MYSQL_*` credentials in `docker/app.env` had no effect** — the official MySQL image only applies `MYSQL_DATABASE`/`MYSQL_USER`/`MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD` the very first time it initializes an empty `db_data` volume. To apply new credentials you must reset it: `docker compose down`, `docker volume rm mysymfql_manager_db_data`, `docker compose up -d` (this destroys all application data in the bundled database).
- **"mysqldump command was not found"** — shouldn't happen with this image (it ships the official `mysql-client`); if you customized the Dockerfile, verify `mysqldump --version` inside the container: `docker compose exec app mysqldump --version`.
- **Sessions/login don't persist** — check that the `redis-server` container is healthy: `docker compose logs redis-server`.
- **Port 29443 not reachable** — confirm the container is listening on it: `docker compose exec app curl -sk -o /dev/null -w '%{http_code}\n' https://127.0.0.1:29443/login` should return `200`. Note plain `http://` on this port always fails (no TLS handshake) — this is expected.
- **Altcha widget doesn't load / browser blocks WebCrypto** — make sure you are accessing the app via `https://`, not `http://`, and that the browser trusts the certificate (accept the warning for the self-signed one, or install a real certificate as described above).
