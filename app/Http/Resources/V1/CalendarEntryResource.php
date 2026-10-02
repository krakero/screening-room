<?php

namespace App\Http\Resources\V1;

use App\Services\CalendarEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CalendarEntry
 */
class CalendarEntryResource extends JsonResource
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
            'type' => $this->type->value,
            'date' => $this->date->toDateString(),
            'watched' => $this->watched,
            'title' => new TitleSummaryResource($this->title),
            'episode' => $this->episode !== null ? new EpisodeSummaryResource($this->episode) : null,
        ];
    }
}
