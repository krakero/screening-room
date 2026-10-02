<?php

namespace App\Http\Resources\V1;

use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The episode shape embedded in season/up-next/calendar listings. Endpoints that need extra,
 * context-specific fields (watched state, Plex availability, up-next badge, …) pass them via
 * `$extra`, merged on top of the base shape.
 *
 * @mixin Episode
 */
class EpisodeResource extends JsonResource
{
    /**
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @param  Episode  $resource
     * @param  array<string, mixed>  $extra
     */
    public function __construct($resource, private readonly array $extra = [])
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge([
            'id' => $this->id,
            'season_number' => $this->season_number,
            'episode_number' => $this->episode_number,
            'name' => $this->name,
            'air_date' => $this->air_date?->format('Y-m-d'),
            'still_url' => $this->stillUrl(),
            'runtime' => $this->runtime,
            'is_special' => $this->isSpecial(),
            'has_aired' => $this->hasAired(),
        ], $this->extra);
    }
}
