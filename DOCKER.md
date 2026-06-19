# Running mysymfql_manager with Docker

This guide explains how to build and run mysymfql_manager as a container, exposed on **port 29443**. The container only runs the PHP/Apache application; both the application database (`APP_DB_*`) and the MySQL servers you manage through the UI are expected to be **remote** — nothing related to MySQL is bundled in the image or in `docker-compose.yml`.

## Prerequisites

- Docker Engine with the Compose plugin (`docker compose version`)
- A reachable MySQL 8.4 server for the application database (see README.md, "Prerequisites — create the application database and user")
- Network access from the host running the container to that MySQL server (and to any MySQL server you intend to manage)

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
| `APP_DB_HOSTNAME` | Hostname/IP of the **remote** MySQL server hosting the application database |
| `APP_DB_PORT` | Usually `3306` |
| `APP_DB_NAME` | e.g. `mysymfql` |
| `APP_DB_USER` / `APP_DB_PASSWORD` | Credentials created per README.md |
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

This starts two containers:
- `app` — the Symfony application, Apache listening on `29443` (published to the host as `29443:29443`)
- `redis-server` — session/cache backend

Check that both are up:

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
http://<host>:29443
```

Log in with the admin account just created.

## 6. Register a managed MySQL server

From the UI (admin → "Server MySQL → Add"), or non-interactively from inside the container:

```bash
docker compose exec app php bin/console app:setup-sqlclient "Produzione" 192.168.1.10 manager 'StrongPassword123!' --port=3306
```

See README.md ("Adding a managed MySQL server") for how to create the corresponding MySQL account on the remote server.

## Data persistence

`docker-compose.yml` declares three named volumes:

| Volume | Mounted at | Contains |
|---|---|---|
| `app_var` | `/var/www/html/var` | Symfony cache/log, `var/share` |
| `app_backups` | `/var/backups/mysymfql` | Table/database backups produced by `mysqldump` (`BACKUP_PATH`) |
| `redis_data` | `/data` (in `redis-server`) | Redis persistence (sessions/cache) |

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

- **Login page loads but every action fails with a DB error** — check `APP_DB_HOSTNAME`/`APP_DB_PORT`/credentials in `docker/app.env`, and that the remote MySQL server's firewall/`bind-address` allows connections from the Docker host (see README.md, section 4 of "Adding a managed MySQL server").
- **"mysqldump command was not found"** — shouldn't happen with this image (it ships the official `mysql-client`); if you customized the Dockerfile, verify `mysqldump --version` inside the container: `docker compose exec app mysqldump --version`.
- **Sessions/login don't persist** — check that the `redis-server` container is healthy: `docker compose logs redis-server`.
- **Port 29443 not reachable** — confirm the container is listening on it: `docker compose exec app curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:29443/login` should return `200`.
