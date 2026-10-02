<?php

namespace App\Models;

use App\Observers\RatingObserver;
use Database\Factories\RatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The legacy `thumb` column is kept in the database (not dropped) until the user re-runs the
 * Trakt ratings import to restore precise 1-10 scores, but app code no longer reads or writes it.
 *
 * @property int $id
 * @property string $rateable_type
 * @property int $rateable_id
 * @property int|null $score half-star units: 1 = ½★ … 10 = ★★★★★
 * @property string|null $review
 * @property bool $review_spoilers
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['rateable_type', 'rateable_id', 'score', 'review', 'review_spoilers', 'reviewed_at'])]
#[ObservedBy(RatingObserver::class)]
class Rating extends Model
{
    /** @use HasFactory<RatingFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_spoilers' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
