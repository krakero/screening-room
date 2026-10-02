<?php

namespace App\Models;

use Database\Factories\MediaListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_watchlist
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'description', 'is_watchlist'])]
class MediaList extends Model
{
    /** @use HasFactory<MediaListFactory> */
    use HasFactory;

    /**
     * @return HasMany<MediaListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MediaListItem::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Title, $this>
     */
    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class, 'media_list_items')
            ->withPivot('position')
            ->withTimestamps();
    }

    public static function watchlist(): self
    {
        return static::firstOrCreate(
            ['slug' => 'watchlist'],
            ['name' => 'Watchlist', 'is_watchlist' => true],
        );
    }

    /**
     * A URL-safe slug for `$name`, disambiguated with a numeric suffix if another list already uses it.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'list';
        $slug = $base;
        $suffix = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_watchlist' => 'boolean',
        ];
    }
}
