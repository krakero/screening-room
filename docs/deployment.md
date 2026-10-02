# Deployment (Docker Compose)

Screening Room ships as a single Docker image (FrankenPHP + PHP 8.4) published
to `ghcr.io/krakero/screening-room`. The default stack is two containers —
`app` and `mysql` — with Redis available as an optional compose profile. The
same image works on a home server or a VPS; nothing in the stack is
platform-specific.

## Files

- `docker-compose.yml` — end-user file at repo root; pulls
  `ghcr.io/krakero/screening-room:latest`, no build step required.
- `docker-compose.dev.yml` — local development override; adds a `build:`
  block for testing image changes.
- `Dockerfile` — multi-stage build: Node (Vite assets) → Composer (PHP deps)
  → FrankenPHP runtime.
- `docker/supervisord.conf` — runs FrankenPHP, queue worker, and scheduler
  under one supervisor in the `app` container.
- `docker/Caddyfile` — FrankenPHP's web server config (serves `public/`).
- `docker/php.ini` — production PHP/OPcache overrides.
- `docker/entrypoint.sh` — zero-config secrets (generates `APP_KEY` if
  missing), runs migrations + cache warmup, then execs supervisord.

## Building the image

Optional — the published image at `ghcr.io/krakero/screening-room:latest` is
built automatically on every push to `main`. To build locally:

```sh
docker compose -f docker-compose.yml -f docker-compose.dev.yml build
```

## Optional `.env`

Create a `.env` file next to `docker-compose.yml` to override defaults. The
compose file ships working defaults for all required variables, so a `.env` is
optional. Common overrides:

| Key | Default | Notes |
| --- | --- | --- |
| `APP_PORT` | `7331` | Host port the `app` service binds |
| `APP_URL` | `http://localhost:7331` | Public URL (used for webhook URLs, links) |
| `APP_KEY` | *(auto-generated)* | Generated on first boot and saved to `/app/storage/app/app.key` if missing |
| `DB_DATABASE` | `screening_room` | |
| `DB_USERNAME` | `screening_room` | |
| `DB_PASSWORD` | `change-me-screening-room` | App DB user password |
| `DB_ROOT_PASSWORD` | `change-me-root` | MySQL root password (container bootstrap only) |
| `QUEUE_CONNECTION` | `database` | Use `redis` with the redis profile |
| `CACHE_STORE` | `database` | Use `redis` with the redis profile |
| `SESSION_DRIVER` | `database` | Use `redis` with the redis profile |
| `TMDB_TOKEN` | *(none)* | TMDB v4 read access token — a bootstrap fallback only; see below |

Everything else (TMDB, Plex, Seerr, Sonarr, Radarr, Pushover credentials)
is configured at runtime from a **first-run setup wizard** at `/setup`
(shown automatically until an owner account exists), and can be changed
afterwards from **Settings → Integrations**. It's stored encrypted in the
database via `App\Support\IntegrationSettings`, not in `.env`.

`TMDB_TOKEN` is the one exception worth setting in `.env` for a Docker
deploy: since TMDB is required before the rest of the app is usable, setting
it ahead of time means the wizard's TMDB step arrives already configured.
It's still just a fallback — a token saved through the wizard or Settings
always takes priority over the environment variable, and either alone is
enough.

### Redis profile

By default, queues, cache, and sessions use the `database` driver. To add
Redis, start the stack with the `redis` profile:

```sh
docker compose --profile redis up -d
```

and set `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, and
`SESSION_DRIVER=redis` in your `.env`.

## Running it

```sh
docker compose up -d
docker compose ps        # wait for mysql to report healthy
docker compose logs -f app
```

The `app` container's entrypoint runs `php artisan migrate --force` and
rebuilds the config/route/view/event caches, then starts supervisord. The
supervisor manages three processes: FrankenPHP (web server), `php artisan
queue:work --tries=3` (queue worker), and `php artisan schedule:work`
(scheduler).

Health checks:
- `app`: `GET /up` (Laravel's built-in health route).
- `mysql`: `mysqladmin ping`.
- `redis` (if using the profile): `redis-cli ping`.

## Queue worker

A Trakt export import (Settings → Features → Trakt import) runs entirely on
the queue as a `Bus::batch()` pipeline of short jobs (title imports, then
play pages, ratings, watchlist, and custom lists), specifically so no single
job runs long enough to hit a queue worker's timeout. That means **a queue
worker must be running** for an import to make any progress — in Docker that
queue worker runs under supervisord inside the `app` container; locally, run
`composer run dev` (which starts `queue:listen` alongside the dev server) or
`php artisan queue:work` directly. The Trakt import page polls and shows
progress while a batch is running; with no worker running it'll just sit at
"running" forever.

## Testing locally next to Herd

To smoke-test the Docker stack on a dev machine that's also running the app
under Herd/DBngin, use the dev override and a separate env file so the local
`.env` (and its DBngin database) are never touched:

- Create `docker/local.env` (gitignored) with overrides: `APP_PORT=8080`,
  `APP_URL=http://localhost:8080`, and optionally your own `DB_PASSWORD` /
  `DB_ROOT_PASSWORD`. The compose file's defaults handle everything else.
  `TMDB_TOKEN` can be left out — the `/setup` wizard asks for it. Set
  `ENV_FILE=docker/local.env` so `docker-compose.yml`'s `env_file:
  ${ENV_FILE:-.env}` picks it up.
- Run everything with `docker compose -f docker-compose.yml -f
  docker-compose.dev.yml --env-file docker/local.env <command>` (`build`, `up
  -d`, `ps`, `logs`, `exec`, `down`). The app is then reachable at
  `http://localhost:8080` without interfering with the Herd-served copy.

## Home server + VPS setup

Same compose file either way — the difference is networking and TLS:

- **Home server**: run behind your existing reverse proxy (e.g. Caddy,
  Traefik, or nginx already on the box) that terminates TLS and forwards to
  `app`'s published port (`APP_PORT`, default `7331`). Point your router/DNS
  or a tunnel (e.g. Cloudflare Tunnel, Tailscale) at that proxy — don't
  expose the `app` port directly to the internet.
- **VPS**: same image, either behind a proxy the same way, or let
  FrankenPHP terminate TLS itself by setting `SERVER_NAME` to your domain
  (`SERVER_NAME=screening-room.example.com`) in `.env` — FrankenPHP/Caddy
  will provision a Let's Encrypt certificate automatically. In that case
  publish ports `80` and `443` instead of the internal `7331` default.

Either way, set `APP_URL` to the externally-reachable HTTPS URL — it's used
to build the Plex webhook URL below and any absolute links.

## Plex webhook URL

Plex Pass sends scrobble events to:

```
{APP_URL}/webhooks/plex/{secret}
```

where `{secret}` is the value stored at `plex.webhook_secret` in
**Settings → Integrations** (generated there, not in `.env`). Add that full
URL under Plex's **Settings → Webhooks**. The endpoint is CSRF-exempt and
authenticates purely via the secret path segment (constant-time compare,
404 on mismatch), so it must stay behind HTTPS.

## Updates

**Settings → Updates** provides in-app update checking and one-click updates with automatic rollback on failure.

### Update channels

The app supports two update channels:

- **Stable** (`latest` tag): versioned releases (v0.1.0, v0.2.0, etc.). The first stable release is v0.1.0. Use this for production.
- **Develop** (`develop` tag): tracks the `main` branch and is published on every push. Use this to test upcoming features or fixes before they're released.

You can switch channels from **Settings → Updates**. Switching from Develop to Stable may require restoring a pre-update database dump if the develop branch introduced schema changes not yet in stable.

### The updater service

The `docker-compose.yml` file includes an **updater** service that monitors for new images and orchestrates updates:

```yaml
updater:
  image: ghcr.io/krakero/screening-room:${APP_IMAGE_TAG:-latest}
  command: ["php", "artisan", "updater:run"]
  restart: unless-stopped
  volumes:
    - storage:/app/storage
    - /var/run/docker.sock:/var/run/docker.sock
    - .:/project
  environment:
    UPDATER_PROJECT_DIR: /project
    UPDATER_COMPOSE_PROJECT: screening-room
    UPDATER_APP_SERVICE: app
  # The updater has access to:
  # - Docker socket: to pull images and recreate the app container
  # - Project directory (.): to update APP_IMAGE_TAG in .env
  # - Storage volume: to write status files and read pre-update dumps
  # It only manages this compose project's containers.
  #
  # To disable in-app updates, delete this service and run:
  #   docker compose up -d
  # Then manage updates manually with:
  #   docker compose pull && docker compose up -d
```

When you click "Update now" in **Settings → Updates**, the web UI writes a request file to the storage volume. The updater service picks it up, pulls the new image, updates `APP_IMAGE_TAG` in your `.env` file, and recreates the `app` container. It polls the new container's health check for up to 5 minutes; if the container becomes unhealthy or fails to start, the updater restores the previous `APP_IMAGE_TAG` and recreates the container again (automatic rollback).

### Pre-update database dumps

Before running `php artisan migrate` on a new version, the entrypoint checks if the version has changed. If it has, it creates a timestamped MySQL dump at `/app/storage/app/backups/pre-update-<version>-<timestamp>.sql.gz` (keeping the 5 newest). If migration fails, the entrypoint exits with an error, the container becomes unhealthy, and the updater rolls back to the previous image. The pre-update dump is then available for manual restore via **Settings → Backups** or `/setup/restore`.

### Manual updates (without the updater service)

To manage updates manually, remove the `updater` service from `docker-compose.yml` and run `docker compose up -d`. Then update with:

```sh
docker compose pull
docker compose up -d
```

Set `APP_IMAGE_TAG` in `.env` to pin a specific version or switch channels:

```env
APP_IMAGE_TAG=latest    # stable channel
APP_IMAGE_TAG=develop   # develop channel
APP_IMAGE_TAG=0.2.1     # pin a specific version
```

### Rollback

If an update causes issues:

1. The automatic rollback (triggered by health check failure) restores the previous image tag
2. Download the pre-update dump from **Settings → Backups** (named `pre-update-<version>-<timestamp>.sql.gz`) and restore it via **Settings → Backups** or `/setup/restore`
3. To roll back to a specific version manually: set `APP_IMAGE_TAG=<version>` in `.env`, then `docker compose up -d`

## Backups

**Settings → Backups** creates timestamped database dumps in the `backups`
named volume (mounted at `/app/storage/app/backups` in the `app` container).
Copy one out with:

```sh
docker compose cp app:/app/storage/app/backups/screening-room-YYYY-MM-DD.sql.gz .
```

or extract the whole volume:

```sh
docker run --rm -v screening-room_backups:/b -v $(pwd):/out alpine \
  tar czf /out/backups.tar.gz -C /b .
```

For scheduled MySQL dumps from the host (or a cron container):

```sh
docker compose exec -T mysql \
  mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction "$DB_DATABASE" \
  | gzip > backups/screening-room-$(date +%F).sql.gz
```

Restore with:

```sh
gunzip -c backups/screening-room-YYYY-MM-DD.sql.gz \
  | docker compose exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"
```

### Backup restore and Trakt import file sizes

The app supports uploading backup files (Settings → Backups) and Trakt
exports (Settings → Features → Trakt import) up to **512 MB**. This limit is
configured in three places:

1. **Livewire** (`config/livewire.php`): global temporary upload max of 512 MB
2. **PHP** (`docker/php.ini`): `upload_max_filesize = 512M` and
   `post_max_size = 512M`
3. **Component validation**: backup restore and Trakt import both validate
   `max:524288` (512 MB in KB)

The restore operation's `max_execution_time` is set to 300 seconds (5 minutes)
in `docker/php.ini` to accommodate large backups. If you're running the app
outside Docker (local dev with Herd/Valet), ensure your PHP config allows
uploads ≥ 512 MB and sufficient execution time for restore operations.

## Release process

New versions are released by tagging `main` with a semver tag (e.g., `v0.1.0`). The tag triggers a GitHub Actions workflow that builds and publishes the image with both the version tag and the `latest` tag, and creates a GitHub Release.

To create a new release:

```sh
scripts/release.sh 0.1.0
```

The script asserts a clean working tree, creates an annotated tag `v0.1.0`, and pushes it to the remote. The CI workflow then builds the image with `APP_VERSION=0.1.0`, `APP_CHANNEL=stable`, and publishes it as:

- `ghcr.io/krakero/screening-room:latest`
- `ghcr.io/krakero/screening-room:0`
- `ghcr.io/krakero/screening-room:0.1`
- `ghcr.io/krakero/screening-room:0.1.0`

The workflow also creates a GitHub Release with auto-generated notes from the commits since the previous tag.
