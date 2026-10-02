<?php

namespace App\Http\Resources\V1;

use App\Models\MediaListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaListItem
 */
class MediaListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'item_id' => $this->id,
            'position' => $this->position,
            'title' => [
                ...(new TitleSummaryResource($this->title))->resolve($request),
                'release_date' => $this->title->release_date?->toIso8601ZuluString(),
                'status' => $this->title->showStatus()?->value,
            ],
        ];
    }
}
