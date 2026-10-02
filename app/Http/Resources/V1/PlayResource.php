<?php

namespace App\Http\Resources\V1;

use App\Models\Play;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bare Play shape returned by the watch-action endpoints (`{"play": {...}}`). See
 * `PlayHistoryResource` for the enriched shape (with the playable embedded) used by `/history`.
 *
 * @mixin Play
 */
class PlayResource extends JsonResource
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
            'watched_at' => $this->watched_at?->toIso8601ZuluString(),
            'source' => $this->source->value,
        ];
    }
}
