<?php

namespace App\Models;

use App\Enums\TitleType;
use Database\Factories\PlexLibraryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One movie/show on the Plex server, indexed by `plex:index` from
 * `/library/sections/{key}/all?includeGuids=1`. The source of truth Title resolution reads from —
 * Plex's `guid` filter only matches an item's PRIMARY guid, never an external id, so per-guid
 * lookups can't work; this table is built by paging the whole library once and matching locally.
 *
 * @property int $id
 * @property string $plex_rating_key
 * @property string|null $machine_identifier
 * @property TitleType $type
 * @property int|null $tmdb_id
 * @property string|null $imdb_id
 * @property int|null $tvdb_id
 * @property string|null $section_key
 * @property Carbon $indexed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['plex_rating_key', 'machine_identifier', 'type', 'tmdb_id', 'imdb_id', 'tvdb_id', 'section_key', 'indexed_at'])]
class PlexLibraryItem extends Model
{
    /** @use HasFactory<PlexLibraryItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TitleType::class,
            'indexed_at' => 'datetime',
        ];
    }
}
