<?php

namespace App\Http\Resources\V1;

use App\Models\MediaList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaList
 */
class MediaListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_watchlist' => $this->is_watchlist,
            'items_count' => $this->items_count ?? $this->items()->count(),
            'preview_posters' => $this->relationLoaded('titles')
                ? $this->titles->map->posterUrl()->filter()->values()->all()
                : $this->titles()->orderBy('media_list_items.position')->limit(5)->get()->map->posterUrl()->filter()->values()->all(),
        ];
    }
}
