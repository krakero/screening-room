<?php

namespace App\Http\Resources\V1;

use App\Models\Episode;
use App\Models\Season;
use App\Support\WatchedSince;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin Season
 */
class SeasonResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @param  Season  $resource
     * @param  Collection<int, Episode>  $episodes
     * @param  array<int, string>  $plexPlayUrls  keyed by episode id
     */
    public function __construct(
        $resource,
        private readonly Collection $episodes,
        private readonly ?int $previousSeasonNumber,
        private readonly ?int $nextSeasonNumber,
        private readonly int $airedUnwatchedCount,
        private readonly ?Episode $nextEpisode,
        private readonly array $plexPlayUrls,
        private readonly bool $awaitingTrailer,
        private readonly bool $awaitingPlex,
        private readonly ?CarbonInterface $since = null,
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
            'title_id' => $this->title_id,
            'season_number' => $this->season_number,
            'name' => $this->name,
            'overview' => $this->overview,
            'air_date' => $this->air_date?->format('Y-m-d'),
            'poster_url' => $this->posterUrl(),
            'episode_count' => $this->episode_count,
            'trailer' => $this->hasTrailer() ? [
                'site' => $this->trailer_site,
                'key' => $this->trailer_key,
                'embed_url' => $this->trailerEmbedUrl(),
                'watch_url' => $this->trailerWatchUrl(),
            ] : null,
            'awaiting_trailer' => $this->awaitingTrailer,
            'awaiting_plex' => $this->awaitingPlex,
            'previous_season_number' => $this->previousSeasonNumber,
            'next_season_number' => $this->nextSeasonNumber,
            'aired_unwatched_count' => $this->airedUnwatchedCount,
            'next_episode_id' => $this->nextEpisode?->id,
            'episodes' => $this->episodes->map(function (Episode $episode): EpisodeResource {
                $watched = WatchedSince::watched($episode->plays, $this->since);

                return new EpisodeResource($episode, [
                    'watched' => $watched,
                    'has_manual_play' => $episode->plays->contains(fn ($play) => $play->source->value === 'manual'),
                    'is_up_next' => $this->nextEpisode?->is($episode) ?? false,
                    'plex_available' => array_key_exists($episode->id, $this->plexPlayUrls),
                    'plex_url' => $this->plexPlayUrls[$episode->id] ?? null,
                ]);
            })->all(),
        ];
    }
}
