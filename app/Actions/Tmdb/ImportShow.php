<?php

namespace App\Actions\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Enums\TitleType;
use App\Jobs\ImportSeasonEpisodes;
use App\Models\Season;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportShow
{
    use NormalizesTmdbArrays;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly SyncCredits $syncCredits,
        private readonly PickTrailer $pickTrailer,
        private readonly SyncWatchProviders $syncWatchProviders,
        private readonly SyncNetworks $syncNetworks,
    ) {}

    /**
     * Imports the show and its season summaries WITHOUT fetching any season's episodes,
     * then queues a batch of `ImportSeasonEpisodes` jobs (one per season) to load them.
     */
    public function handle(int $tmdbId): Title
    {
        $data = $this->tmdb->show($tmdbId);

        $seasonSummaries = $this->arrayOfArrays($data['seasons'] ?? []);
        $trailer = $this->pickTrailer->handle($data['videos']['results'] ?? []);

        // Retries the whole transaction on deadlock (SQLSTATE 40001/1213), which
        // concurrent title imports and other writers can trigger.
        $title = DB::transaction(function () use ($data, $tmdbId, $seasonSummaries, $trailer): Title {
            $title = Title::updateOrCreate(
                ['type' => TitleType::Show, 'tmdb_id' => $tmdbId],
                [
                    'imdb_id' => $data['external_ids']['imdb_id'] ?? null,
                    'tvdb_id' => $data['external_ids']['tvdb_id'] ?? null,
                    'name' => $data['name'],
                    'original_name' => $data['original_name'] ?? null,
                    'original_language' => $data['original_language'] ?? null,
                    'tagline' => $data['tagline'] ?? null,
                    'overview' => $data['overview'] ?? null,
                    'status' => $data['status'] ?? null,
                    'in_production' => (bool) ($data['in_production'] ?? false),
                    'release_date' => $this->nullableDate($data['first_air_date'] ?? null),
                    'last_air_date' => $this->nullableDate($data['last_air_date'] ?? null),
                    'runtime' => $data['episode_run_time'][0] ?? null,
                    'genres' => collect($this->arrayOfArrays($data['genres'] ?? []))->pluck('name')->all(),
                    'poster_path' => $data['poster_path'] ?? null,
                    'backdrop_path' => $data['backdrop_path'] ?? null,
                    'tmdb_synced_at' => now(),
                    'trailer_site' => $trailer['site'] ?? null,
                    'trailer_key' => $trailer['key'] ?? null,
                    'trailer_checked_at' => now(),
                ],
            );

            $this->syncCredits->handle($title, $data['aggregate_credits'] ?? [], aggregate: true);

            $this->syncSeasons($title, $seasonSummaries);

            $this->syncNetworks->handle($title, $data['networks'] ?? []);

            $this->syncWatchProviders->handle($title, $data['watch/providers'] ?? []);

            return $title;
        }, 3);

        $this->queueEpisodeImports($title, collect($seasonSummaries)->pluck('season_number')->all());

        return $title;
    }

    /**
     * Upsert seasons from the show payload's `seasons` summary array — no episode calls here.
     *
     * @param  array<int, array<string, mixed>>  $seasonSummaries
     */
    private function syncSeasons(Title $title, array $seasonSummaries): void
    {
        if ($seasonSummaries === []) {
            return;
        }

        foreach ($seasonSummaries as $seasonSummary) {
            Season::updateOrCreate(
                ['title_id' => $title->id, 'season_number' => $seasonSummary['season_number']],
                [
                    'tmdb_id' => $seasonSummary['id'] ?? null,
                    'name' => $seasonSummary['name'] ?? null,
                    'overview' => $seasonSummary['overview'] ?? null,
                    'air_date' => $this->nullableDate($seasonSummary['air_date'] ?? null),
                    'poster_path' => $seasonSummary['poster_path'] ?? null,
                    'episode_count' => $seasonSummary['episode_count'] ?? null,
                ],
            );
        }

        $this->removeObsoleteSeasons($title, collect($seasonSummaries)->pluck('season_number')->all());
    }

    /**
     * Queues one `ImportSeasonEpisodes` job per season, after the title/season transaction commits.
     *
     * @param  array<int, int>  $seasonNumbers
     */
    private function queueEpisodeImports(Title $title, array $seasonNumbers): void
    {
        if ($seasonNumbers === []) {
            return;
        }

        $jobs = collect($seasonNumbers)
            ->map(fn (int $seasonNumber): ImportSeasonEpisodes => new ImportSeasonEpisodes($title->id, $seasonNumber))
            ->all();

        Bus::batch($jobs)
            ->name("import-season-episodes:{$title->id}")
            ->allowFailures()
            ->catch(function (Batch $batch, Throwable $e): void {
                // Individual season failures don't block the others; nothing further to do here.
            })
            ->dispatch();
    }

    /**
     * @param  array<int, int>  $currentSeasonNumbers
     */
    private function removeObsoleteSeasons(Title $title, array $currentSeasonNumbers): void
    {
        $obsoleteSeasons = $title->seasons()
            ->whereNotIn('season_number', $currentSeasonNumbers)
            ->get();

        foreach ($obsoleteSeasons as $season) {
            if ($season->episodes()->whereHas('plays')->exists()) {
                continue;
            }

            $season->episodes()->delete();
            $season->delete();
        }
    }

    private function nullableDate(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
