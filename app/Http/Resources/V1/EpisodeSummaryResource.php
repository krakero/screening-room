<?php

namespace App\Http\Resources\V1;

use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A minimal Episode shape for embedding inside other resources (history, plays), not the full
 * episode-detail payload `EpisodeController` returns. Expects `title` to be loaded.
 *
 * @mixin Episode
 */
class EpisodeSummaryResource extends JsonResource
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
        return [
            'id' => $this->id,
            'season_number' => $this->season_number,
            'episode_number' => $this->episode_number,
            'code' => sprintf('S%02dE%02d', $this->season_number, $this->episode_number),
            'name' => $this->name,
            'air_date' => $this->air_date?->format('Y-m-d'),
            'still_url' => $this->stillUrl('w300'),
            'has_aired' => $this->hasAired(),
            'title' => new TitleSummaryResource($this->whenLoaded('title')),
        ];
    }
}
