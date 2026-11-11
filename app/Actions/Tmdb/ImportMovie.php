<?php

namespace App\Actions\Tmdb;

use App\Concerns\NormalizesTmdbArrays;
use App\Enums\TitleType;
use App\Models\Title;
use App\Services\Tmdb\TmdbClient;
use Illuminate\Support\Facades\DB;

class ImportMovie
{
    use NormalizesTmdbArrays;

    public function __construct(
        private readonly TmdbClient $tmdb,
        private readonly SyncCredits $syncCredits,
        private readonly PickTrailer $pickTrailer,
        private readonly SyncWatchProviders $syncWatchProviders,
    ) {}

    public function handle(int $tmdbId): Title
    {
        $data = $this->tmdb->movie($tmdbId);
        $trailer = $this->pickTrailer->handle($data['videos']['results'] ?? []);

        // Retries the whole transaction on deadlock (SQLSTATE 40001/1213), which
        // concurrent title imports and other writers can trigger.
        return DB::transaction(function () use ($data, $tmdbId, $trailer): Title {
            $title = Title::updateOrCreate(
                ['type' => TitleType::Movie, 'tmdb_id' => $tmdbId],
                [
                    'imdb_id' => $data['external_ids']['imdb_id'] ?? $data['imdb_id'] ?? null,
                    'tvdb_id' => $data['external_ids']['tvdb_id'] ?? null,
                    'name' => $data['title'],
                    'original_name' => $data['original_title'] ?? null,
                    'original_language' => $data['original_language'] ?? null,
                    'tagline' => $data['tagline'] ?? null,
                    'overview' => $data['overview'] ?? null,
                    'status' => $data['status'] ?? null,
                    'certification' => $this->parseCertification($data),
                    'director' => $this->parseDirector($data),
                    'in_production' => false,
                    'release_date' => $this->nullableDate($data['release_date'] ?? null),
                    'last_air_date' => null,
                    'runtime' => $data['runtime'] ?? null,
                    'genres' => collect($this->arrayOfArrays($data['genres'] ?? []))->pluck('name')->all(),
                    'poster_path' => $data['poster_path'] ?? null,
                    'backdrop_path' => $data['backdrop_path'] ?? null,
                    'tmdb_synced_at' => now(),
                    'trailer_site' => $trailer['site'] ?? null,
                    'trailer_key' => $trailer['key'] ?? null,
                    'trailer_checked_at' => now(),
                ],
            );

            $this->syncCredits->handle($title, $data['credits'] ?? [], aggregate: false);

            $this->syncWatchProviders->handle($title, $data['watch/providers'] ?? []);

            return $title;
        }, 3);
    }

    private function parseDirector(array $data): ?string
    {
        $crew = $this->arrayOfArrays($data['credits']['crew'] ?? []);

        $names = collect($crew)
            ->filter(fn (array $member): bool => ($member['job'] ?? '') === 'Director')
            ->pluck('name')
            ->filter()
            ->values();

        return $names->isNotEmpty() ? $names->implode(', ') : null;
    }

    private function parseCertification(array $data): ?string
    {
        $region = $this->tmdb->region();
        $results = collect($this->arrayOfArrays($data['release_dates']['results'] ?? []));

        $forRegion = $results->firstWhere('iso_3166_1', $region)
            ?? $results->firstWhere('iso_3166_1', 'US');

        if (! $forRegion) {
            return null;
        }

        $releaseDates = $this->arrayOfArrays($forRegion['release_dates'] ?? []);

        $theatrical = collect($releaseDates)->firstWhere('type', 3);
        if ($theatrical && ($theatrical['certification'] ?? '') !== '') {
            return $theatrical['certification'];
        }

        return collect($releaseDates)
            ->first(fn (array $r): bool => ($r['certification'] ?? '') !== '')['certification'] ?? null;
    }

    private function nullableDate(?string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
