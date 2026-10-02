<?php

namespace App\Models;

use App\Observers\EpisodeObserver;
use App\Support\DisplayTimezone;
use Database\Factories\EpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $title_id
 * @property int $season_id
 * @property int $tmdb_id
 * @property int|null $tvdb_id
 * @property int $season_number
 * @property int $episode_number
 * @property string|null $name
 * @property string|null $overview
 * @property Carbon|null $air_date
 * @property int|null $runtime
 * @property string|null $still_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title_id', 'season_id', 'tmdb_id', 'tvdb_id', 'season_number', 'episode_number',
    'name', 'overview', 'air_date', 'runtime', 'still_path',
])]
#[ObservedBy(EpisodeObserver::class)]
class Episode extends Model
{
    /** @use HasFactory<EpisodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return MorphMany<Play, $this>
     */
    public function plays(): MorphMany
    {
        return $this->morphMany(Play::class, 'playable');
    }

    /**
     * @return MorphMany<Credit, $this>
     */
    public function credits(): MorphMany
    {
        return $this->morphMany(Credit::class, 'creditable');
    }

    /**
     * @return MorphOne<PlexItem, $this>
     */
    public function plexItem(): MorphOne
    {
        return $this->morphOne(PlexItem::class, 'plexable');
    }

    public function stillUrl(string $size = 'w300'): ?string
    {
        if ($this->still_path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/{$size}{$this->still_path}";
    }

    public function isSpecial(): bool
    {
        return $this->season_number === 0;
    }

    public function hasAired(): bool
    {
        return $this->air_date !== null && $this->air_date->toDateString() <= DisplayTimezone::today()->toDateString();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'air_date' => 'date',
        ];
    }
}
