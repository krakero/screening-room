<?php

namespace App\Http\Resources\V1;

use App\Models\CollectionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CollectionItem
 */
class CollectionItemResource extends JsonResource
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
            'title_id' => $this->title_id,
            'title_name' => $this->title->name,
            'title_type' => $this->title->type->value,
            'title_year' => $this->title->year,
            'title_poster_url' => $this->title->posterUrl('w342'),
            'season_number' => $this->season?->season_number,
            'format' => $this->format->value,
            'format_label' => $this->format->label(),
            'edition' => $this->edition,
            'retailer' => $this->retailer,
            'barcode' => $this->barcode,
            'acquired_at' => $this->acquired_at?->format('Y-m-d'),
            'price' => $this->price,
            'currency' => $this->currency,
            'location' => $this->location,
            'loaned_to' => $this->loaned_to,
            'loaned_at' => $this->loaned_at?->format('Y-m-d'),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
