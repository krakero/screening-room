<?php

namespace App\Actions\Plex;

use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Actions\Tmdb\ImportTitle;
use App\Enums\PlaySource;
use App\Enums\TitleType;
use App\Models\Episode;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use App\Support\IntegrationSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RecordScrobble
{
    public function __construct(
        private readonly IntegrationSettings $settings,
        private readonly ImportTitle $importTitle,
        private readonly TmdbClient $tmdb,
        private readonly EnsureSeasonEpisodes $ensureSeasonEpisodes,
    ) {}

    /**
     * Record a play from a normalized Plex scrobble event.
     *
     * @param  array{account_id: string|int|null, rating_key: string, viewed_at: Carbon, type: ?string, title: string, grandparent_title: ?string, season_number: ?int, episode_number: ?int, guids: array<string, string>, show_guids?: array<string, string>}  $event
     */
    public function handle(array $event): ?Play
    {
        return $this->attempt($event)['play'];
    }

    /**
     * Same as {@see handle()}, but reports WHY nothing was recorded (used for `plex:poll`
     * summaries) and, with `persist: false`, resolves the match without writing a `Play`.
     *
     * `guids` are the item's OWN external ids (the movie's, or the episode's own tmdb/tvdb id).
     * `show_guids` are the SHOW's external ids when the caller already has them (legacy tvdb
     * agent, or a token-backed lookup) — when present they skip the TMDB `/find` round-trip.
     *
     * @param  array{account_id: string|int|null, account_username?: ?string, rating_key: string, viewed_at: Carbon, type: ?string, title: string, grandparent_title: ?string, season_number: ?int, episode_number: ?int, guids: array<string, string>, show_guids?: array<string, string>}  $event
     * @return array{outcome: 'ignored_account'|'duplicate'|'unmatched'|'recorded', play: ?Play}
     */
    public function attempt(array $event, bool $persist = true): array
    {
        if (! $this->isFromConfiguredAccount($event['account_id'] ?? null, $event['account_username'] ?? null)) {
            return ['outcome' => 'ignored_account', 'play' => null];
        }

        $externalId = "{$event['rating_key']}:{$event['viewed_at']->getTimestamp()}";

        if (Play::query()->where('source', PlaySource::Plex)->where('external_id', $externalId)->exists()) {
            return ['outcome' => 'duplicate', 'play' => null];
        }

        $playable = match ($event['type']) {
            'movie' => $this->resolveMovie($event['guids'] ?? []),
            'episode' => $this->resolveEpisode(
                $event['guids'] ?? [],
                $event['show_guids'] ?? [],
                $event['season_number'] ?? null,
                $event['episode_number'] ?? null,
                $event['grandparent_title'] ?? null,
            ),
            default => null,
        };

        if ($playable === null) {
            return ['outcome' => 'unmatched', 'play' => null];
        }

        if (! $persist) {
            return ['outcome' => 'recorded', 'play' => null];
        }

        $play = $playable->plays()->create([
            'watched_at' => $event['viewed_at'],
            'source' => PlaySource::Plex,
            'external_id' => $externalId,
        ]);

        return ['outcome' => 'recorded', 'play' => $play];
    }

    /**
     * @param  array<string, string>  $guids
     */
    private function resolveMovie(array $guids): ?Title
    {
        if ($guids === []) {
            return null;
        }

        $title = $this->findLocalTitle(TitleType::Movie, $guids);

        if ($title !== null) {
            return $title;
        }

        if (isset($guids['tmdb'])) {
            return $this->importTitle->handle(TitleType::Movie, (int) $guids['tmdb']);
        }

        if (isset($guids['imdb'])) {
            $tmdbId = $this->movieTmdbIdFromImdb($guids['imdb']);

            if ($tmdbId !== null) {
                return $this->importTitle->handle(TitleType::Movie, $tmdbId);
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $guids
     * @param  array<string, string>  $showGuids
     */
    private function resolveEpisode(array $guids, array $showGuids, ?int $seasonNumber, ?int $episodeNumber, ?string $grandparentTitle): ?Episode
    {
        if ($seasonNumber === null || $episodeNumber === null) {
            return null;
        }

        if (isset($guids['tmdb'])) {
            $episode = Episode::query()->where('tmdb_id', $guids['tmdb'])->first();

            if ($episode !== null) {
                return $episode;
            }
        }

        if (isset($guids['tvdb'])) {
            $episode = Episode::query()->where('tvdb_id', $guids['tvdb'])->first();

            if ($episode !== null) {
                return $episode;
            }
        }

        $show = $this->resolveShowForEpisode($guids, $showGuids, $grandparentTitle);

        if ($show === null) {
            return null;
        }

        $season = Season::query()->where('title_id', $show->id)->where('season_number', $seasonNumber)->first();

        if ($season !== null) {
            $this->ensureSeasonEpisodes->handle($season);
        }

        return Episode::query()
            ->where('title_id', $show->id)
            ->where('season_number', $seasonNumber)
            ->where('episode_number', $episodeNumber)
            ->first();
    }

    /**
     * @param  array<string, string>  $guids
     * @param  array<string, string>  $showGuids
     */
    private function resolveShowForEpisode(array $guids, array $showGuids, ?string $grandparentTitle): ?Title
    {
        if ($showGuids !== []) {
            $show = $this->findLocalTitle(TitleType::Show, $showGuids);

            if ($show !== null) {
                return $show;
            }

            $tmdbId = $this->showTmdbIdFromShowGuids($showGuids);

            if ($tmdbId !== null) {
                return $this->importTitle->handle(TitleType::Show, $tmdbId);
            }
        }

        if (isset($guids['tvdb'])) {
            $tmdbId = $this->showTmdbIdFromEpisodeExternalId((string) $guids['tvdb'], 'tvdb_id');

            if ($tmdbId !== null) {
                return $this->importTitle->handle(TitleType::Show, $tmdbId);
            }
        }

        if (isset($guids['imdb'])) {
            $tmdbId = $this->showTmdbIdFromEpisodeExternalId((string) $guids['imdb'], 'imdb_id');

            if ($tmdbId !== null) {
                return $this->importTitle->handle(TitleType::Show, $tmdbId);
            }
        }

        if ($grandparentTitle !== null) {
            return Title::query()
                ->where('type', TitleType::Show)
                ->where('name', $grandparentTitle)
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, string>  $guids
     */
    private function findLocalTitle(TitleType $type, array $guids): ?Title
    {
        return Title::query()
            ->where('type', $type)
            ->where(function (Builder $query) use ($guids): void {
                if (isset($guids['tmdb'])) {
                    $query->orWhere('tmdb_id', $guids['tmdb']);
                }

                if (isset($guids['tvdb'])) {
                    $query->orWhere('tvdb_id', $guids['tvdb']);
                }

                if (isset($guids['imdb'])) {
                    $query->orWhere('imdb_id', $guids['imdb']);
                }
            })
            ->first();
    }

    private function movieTmdbIdFromImdb(string $imdbId): ?int
    {
        $data = $this->tmdb->findByExternalId($imdbId, 'imdb_id');

        $id = $data['movie_results'][0]['id'] ?? null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  array<string, string>  $showGuids
     */
    private function showTmdbIdFromShowGuids(array $showGuids): ?int
    {
        if (isset($showGuids['tmdb'])) {
            return (int) $showGuids['tmdb'];
        }

        if (isset($showGuids['tvdb'])) {
            $data = $this->tmdb->findByExternalId((string) $showGuids['tvdb'], 'tvdb_id');

            return isset($data['tv_results'][0]['id']) ? (int) $data['tv_results'][0]['id'] : null;
        }

        if (isset($showGuids['imdb'])) {
            $data = $this->tmdb->findByExternalId((string) $showGuids['imdb'], 'imdb_id');

            return isset($data['tv_results'][0]['id']) ? (int) $data['tv_results'][0]['id'] : null;
        }

        return null;
    }

    private function showTmdbIdFromEpisodeExternalId(string $externalId, string $externalSource): ?int
    {
        $data = $this->tmdb->findByExternalId($externalId, $externalSource);

        $showId = $data['tv_episode_results'][0]['show_id'] ?? null;

        return $showId !== null ? (int) $showId : null;
    }

    /**
     * The configured `plex.account_id` may be a numeric Plex account id or a username, so this
     * checks the event's account id AND its username (case-insensitively) against it.
     */
    private function isFromConfiguredAccount(string|int|null $accountId, ?string $accountUsername = null): bool
    {
        $configured = (string) $this->settings->get('plex.account_id', '');

        if (blank($configured)) {
            return true;
        }

        if ($accountId !== null && (string) $accountId === $configured) {
            return true;
        }

        return $accountUsername !== null && strcasecmp($accountUsername, $configured) === 0;
    }
}
