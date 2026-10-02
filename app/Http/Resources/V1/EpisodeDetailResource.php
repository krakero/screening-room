<?php

namespace App\Http\Resources\V1;

use App\Models\Episode;
use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The episode flyout's detail shape (`GET /episodes/{episode}`): richer than the embedded
 * `EpisodeResource`, with TMDB credits, play history, and sibling navigation.
 *
 * @mixin Episode
 */
class EpisodeDetailResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @param  Episode  $resource
     * @param  array{cast: array<int, array<string, mixed>>, crew: array<int, array<string, mixed>>, voteAverage: float|null}  $credits
     * @param  array{total: int, watched: int}  $seasonStats
     */
    public function __construct(
        $resource,
        private readonly array $credits,
        private readonly bool $creditsPending,
        private readonly ?string $plexUrl,
        private readonly bool $plexPending,
        private readonly ?int $previousEpisodeId,
        private readonly ?int $nextEpisodeId,
        private readonly array $seasonStats,
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
            'title' => [
                'id' => $this->title->id,
                'name' => $this->title->name,
                'poster_url' => $this->title->posterUrl(),
            ],
            'season_number' => $this->season_number,
            'episode_number' => $this->episode_number,
            'name' => $this->name,
            'overview' => $this->overview,
            'air_date' => $this->air_date?->format('Y-m-d'),
            'still_url_w780' => $this->stillUrl('w780'),
            'runtime' => $this->runtime,
            'has_aired' => $this->hasAired(),
            'vote_average' => $this->credits['voteAverage'],
            'cast' => collect($this->credits['cast'])->map(fn (array $member): array => [
                'name' => $member['name'] ?? null,
                'character' => $member['character'] ?? null,
                'profile_path_url' => $this->profileUrl($member['profile_path'] ?? null),
            ])->all(),
            'crew' => collect($this->credits['crew'])->map(fn (array $member): array => [
                'name' => $member['name'] ?? null,
                'job' => $member['job'] ?? null,
            ])->all(),
            'credits_pending' => $this->creditsPending,
            'plays' => $this->plays->sortByDesc('watched_at')->values()->map(fn (Play $play): array => [
                'id' => $play->id,
                'watched_at' => $play->watched_at?->toIso8601ZuluString(),
                'source' => $play->source->value,
            ])->all(),
            'plex_url' => $this->plexUrl,
            'plex_pending' => $this->plexPending,
            'previous_episode_id' => $this->previousEpisodeId,
            'next_episode_id' => $this->nextEpisodeId,
            'season_stats' => $this->seasonStats,
        ];
    }

    private function profileUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/w185{$path}";
    }
}
