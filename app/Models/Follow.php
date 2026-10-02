<?php

namespace App\Models;

use App\Enums\FollowState;
use App\Observers\FollowObserver;
use Carbon\CarbonInterface;
use Database\Factories\FollowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $title_id
 * @property FollowState $state
 * @property Carbon $state_changed_at
 * @property Carbon|null $last_played_at
 * @property Carbon|null $rewatch_started_at
 * @property int $rewatch_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title_id', 'state', 'state_changed_at', 'last_played_at', 'rewatch_started_at', 'rewatch_count'])]
#[ObservedBy(FollowObserver::class)]
class Follow extends Model
{
    /** @use HasFactory<FollowFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    public function isRewatching(): bool
    {
        return $this->rewatch_started_at !== null;
    }

    /**
     * The point in time watched-progress is measured from while rewatching, or null when not.
     */
    public function progressSince(): ?CarbonInterface
    {
        return $this->rewatch_started_at;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => FollowState::class,
            'state_changed_at' => 'datetime',
            'last_played_at' => 'datetime',
            'rewatch_started_at' => 'datetime',
            'rewatch_count' => 'integer',
        ];
    }
}
