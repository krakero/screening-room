<?php

namespace App\Http\Resources\V1;

use App\Models\Rating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Rating
 */
class RatingResource extends JsonResource
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
            'score' => $this->score,
            'review' => $this->review,
            'review_spoilers' => $this->review_spoilers,
            'reviewed_at' => $this->reviewed_at?->toIso8601ZuluString(),
        ];
    }
}
