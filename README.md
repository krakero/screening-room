# Screening Room

Personal movie/TV tracker combining TMDB metadata, Plex scrobbling, and Seerr/Sonarr/Radarr integrations — tracks what you've watched, what's coming up, and what you want to request.

## Requirements

- **macOS/Windows**: Docker Desktop
- **Linux**: Docker Engine + compose plugin (`docker-compose-plugin`)

## Install

```sh
mkdir screening-room && cd screening-room
curl -O https://raw.githubusercontent.com/krakero/screening-room/main/docker-compose.yml
docker compose up -d
```

Open **http://localhost:7331** — the `/setup` wizard walks through account creation and integrations (TMDB, Plex, Seerr, etc.). If you're moving an existing install, use `/setup/restore` instead to upload a backup.

## Change the port

Create a `.env` file next to `docker-compose.yml`:

```env
APP_PORT=8080
```

then `docker compose up -d` again. The app will be reachable at `http://localhost:8080`.

## Updates

**Settings → Updates** provides in-app update checking and one-click updates. The app runs two update channels:

- **Stable** (`latest` tag): versioned releases (v0.1.0, v0.2.0, etc.). First stable release is v0.1.0.
- **Develop** (`develop` tag): tracks the `main` branch with the latest features and fixes. Each push to `main` publishes a new `develop` image.

The **updater** service (included in `docker-compose.yml`) monitors for updates, pulls new images, and recreates the `app` container automatically when you click "Update now". Before migrating to a new version, the entrypoint creates a timestamped database dump at `/app/storage/app/backups/pre-update-*.sql.gz` (keeping the 5 newest). If migration fails, the updater rolls back to the previous image tag and the dump is available for manual restore.

### What the updater can access

The updater service has access to:
- The **Docker socket** (`/var/run/docker.sock`) to pull images and recreate the `app` container
- The **project directory** (your install folder mounted at `/project`) to read and write the `.env` file (specifically the `APP_IMAGE_TAG` variable)
- The **storage volume** to write status files and read pre-update dumps

It only manages containers in the `screening-room` compose project and never touches other projects or containers.

### Removing the updater (for manual updates)

To disable in-app updates and manage the stack manually, remove the `updater` service from `docker-compose.yml` and run `docker compose up -d` to apply the change. Then update manually with:

```sh
docker compose pull
docker compose up -d
```

### Rollback

If an update fails or causes issues:

1. The automatic rollback restores the previous image tag and leaves a pre-update database dump in **Settings → Backups**
2. If you need to restore an earlier database state, download the dump (named `pre-update-<version>-<timestamp>.sql.gz`) from **Settings → Backups** and restore it via **Settings → Backups** or `/setup/restore`
3. To roll back to a specific image version manually: set `APP_IMAGE_TAG=<version>` in `.env` (e.g., `APP_IMAGE_TAG=latest` or `APP_IMAGE_TAG=0.1.0`), then `docker compose up -d`

## Backups

**Settings → Backups** creates password-protected zip backups (full or config-only) in the `backups` volume. Copy one out with:

```sh
docker compose cp app:/app/storage/app/backups/screening-room-full-YYYY-MM-DD_HHMMSS.zip .
```

Restore one from **Settings → Backups**, or on a fresh install from `/setup/restore`.

or extract the whole volume:

```sh
docker run --rm -v screening-room_backups:/b -v $(pwd):/out alpine \
  tar czf /out/backups.tar.gz -C /b .
```

MySQL dumps: see [docs/deployment.md](docs/deployment.md#backups).

## HTTPS

Run behind a reverse proxy (Caddy, Traefik, nginx) that terminates TLS and forwards to `localhost:7331`. See [docs/deployment.md](docs/deployment.md#home-server--vps-setup) for home server and VPS setup.

## Developing

Local development with hot-reloading:

```sh
composer run dev
```

or test the Docker stack locally with the dev override: [docs/deployment.md](docs/deployment.md#testing-locally-next-to-herd).
