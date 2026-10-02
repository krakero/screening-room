<?php

namespace App\Models;

use Database\Factories\WatchProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tmdb_id
 * @property string $name
 * @property string|null $logo_path
 * @property int $display_priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['tmdb_id', 'name', 'logo_path', 'display_priority'])]
class WatchProvider extends Model
{
    /** @use HasFactory<WatchProviderFactory> */
    use HasFactory;

    /**
     * Add-on channels (e.g. "HBO Max Amazon Channel") are never stored or shown.
     */
    public static function isChannelVariant(string $name): bool
    {
        return str_ends_with(mb_strtolower(trim($name)), 'channel');
    }

    /**
     * @return BelongsToMany<Title, $this>
     */
    public function titles(): BelongsToMany
    {
        return $this->belongsToMany(Title::class, 'title_watch_provider')->withPivot('type', 'region')->withTimestamps();
    }

    public function logoUrl(): ?string
    {
        if ($this->logo_path === null) {
            return null;
        }

        return config('services.tmdb.image_base_url').'/w92'.$this->logo_path;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tmdb_id' => 'integer',
            'display_priority' => 'integer',
        ];
    }
}
