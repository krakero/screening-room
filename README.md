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

## Update

```sh
docker compose pull
docker compose up -d
```

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
