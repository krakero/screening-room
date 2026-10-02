<?php

namespace App\Http\Resources\V1;

use App\Models\Episode;
use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `/history` item shape: a Play plus its embedded playable (an episode with its title, or a
 * movie title directly). Expects `playable` (and, for an episode, `playable.title`) loaded.
 *
 * @mixin Play
 */
class PlayHistoryResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isEpisode = $this->playable instanceof Episode;

        return [
            'id' => $this->id,
            'watched_at' => $this->watched_at?->toIso8601ZuluString(),
            'source' => $this->source->value,
            'playable_type' => $isEpisode ? 'episode' : 'movie',
            ...($isEpisode
                ? ['episode' => new EpisodeSummaryResource($this->playable)]
                : ['title' => new TitleSummaryResource($this->playable)]),
        ];
    }
}
