<?php

namespace App\Models;

use Database\Factories\PlexLibraryEpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One episode under an indexed show (`plex_library_items`), keyed by the show's own
 * `plex_rating_key` rather than a foreign key, so `ResolvePlexAvailability` can match against it
 * using the show's already-resolved `PlexItem` without an extra join. Built by `plex:index`, which
 * fetches a show's full `allLeaves` list once and stores it here — the reason
 * `plex:refresh-availability` can resolve most stale episodes without ever calling Plex.
 *
 * @property int $id
 * @property string $show_rating_key
 * @property string|null $machine_identifier
 * @property int $season_number
 * @property int $episode_number
 * @property string $plex_rating_key
 * @property Carbon $indexed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['show_rating_key', 'machine_identifier', 'season_number', 'episode_number', 'plex_rating_key', 'indexed_at'])]
class PlexLibraryEpisode extends Model
{
    /** @use HasFactory<PlexLibraryEpisodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'indexed_at' => 'datetime',
        ];
    }
}
