<?php

namespace App\Actions\Trakt;

use App\Actions\Tmdb\EnsureSeasonEpisodes;
use App\Actions\Trakt\Concerns\EnsuresSeasonsLoadedForEpisodes;
use App\Actions\Trakt\Concerns\ResolvesTraktEntities;
use App\Actions\Trakt\DataTransferObjects\PlaysImportResult;
use App\Enums\PlaySource;
use Illuminate\Support\Carbon;

class ImportPlays
{
    use EnsuresSeasonsLoadedForEpisodes, ResolvesTraktEntities;

    public function __construct(private readonly EnsureSeasonEpisodes $ensureSeasonEpisodes) {}

    /**
     * @param  iterable<int, array<string, mixed>>  $entries  Raw history entries, e.g. `ExportReader::history()` or a single decoded history page.
     */
    public function handle(iterable $entries, bool $dryRun): PlaysImportResult
    {
        $imported = 0;
        $skipped = 0;

        $entries = $this->ensureNeededSeasonsLoaded($entries);

        foreach ($entries as $entry) {
            $playable = match ($entry['type'] ?? null) {
                'movie' => $this->resolveTitle($entry),
                'episode' => $this->resolveEpisode($entry),
                default => null,
            };

            if ($playable === null) {
                $skipped++;

                continue;
            }

            $imported++;

            if ($dryRun) {
                continue;
            }

            $playable->plays()->updateOrCreate(
                ['source' => PlaySource::Trakt, 'external_id' => (string) $entry['id']],
                ['watched_at' => Carbon::parse($entry['watched_at'])],
            );
        }

        return new PlaysImportResult($imported, $skipped);
    }
}
