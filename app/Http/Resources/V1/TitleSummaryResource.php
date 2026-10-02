<?php

namespace App\Http\Resources\V1;

use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A minimal Title shape for embedding inside other resources (history, plays), not the full
 * title-detail payload `TitleController` returns.
 *
 * @mixin Title
 */
class TitleSummaryResource extends JsonResource
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
            'type' => $this->type->value,
            'name' => $this->name,
            'poster_url' => $this->posterUrl('w342'),
            'backdrop_url' => $this->backdropUrl('w1280'),
        ];
    }
}
