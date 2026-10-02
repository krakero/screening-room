<?php

namespace App\Http\Resources\V1;

use App\Models\Credit;
use App\Models\ExternalRating;
use App\Models\Network;
use App\Models\Play;
use App\Models\Season;
use App\Models\Title;
use App\Models\WatchProvider;
use App\Services\ShowProgressData;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Title
 */
class TitleResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @param  Title  $resource
     * @param  Collection<int, Credit>  $cast
     * @param  array<int, array{percent: float, complete: bool}>  $seasonProgress  keyed by season id
     */
    public function __construct(
        $resource,
        private readonly Collection $cast,
        private readonly ?Season $seasonTrailer,
        private readonly array $seasonProgress,
        private readonly ?string $plexPlayUrl,
        private readonly ?ShowProgressData $progress,
        private readonly bool $awaitingTrailer,
        private readonly bool $awaitingSeasonTrailer,
        private readonly bool $awaitingPlex,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'tmdb_id' => $this->tmdb_id,
            'name' => $this->name,
            'overview' => $this->overview,
            'tagline' => $this->tagline,
            'genres' => $this->genres ?? [],
            'runtime' => $this->runtime,
            'release_date' => $this->release_date?->format('Y-m-d'),
            'last_air_date' => $this->last_air_date?->format('Y-m-d'),
            'year_range' => $this->yearRange(),
            'poster_url' => $this->posterUrl(),
            'poster_url_w185' => $this->posterUrl('w185'),
            'backdrop_url' => $this->backdropUrl(),
            'backdrop_url_w780' => $this->backdropUrl('w780'),
            'show_status' => $this->when($this->isShow(), fn () => $this->showStatus()?->value),
            'trailer' => $this->hasTrailer() ? [
                'site' => $this->trailer_site,
                'key' => $this->trailer_key,
                'embed_url' => $this->trailerEmbedUrl(),
                'watch_url' => $this->trailerWatchUrl(),
            ] : null,
            'awaiting_trailer' => $this->awaitingTrailer,
            'season_trailer' => $this->seasonTrailer ? [
                'season_number' => $this->seasonTrailer->season_number,
                'site' => $this->seasonTrailer->trailer_site,
                'key' => $this->seasonTrailer->trailer_key,
                'embed_url' => $this->seasonTrailer->trailerEmbedUrl(),
                'watch_url' => $this->seasonTrailer->trailerWatchUrl(),
            ] : null,
            'awaiting_season_trailer' => $this->awaitingSeasonTrailer,
            'external_ratings' => $this->externalRatings->map(fn (ExternalRating $rating): array => [
                'source' => $rating->source->value,
                'value' => $rating->value,
                'max' => $rating->max,
                'votes' => $rating->votes,
                'url' => $rating->url,
                'formatted' => $rating->source->format($rating->value),
            ])->all(),
            'networks' => $this->when($this->isShow(), fn (): array => $this->networks
                ->map(fn (Network $network): array => [
                    'id' => $network->tmdb_id,
                    'name' => $network->name,
                    'logo_url' => $network->logoUrl(),
                ])->all()),
            'watch_providers' => $this->watch_providers_checked_at === null || ! app(TmdbClient::class)->showWatchProviders()
                ? []
                : $this->streamingProviders()->map(fn (WatchProvider $provider): array => [
                    'provider_id' => $provider->tmdb_id,
                    'name' => $provider->name,
                    'logo_url' => $provider->logoUrl(),
                    'type' => $provider->pivot->type->value,
                ])->values()->all(),
            'justwatch_attribution' => 'Streaming data by JustWatch (https://www.justwatch.com)',
            'plex_play_url' => $this->plexPlayUrl,
            'awaiting_plex' => $this->awaitingPlex,
            'request_status' => $this->libraryStatus ? [
                'state' => $this->libraryStatus->state->value,
                'seerr_status' => $this->libraryStatus->seerr_status?->value,
            ] : null,
            'follow' => $this->follow ? [
                'id' => $this->follow->id,
                'state' => $this->follow->state->value,
                'rewatching' => $this->follow->isRewatching(),
                'rewatch_count' => $this->follow->rewatch_count,
            ] : null,
            'on_watchlist' => $this->mediaLists->contains(fn ($list) => $list->is_watchlist),
            'rating' => $this->rating ? [
                'score' => $this->rating->score,
                'review' => $this->rating->review,
                'review_spoilers' => $this->rating->review_spoilers,
                'reviewed_at' => $this->rating->reviewed_at?->toIso8601ZuluString(),
            ] : null,
            'progress' => $this->when($this->isShow(), fn (): array => [
                'aired_count' => $this->progress->airedCount,
                'watched_count' => $this->progress->watchedCount,
                'percent' => $this->progress->percent,
                'is_complete' => $this->progress->isComplete,
                'next_episode_id' => $this->progress->nextEpisode?->id,
            ]),
            'seasons' => $this->when($this->isShow(), fn (): array => $this->seasons
                ->sortBy(fn (Season $season) => $season->season_number === 0 ? PHP_INT_MAX : $season->season_number)
                ->values()
                ->map(fn (Season $season): array => [
                    'id' => $season->id,
                    'season_number' => $season->season_number,
                    'name' => $season->name,
                    'air_date' => $season->air_date?->format('Y-m-d'),
                    'episode_count' => $season->episode_count,
                    'poster_url' => $season->posterUrl(),
                    'percent_watched' => $this->seasonProgress[$season->id]['percent'] ?? 0.0,
                    'complete' => $this->seasonProgress[$season->id]['complete'] ?? false,
                ])->all()),
            'cast' => $this->cast->map(fn (Credit $credit): array => [
                'id' => $credit->id,
                'person' => [
                    'id' => $credit->person->id,
                    'name' => $credit->person->name,
                    'profile_url' => $credit->person->profileUrl(),
                ],
                'character' => $credit->character,
            ])->all(),
            'movie_plays' => $this->when($this->isMovie(), fn (): array => $this->plays
                ->sortByDesc('watched_at')
                ->values()
                ->map(fn (Play $play): array => [
                    'id' => $play->id,
                    'watched_at' => $play->watched_at?->toIso8601ZuluString(),
                    'source' => $play->source->value,
                ])->all()),
        ];
    }
}
