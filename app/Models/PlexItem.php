<?php

namespace App\Models;

use Database\Factories\PlexItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Caches where a Title or Episode lives on the user's Plex server, so a "Watch on Plex" link
 * can be built without an API round-trip on every page load.
 *
 * @property int $id
 * @property string $plexable_type
 * @property int $plexable_id
 * @property string|null $machine_identifier
 * @property string|null $rating_key
 * @property Carbon $checked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['plexable_type', 'plexable_id', 'machine_identifier', 'rating_key', 'checked_at'])]
class PlexItem extends Model
{
    /** @use HasFactory<PlexItemFactory> */
    use HasFactory;

    /**
     * @return MorphTo<Model, $this>
     */
    public function plexable(): MorphTo
    {
        return $this->morphTo();
    }

    public function found(): bool
    {
        return filled($this->rating_key) && filled($this->machine_identifier);
    }

    /**
     * The Plex web app deep link, or null when this item wasn't found on the server.
     */
    public function playUrl(): ?string
    {
        if (! $this->found()) {
            return null;
        }

        return sprintf(
            'https://app.plex.tv/desktop/#!/server/%s/details?key=%s',
            $this->machine_identifier,
            rawurlencode("/library/metadata/{$this->rating_key}"),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }
}
