<?php

namespace App\Http\Resources\V1;

use App\Models\Follow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Follow
 */
class FollowResource extends JsonResource
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
            'state' => $this->state,
            'rewatching' => $this->isRewatching(),
            'rewatch_count' => $this->rewatch_count,
        ];
    }
}
