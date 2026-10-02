<?php

namespace App\Models;

use App\Enums\PlaySource;
use App\Observers\PlayObserver;
use Database\Factories\PlayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $playable_type
 * @property int $playable_id
 * @property Carbon|null $watched_at
 * @property PlaySource $source
 * @property string|null $external_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['playable_type', 'playable_id', 'watched_at', 'source', 'external_id'])]
#[ObservedBy(PlayObserver::class)]
class Play extends Model
{
    /** @use HasFactory<PlayFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function playable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'watched_at' => 'datetime',
            'source' => PlaySource::class,
        ];
    }
}
