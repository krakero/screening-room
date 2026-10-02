<?php

namespace App\Models;

use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $title_id
 * @property int|null $tmdb_id
 * @property int $season_number
 * @property string|null $name
 * @property string|null $overview
 * @property Carbon|null $air_date
 * @property string|null $poster_path
 * @property int|null $episode_count
 * @property Carbon|null $episodes_synced_at
 * @property string|null $trailer_site
 * @property string|null $trailer_key
 * @property Carbon|null $trailer_checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'title_id', 'tmdb_id', 'season_number', 'name', 'overview', 'air_date', 'poster_path',
    'episode_count', 'episodes_synced_at', 'trailer_site', 'trailer_key', 'trailer_checked_at',
])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Title, $this>
     */
    public function title(): BelongsTo
    {
        return $this->belongsTo(Title::class);
    }

    /**
     * @return HasMany<Episode, $this>
     */
    public function episodes(): HasMany
    {
        return $this->hasMany(Episode::class)->orderBy('episode_number');
    }

    /**
     * @return HasMany<CollectionItem, $this>
     */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    public function posterUrl(string $size = 'w342'): ?string
    {
        if ($this->poster_path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url')."/{$size}{$this->poster_path}";
    }

    /**
     * Whether this season's episodes have ever been imported from TMDB.
     */
    public function episodesLoaded(): bool
    {
        return $this->episodes_synced_at !== null;
    }

    public function hasTrailer(): bool
    {
        return filled($this->trailer_site) && filled($this->trailer_key);
    }

    public function trailerEmbedUrl(): ?string
    {
        if (! $this->hasTrailer()) {
            return null;
        }

        return match ($this->trailer_site) {
            'YouTube' => "https://www.youtube-nocookie.com/embed/{$this->trailer_key}?autoplay=1",
            'Vimeo' => "https://player.vimeo.com/video/{$this->trailer_key}?autoplay=1",
            default => null,
        };
    }

    public function trailerWatchUrl(): ?string
    {
        if (! $this->hasTrailer()) {
            return null;
        }

        return match ($this->trailer_site) {
            'YouTube' => "https://www.youtube.com/watch?v={$this->trailer_key}",
            'Vimeo' => "https://vimeo.com/{$this->trailer_key}",
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'air_date' => 'date',
            'episodes_synced_at' => 'datetime',
            'trailer_checked_at' => 'datetime',
        ];
    }
}
