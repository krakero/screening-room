# Screening Room — Product Spec

A personal, single-user tracker for movies and TV. It stores TMDB metadata locally, scrobbles plays from Plex, and connects to Seerr/Sonarr/Radarr so every title shows where it stands: **not in library → requested → downloading → available → watched**.

## Decisions

| Area | Decision |
|---|---|
| Stack | Laravel 13, Livewire 4, Flux UI Pro 2, Pest |
| Users | Single user. Auth is still required because webhook endpoints are public |
| Hosting | Docker Compose, portable across a home server, VPS, or Laravel Cloud later |
| Database | MySQL everywhere (local via DBngin, tests use `screening_room_testing`, MySQL container in Docker/production) |
| Queues | Redis + Horizon. All syncs and webhooks are processed as queued jobs |
| TMDB data | Full metadata stored locally (titles, seasons, episodes, people, credits). Images are **not** cached; store TMDB image paths and render via TMDB's CDN |
| TV granularity | Episode-level |
| Ratings | Thumbs up / thumbs down (nullable = unrated) |
| Plex | Scrobble only (Plex → app). Webhooks (Plex Pass) + scheduled polling as a backstop |
| History import | Trakt, as a one-time migration |
| Requests | Via Seerr. The app never talks to Sonarr/Radarr to add content |
| Library status | Sonarr/Radarr webhooks + Seerr request status |
| Notifications | In-app + Pushover |
| UI design | Cinematic amber (Plex-leaning), blended with Seerr-style status badges and Trakt-style progress. Top nav, bottom tab bar on phones, dark by default with a light option |
| Home screen | "Up Next": the next unwatched episode for each active in-progress show |
| Stale shows | Shows with no plays for 6 months are hidden from Up Next and marked **Abandoned**, which is visible on the title and filterable |
| Specials | Season 0 is tracked but never counts toward progress, Up Next, or completion |

## Data model (first draft)

- **titles**: `type` (movie|show), `tmdb_id` (unique per type), `imdb_id`, `tvdb_id`, name, overview, release/first-air date, status, runtime, poster/backdrop paths, genres, `tmdb_synced_at`
- **seasons**: `title_id`, season number, name, overview, air date, poster path
- **episodes**: `season_id`, `title_id`, episode number, name, overview, air date, runtime, still path, `tmdb_id`, `tvdb_id`
- **people**, **credits**: cast/crew, attached polymorphically to titles and episodes
- **plays**: one row per viewing. Polymorphic `playable` (title for movies, episode for TV), `watched_at`, `source` (plex|trakt|manual), `external_id` (for dedupe). Rewatches are just additional rows
- **ratings**: polymorphic, `thumb` (up|down), `note` (text)
- **lists** / **list_items**: custom lists; a built-in "Watchlist" list is seeded
- **follows**: shows tracked for Up Next and the upcoming calendar. `state` (watching|abandoned|completed|paused) plus `state_changed_at`. `abandoned` is set automatically; `paused` is manual and exempt from auto-abandon
- **library_statuses**: per title, `state` (requested|pending|downloading|available), `seerr_request_id`, `sonarr_id` / `radarr_id`, updated from webhooks
- **collection_items**: physical and digital copies owned. `title_id`, optional `season_id`, `format` (enum: uhd_4k|bluray|dvd|digital), `edition`, `retailer`, `barcode`, `acquired_at`, `price`, `currency`, `location`, `loaned_to`, `loaned_at`, `notes`. Plex ownership is derived from `plex_library_items`, not stored
- **webhook_events**: raw payload log for Plex/Sonarr/Radarr/Seerr so events can be debugged and replayed

## Integrations

### TMDB
- Search runs live against TMDB; adding a title imports it fully (for shows, that includes seasons, episodes, and credits).
- A scheduled refresh prioritizes followed shows that are still airing (daily), then everything else (weekly), using TMDB's `/changes` endpoints to skip titles that haven't changed.

### Plex (scrobbling)
- `POST /webhooks/plex/{secret}` accepts multipart payloads. Only `media.scrobble` creates a play; other events are logged and ignored.
- Titles are matched using the Guid array (`tmdb://`, `tvdb://`, `imdb://`). When a matched title isn't stored locally yet, it's imported from TMDB automatically.
- Polling job (every 15 min): reads recent watch history from the Plex server and dedupes against existing plays by Plex `ratingKey` + `viewedAt`.
- Only plays by the configured Plex account are recorded.

### Trakt (one-time import)
- User uploads the .zip export Trakt emails from Settings → Data → Export on trakt.tv. The import then pulls history (plays with timestamps), ratings, watchlist, and custom lists.
- Ratings map to thumbs: 6–10 → up, 1–5 → down.

### Seerr
- A "Request" button on titles not in the library creates a Seerr request (movie, or show with chosen seasons).
- Seerr webhooks keep the request state current.

### Sonarr / Radarr
- Read-only. Webhooks (`Grab`, `Download`, `Delete`) update `library_statuses`; a periodic reconcile job catches missed events.

### Notifications
- Upcoming episodes and releases for followed titles: in-app (dashboard + calendar) and Pushover.
- Also sent when a requested title becomes available.

## Screens

1. **Up Next** (home): next unwatched episode per in-progress show, plus a strip of what's airing soon, plus a strip of titles recently added to the Watchlist
2. **Search**: TMDB search with library/watch state badges on every result
3. **Title detail**: metadata, seasons/episodes with watched toggles, play history, thumb + note, list membership, library status, and a Request button
4. **Calendar** (phase 4; a 'Coming soon' placeholder until then): episodes of followed shows (not abandoned), Watchlist movie release dates, season premieres of Watchlist shows you haven't started, plus the past 7 days of aired-but-unwatched episodes so you can catch up. Layout TBD at build time (agenda vs month grid)
5. **History**: chronological play log
6. **Lists**: watchlist and custom lists
7. **Settings**: Features: Collection, Trakt import; Integrations: TMDB key, Plex server/token/account, Seerr, Sonarr, Radarr, Pushover, qBittorrent, MDBList

## Status (2026-09-30)

Phases 1-6 and 8 are built, tested (1694 tests), and on `main`: foundation, Up Next, Plex scrobbling (webhook + polling), Trakt export import, calendar + Pushover, Seerr requests + Sonarr/Radarr status, Settings > Integrations, Docker Compose (builds fine; stack is app + MySQL, Redis optional), and Collection (physical/digital copy tracking with CSV import). Mobile (phase 7) lives in the separate `screening-room-mobile` repo and talks to this app over the v1 API.

## Phases

1. **Foundation**: data model, TMDB client + import + refresh, search, title detail, manual play logging, thumbs/notes, lists
2. **Plex scrobbling**: webhook receiver, matching, polling backstop
3. **Trakt import**
4. **Upcoming**: follows, calendar, Up Next, Pushover
5. **\*arr integration**: Seerr requests, Sonarr/Radarr status
6. **Deployment**: Docker Compose (app + MySQL, Redis optional)
7. **Mobile**: NativePHP Mobile app in the separate `screening-room-mobile` repo, consuming this app's Sanctum v1 API. Main features: request on the go, watch progress, upcoming, search. Keep domain logic in `app/Services` and `app/Actions` so the API layer stays thin
8. **Collection**: physical and digital copy tracking. Grid page, title/season sections, owned badges on search/Up Next/lists, request confirmation when owned, per-user toggle, CSV import with TMDB matching, stats panel

## Show progress rules

- **Progress** = watched regular episodes ÷ aired regular episodes. Season 0 is excluded from both sides.
- **Next episode** = the lowest unwatched, already-aired episode outside season 0.
- **Completed**: every aired regular episode is watched and the show has ended. A returning series with everything watched stays `watching` but drops off Up Next until a new episode airs.
- **Abandoned**: a scheduled job moves `watching` shows with no play in 6 months (configurable) to `abandoned`. A new play (manual, Plex, or Trakt) automatically moves it back to `watching`, as does the **Resume** button.
- **Paused**: set manually ("I'll get back to it"). It is hidden from Up Next like an abandoned show but never auto-changes.

## Collection (Phase 8)

A per-user physical + digital collection tracker that shows what formats you own for each title.

**CSV Import**:
- Upload a CSV file to bulk-import collection items
- The importer matches each row to a Title via TMDB search (exact title+year first, otherwise best search hit)
- Titles not yet in the local database are imported automatically
- Exact duplicates (same title/season/format/edition/barcode) are skipped
- Unmatched rows and parse errors are reported after import completes

**CSV Format** (see `docs/collection-import-sample.csv` for an example):

| Column | Type | Required | Description |
|--------|------|----------|-------------|
| `title` | string | Yes | Title name (matches against TMDB) |
| `year` | integer | No | Release year for better matching |
| `type` | string | No | `movie` (default) or `show` |
| `season` | integer | No | Season number for TV shows |
| `format` | string | Yes | `4K UHD`, `Blu-ray`, `DVD`, or `Digital` (case-insensitive; also accepts `4K`, `UHD`, `BD`, `Blu Ray`) |
| `edition` | string | No | Edition name (e.g., "Collector's Edition") |
| `retailer` | string | No | Where purchased |
| `barcode` | string | No | UPC/EAN barcode |
| `acquired_at` | date | No | Purchase date (any format strtotime accepts) |
| `price` | float | No | Purchase price |
| `currency` | string | No | 3-letter currency code (e.g., USD) |
| `location` | string | No | Physical location |
| `notes` | text | No | Free-form notes |

## Open questions

- The scheduled refresh (`tmdb:refresh`) currently re-imports every stale title in full rather than using TMDB's `/changes` endpoint to skip titles that haven't actually changed. That optimization is deferred; revisit once refresh volume makes the extra TMDB calls worth avoiding.
