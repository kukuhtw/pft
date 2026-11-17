# Deploying with Dokploy

> Dokploy runs applications as Docker containers. The master prompt bans Docker unless explicitly requested;
> it was requested for Dokploy deployment. Docker is used **only** as the deployment runtime. The app stays a
> modular monolith (no microservices). For a bare-metal VPS without Docker, see [`ubuntu.md`](ubuntu.md).

## Architecture

```
Internet ──HTTPS──▶ Traefik (Dokploy, Let's Encrypt)
                       │  dokploy-network
                       ▼
                 web  (nginx + php-fpm, :8080, runs migrations on start)
                       │  internal network (private)
        ┌──────────────┼───────────────┐
     worker        scheduler         mysql 8.4  (volume mysql-data, never published)
 (queue:work)   (schedule:work →
                 ledger:verify 02:00, finance:prune 03:00 WIB)
```

All three app roles use one image (`Dockerfile`), and `app-storage` is shared between them.

| File | Purpose |
|---|---|
| `Dockerfile` | PHP 8.5-FPM Alpine + bcmath/intl/pdo_mysql/zip/pcntl, nginx, supervisord; production Composer deps |
| `docker-compose.yml` | Services web / worker / scheduler / mysql for the Dokploy **Compose** type |
| `docker/entrypoint.sh` | Waits for DB, `artisan optimize`, migrations (web), then starts the role |
| `docker/nginx.conf`, `php.ini`, `php-fpm.conf`, `supervisord.conf` | Runtime config |
| `docker/dokploy.env.example` | Environment variables to paste into Dokploy |
| `.dockerignore` | Keeps `.env`, SQLite files, `.git` and `vendor` out of the image |

## Prerequisites

1. A VPS with Dokploy installed (`curl -sSL https://dokploy.com/install.sh | sh`; review the script first). Use at least 2 GB RAM for MySQL 8.4.
2. The project pushed to a Git repository Dokploy can access (GitHub/GitLab/Gitea or a Git URL with a deploy key).
3. A DNS **A record** for your domain pointing to the VPS IP.

## Steps

1. **Project** → *Create Project* (e.g. `finance`) → *Create Service* → **Compose**.
2. **General** tab:
   - Provider: your Git repo and branch (e.g. `main`)
   - Compose Path: `./docker-compose.yml`
   - Compose type: **Docker Compose** (not Stack, which doesn't support `build:`)
3. **Environment** tab: paste [`docker/dokploy.env.example`](../../docker/dokploy.env.example) and replace every `<…>` value.
   - Generate `APP_KEY`, `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` freshly. Never reuse local or XAMPP credentials.
   - Dokploy writes these to `.env` beside the compose file. Each service loads it via `env_file: .env`.
   - **Required, or the deploy stops at once:** `MYSQL_ROOT_PASSWORD` and `DB_PASSWORD` (compose fails with "required variable … is missing a value"), plus `APP_KEY` (the web container exits).
   - Dashboard login: set `DASHBOARD_ADMIN_EMAIL` and `DASHBOARD_ADMIN_PASSWORD` (a bcrypt hash from `php artisan dashboard:user --hash`, inside single quotes). The web container syncs it on every start, so no shell is needed. Keep `AUTH_REGISTRATION_ENABLED=false`.
4. **Domains** tab → *Add Domain*:
   - Service: `web` · Host: `pft.example.com` · Container port: `8080` · Path `/`
   - HTTPS: **on**, certificate provider: **Let's Encrypt**
   - Dokploy injects the Traefik labels and attaches `dokploy-network` to `web` only. `worker`, `scheduler` and `mysql` stay on the private `internal` network. Use *Preview Compose* to check.
5. **Deploy**. On the first run, the web container waits for MySQL, migrates, runs `ledger:verify`, then starts serving. Worker and scheduler start once `web` is healthy.
6. Smoke test:
   ```bash
   curl -fsS https://pft.example.com/up
   ```
   Then sign in at `https://pft.example.com/login` with `DASHBOARD_ADMIN_EMAIL` and the password you hashed.
7. **Auto-deploy** (optional): enable the webhook in *General → Deployments* so pushes to the branch redeploy.

## Operations

| Task | How |
|---|---|
| Logs | Dokploy → Logs (per service). App logs go to stderr (`LOG_CHANNEL=stderr`) |
| Artisan | Dokploy → *Docker Terminal* on `web`: `su-exec www-data php artisan ledger:verify` |
| Backups | Dokploy → **Volume Backups** for `mysql-data` (named volumes only) to S3-compatible storage, daily, encrypted bucket. Also take logical dumps: `docker exec <mysql> mysqldump --single-transaction -uroot -p pft` |
| Restore test | Quarterly: restore into a scratch stack, then run `php artisan ledger:verify` |
| Migrations | Run automatically on each deploy (`RUN_MIGRATIONS=true`). For risky migrations set it to `false` and run them manually |
| Scaling | Run only **one** `scheduler`. `worker` may be scaled. `web` may be scaled because posting is lock-safe, and sessions/cache live in MySQL |

### Alternative: Dokploy-managed MySQL

To use Dokploy's own MySQL database service (built-in scheduled S3 backups):

1. Create a **MySQL 8.4** database in the same project.
2. Delete the `mysql` service and its `depends_on` entries from `docker-compose.yml`.
3. Set `DB_HOST` to the database's *internal host*, add `dokploy-network` to `web`, `worker` and `scheduler` so they can reach it, and drop `MYSQL_ROOT_PASSWORD` from the environment.

## Security notes (ISO/IEC 27001:2022)

- TLS terminates at Traefik (Let's Encrypt). The app container only listens on the internal network (`expose`, no `ports`).
- MySQL is never published to the host and sits only on the private `internal` network.
- Secrets live only in Dokploy's Environment. `.dockerignore` keeps any `.env` out of image layers. `config:cache` runs at container start, so secrets are never baked into the image.
- `TRUSTED_PROXIES` is limited to private Docker ranges, so client IPs used by rate limiting and audit logs can't be spoofed from the internet.
- `APP_DEBUG=false`, `expose_php=Off`, `server_tokens off`, and security headers in nginx. HSTS: enable it on the Traefik/Dokploy domain settings, or add `Strict-Transport-Security` in `docker/nginx.conf` once HTTPS is confirmed.
- Restrict Dokploy's own dashboard (port 3000) with a strong admin password and 2FA, or put it behind a domain with HTTPS and close port 3000 in the firewall.

## Not verified yet

- The image has **not been built or run locally** because Docker isn't installed on the development machine. The first Dokploy deploy is the first real build. Check the build log.
- The test suite has not yet run against MySQL 8.4. Run it before go-live (see README → *Running the MySQL/MariaDB integration tests*).
