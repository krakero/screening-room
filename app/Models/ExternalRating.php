<?php

namespace App\Models;

use App\Enums\RatingSource;
use Database\Factories\ExternalRatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $title_id
 * @property RatingSource $source
 * @property float $value
 * @property float $max
 * @property int|null $votes
 * @property string|null $url
 * @property Carbon $fetched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title_id', 'source', 'value', 'max', 'votes', 'url', 'fetched_at'])]
class ExternalRating extends Model
{
    /** @use HasFactory<ExternalRatingFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => RatingSource::class,
            'value' => 'float',
            'max' => 'float',
            'fetched_at' => 'datetime',
        ];
    }
}
