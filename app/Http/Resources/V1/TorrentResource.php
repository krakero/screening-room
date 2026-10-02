<?php

namespace App\Http\Resources\V1;

use App\Services\Qbittorrent\Torrent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Torrent
 */
class TorrentResource extends JsonResource
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
            'hash' => $this->hash,
            'name' => $this->name,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'progress' => $this->progress,
            'percent' => $this->percent(),
            'size' => $this->size,
            'downloaded' => $this->downloaded,
            'dlspeed' => $this->dlspeed,
            'upspeed' => $this->upspeed,
            'eta' => $this->eta,
            'category' => $this->category,
            'added_on' => $this->addedOn->toIso8601ZuluString(),
            'num_seeds' => $this->numSeeds,
            'num_leechs' => $this->numLeechs,
            'ratio' => $this->ratio,
        ];
    }
}
