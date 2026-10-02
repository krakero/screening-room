<?php

namespace App\Jobs;

use App\Actions\Tmdb\Concerns\SyncsSeasonEpisodes;
use App\Models\Season;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Imports one season's episodes from TMDB. Dispatched (one per season) after
 * `ImportShow` stores the show and its season summaries without episodes.
 */
class ImportSeasonEpisodes implements ShouldBeUnique, ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SyncsSeasonEpisodes;

    public int $timeout = 55;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $titleId,
        public readonly int $seasonNumber,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->titleId}:{$this->seasonNumber}";
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping((string) $this->uniqueId())];
    }

    public function handle(TmdbClient $tmdb): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $title = Title::findOrFail($this->titleId);
        $season = Season::where('title_id', $title->id)->where('season_number', $this->seasonNumber)->firstOrFail();

        $seasonsData = $tmdb->seasons($title->tmdb_id, [$this->seasonNumber]);
        $seasonData = $seasonsData[$this->seasonNumber] ?? null;

        if ($seasonData === null) {
            return;
        }

        // Retries on deadlock (SQLSTATE 40001/1213); these batches of per-season
        // writes can run alongside other title/season/episode writers.
        DB::transaction(function () use ($title, $season, $seasonData): void {
            $this->syncSeasonEpisodes($title, $season, $seasonData);
        }, 3);
    }
}
